<?php

namespace Tests\Feature\Azure;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Container Apps sizing and wiring rules that Bicep cannot check.
 *
 * `bicep build` validates property names and types against the Azure resource schema, but `cpu` is
 * just a number there and `memory` is just a string. Azure itself enforces a much narrower contract
 * at deployment time, and rejects the whole deployment when it is violated. Every rule below is one
 * that would otherwise surface as a failed `--apply` against a real subscription, after the platform
 * resources had already been created.
 *
 * These are static checks against infra/. They do not need an Azure subscription — which is the
 * point: they are what can be verified before the tenant exists.
 */
class ContainerAppsSizingContractTest extends TestCase
{
    /**
     * The CPU/memory combinations Azure Container Apps accepts. Memory must be exactly cpu × 2 GiB,
     * in 0.25 vCPU steps.
     *
     * @var array<string, string>
     */
    private const ALLOWED_CPU_MEMORY = [
        '0.25' => '0.5Gi',
        '0.5' => '1Gi',
        '0.75' => '1.5Gi',
        '1.0' => '2Gi',
        '1.25' => '2.5Gi',
        '1.5' => '3Gi',
        '1.75' => '3.5Gi',
        '2.0' => '4Gi',
        '2.25' => '4.5Gi',
        '2.5' => '5Gi',
        '2.75' => '5.5Gi',
        '3.0' => '6Gi',
        '3.25' => '6.5Gi',
        '3.5' => '7Gi',
        '3.75' => '7.5Gi',
        '4.0' => '8Gi',
    ];

    /** Consumption workload profile ceiling per replica. */
    private const MAX_CPU_PER_REPLICA = 4.0;

    #[DataProvider('environments')]
    public function test_every_worker_uses_a_cpu_memory_pair_azure_accepts(string $environment): void
    {
        foreach ($this->workers($environment) as $worker) {
            $this->assertValidPair(
                $worker['cpu'],
                $worker['memory'],
                sprintf('worker [%s] in %s.bicepparam', $worker['name'], $environment),
            );
        }
    }

    #[DataProvider('environments')]
    public function test_web_and_scheduler_use_cpu_memory_pairs_azure_accepts(string $environment): void
    {
        $params = $this->scalarParams($environment);

        $this->assertValidPair($params['webCpu'], $params['webMemory'], sprintf('web in %s.bicepparam', $environment));
        $this->assertValidPair(
            $params['schedulerCpu'],
            $params['schedulerMemory'],
            sprintf('scheduler in %s.bicepparam', $environment),
        );
    }

    #[DataProvider('environments')]
    public function test_no_replica_exceeds_the_consumption_profile_ceiling(string $environment): void
    {
        $params = $this->scalarParams($environment);

        foreach ([
            'web' => $params['webCpu'],
            'scheduler' => $params['schedulerCpu'],
        ] as $name => $cpu) {
            $this->assertLessThanOrEqual(
                self::MAX_CPU_PER_REPLICA,
                (float) $cpu,
                sprintf('%s in %s exceeds the %s vCPU Consumption ceiling.', $name, $environment, self::MAX_CPU_PER_REPLICA),
            );
        }

        foreach ($this->workers($environment) as $worker) {
            $this->assertLessThanOrEqual(
                self::MAX_CPU_PER_REPLICA,
                (float) $worker['cpu'],
                sprintf('worker [%s] in %s exceeds the Consumption ceiling.', $worker['name'], $environment),
            );
        }
    }

    /**
     * Container App names are capped at 32 characters. The prefix is procynia-stg / procynia-prd, so
     * a long worker name is a deployment failure, not a cosmetic problem.
     */
    #[DataProvider('environments')]
    public function test_every_container_app_name_fits_the_azure_limit(string $environment): void
    {
        $prefix = $environment === 'production' ? 'procynia-prd' : 'procynia-stg';

        $names = ['web', 'scheduler'];

        foreach ($this->workers($environment) as $worker) {
            $names[] = $worker['name'];
        }

        foreach ($names as $name) {
            $full = $prefix.'-'.$name;

            $this->assertLessThanOrEqual(
                32,
                strlen($full),
                sprintf('Container App name [%s] is %d characters; Azure allows 32.', $full, strlen($full)),
            );
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
                $full,
                sprintf('Container App name [%s] must be lowercase alphanumeric with dashes.', $full),
            );
        }
    }

    /**
     * The scheduler must be pinned to one replica, and no worker may be able to scale to zero — a
     * 35 minute ai-requirements job cannot survive being evicted.
     */
    #[DataProvider('environments')]
    public function test_replica_counts_match_the_workload_contract(string $environment): void
    {
        foreach ($this->workers($environment) as $worker) {
            $this->assertGreaterThanOrEqual(
                1,
                $worker['replicas'],
                sprintf('worker [%s] in %s must keep at least one replica.', $worker['name'], $environment),
            );
        }

        $module = file_get_contents(base_path('infra/modules/container-apps.bicep'));
        $schedulerSection = substr($module, (int) strpos($module, "resource scheduler 'Microsoft.App/containerApps"));

        $this->assertMatchesRegularExpression(
            '/scale:\s*\{\s*minReplicas:\s*1\s*maxReplicas:\s*1\s*\}/',
            $schedulerSection,
            'The scheduler must be pinned to exactly one replica.',
        );

        $params = $this->scalarParams($environment);
        $this->assertLessThanOrEqual(
            (int) $params['webMaxReplicas'],
            (int) $params['webMinReplicas'],
            sprintf('web min replicas must not exceed max replicas in %s.', $environment),
        );
        $this->assertGreaterThanOrEqual(
            1,
            (int) $params['webMinReplicas'],
            sprintf('web must keep at least one replica in %s.', $environment),
        );
    }

    /**
     * Azure caps terminationGracePeriodSeconds at 600. A larger value is rejected outright.
     */
    #[DataProvider('environments')]
    public function test_termination_grace_periods_are_within_the_azure_maximum(string $environment): void
    {
        foreach ($this->workers($environment) as $worker) {
            $this->assertLessThanOrEqual(
                600,
                $worker['terminationGracePeriodSeconds'],
                sprintf(
                    'worker [%s] in %s sets terminationGracePeriodSeconds=%d; Azure allows at most 600.',
                    $worker['name'],
                    $environment,
                    $worker['terminationGracePeriodSeconds'],
                ),
            );
        }
    }

    // -----------------------------------------------------------------------
    // Wiring between the IaC and the production images
    // -----------------------------------------------------------------------

    /**
     * The ingress target port and the port the web image actually listens on have to be the same
     * number, held in two different files. If they drift, the deployment succeeds and the health
     * probe fails.
     */
    public function test_the_ingress_port_matches_the_port_the_web_image_listens_on(): void
    {
        $nginx = file_get_contents(base_path('docker/production/nginx.conf'));

        $this->assertSame(
            1,
            preg_match('/^\s*listen\s+(\d+);/m', $nginx, $nginxMatch),
            'The production nginx config must declare a listen port.',
        );

        foreach (['staging', 'production'] as $environment) {
            $params = $this->scalarParams($environment);

            $this->assertSame(
                $nginxMatch[1],
                (string) $params['webTargetPort'],
                sprintf(
                    'nginx listens on %s but %s.bicepparam sets webTargetPort=%s. The Container Apps '
                    .'health probe would never get a response.',
                    $nginxMatch[1],
                    $environment,
                    $params['webTargetPort'],
                ),
            );
        }

        $this->assertStringContainsString(
            'EXPOSE 8080',
            file_get_contents(base_path('docker/production/Dockerfile')),
            'The web image must document the port it serves.',
        );
    }

    /**
     * The Azure Files mount path, the Laravel storage layout and the production image must agree.
     * A mismatch means web writes a document a worker cannot find — silently.
     */
    public function test_the_storage_mount_path_is_consistent_everywhere(): void
    {
        $expected = '/var/www/html/storage/app';

        foreach (['staging', 'production'] as $environment) {
            $this->assertStringContainsString(
                "param storageMountPath = '{$expected}'",
                file_get_contents(base_path("infra/environments/{$environment}.bicepparam")),
                sprintf('%s.bicepparam must mount Azure Files at %s.', $environment, $expected),
            );
        }

        $this->assertStringContainsString(
            "param storageMountPath string = '{$expected}'",
            file_get_contents(base_path('infra/main.bicep')),
            'The Azure template default mount path must match the Laravel storage layout.',
        );

        // The image creates the tree the mount lands on.
        $this->assertStringContainsString(
            '/var/www/html/storage/app/private',
            file_get_contents(base_path('docker/production/Dockerfile')),
            'The production image must create the storage tree the mount point sits on.',
        );

        // And the entrypoint recreates it, because a freshly mounted Azure Files share is empty.
        $this->assertStringContainsString(
            '${APP_ROOT}/storage/app/private',
            file_get_contents(base_path('docker/production/entrypoint.sh')),
            'The entrypoint must recreate the storage subdirectories on a freshly mounted share.',
        );
    }

    /**
     * Image pull and secret resolution must both go through the user-assigned managed identity.
     * A registry username/password or an access-policy Key Vault would both work, and both would be
     * a step backwards.
     */
    public function test_image_pull_and_secrets_use_the_managed_identity(): void
    {
        $module = file_get_contents(base_path('infra/modules/container-apps.bicep'));

        // The registry block is declared once as a variable and referenced by all three apps.
        $this->assertMatchesRegularExpression(
            '/var registryConfiguration = \[\s*\{\s*server:\s*registryLoginServer\s*identity:\s*workloadIdentityId\s*\}\s*\]/',
            $module,
            'ACR pull must use the workload managed identity, not a stored credential.',
        );
        $this->assertSame(
            3,
            substr_count($module, 'registries: registryConfiguration'),
            'All three Container Apps (web, workers, scheduler) must pull through the same identity.',
        );
        $this->assertStringNotContainsString(
            'registryPassword',
            $module,
            'No registry password may appear in the Container Apps definition.',
        );
        $this->assertStringContainsString(
            'keyVaultUrl:',
            $module,
            'Container App secrets must be Key Vault references.',
        );

        $registry = file_get_contents(base_path('infra/modules/registry.bicep'));
        $this->assertStringContainsString(
            'adminUserEnabled: false',
            $registry,
            'The registry admin user must stay disabled.',
        );

        $keyVault = file_get_contents(base_path('infra/modules/key-vault.bicep'));
        $this->assertStringContainsString(
            'enableRbacAuthorization: true',
            $keyVault,
            'Key Vault must use RBAC, not access policies.',
        );
    }

    /**
     * Health probes must target the endpoint the application actually serves.
     */
    public function test_health_probes_target_the_application_health_endpoint(): void
    {
        $module = file_get_contents(base_path('infra/modules/container-apps.bicep'));

        foreach (['Startup', 'Readiness', 'Liveness'] as $type) {
            $this->assertStringContainsString(
                "type: '{$type}'",
                $module,
                sprintf('The web app must declare a %s probe.', $type),
            );
        }

        $this->assertSame(
            3,
            substr_count($module, "path: '/up'"),
            'All three probes must target /up, the endpoint bootstrap/app.php registers.',
        );

        $this->assertStringContainsString(
            "health: '/up'",
            file_get_contents(base_path('bootstrap/app.php')),
            'The application must still serve /up.',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function environments(): array
    {
        return ['staging' => ['staging'], 'production' => ['production']];
    }

    // -----------------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------------

    private function assertValidPair(string $cpu, string $memory, string $context): void
    {
        // Normalise "1" / "1.0" to the canonical key.
        $normalisedCpu = rtrim(rtrim(number_format((float) $cpu, 2, '.', ''), '0'), '.');
        $normalisedCpu = str_contains($normalisedCpu, '.') ? $normalisedCpu : $normalisedCpu.'.0';
        $lookup = self::ALLOWED_CPU_MEMORY[$normalisedCpu] ?? self::ALLOWED_CPU_MEMORY[rtrim($normalisedCpu, '.0')] ?? null;

        if ($lookup === null && isset(self::ALLOWED_CPU_MEMORY[$cpu])) {
            $lookup = self::ALLOWED_CPU_MEMORY[$cpu];
        }

        $this->assertNotNull(
            $lookup,
            sprintf(
                '%s uses cpu=%s, which is not one of the Azure Container Apps steps (%s).',
                $context,
                $cpu,
                implode(', ', array_keys(self::ALLOWED_CPU_MEMORY)),
            ),
        );

        $this->assertSame(
            $lookup,
            $memory,
            sprintf(
                '%s pairs cpu=%s with memory=%s. Azure requires exactly %s — the deployment would be '
                .'rejected.',
                $context,
                $cpu,
                $memory,
                $lookup,
            ),
        );
    }

    /** @return list<array{name: string, cpu: string, memory: string, replicas: int, terminationGracePeriodSeconds: int}> */
    private function workers(string $environment): array
    {
        $source = file_get_contents(base_path("infra/environments/{$environment}.bicepparam"));
        $body = substr($source, (int) strpos($source, 'param workers = ['));

        preg_match_all('/\{(.*?)\}/s', $body, $entries);

        $workers = [];

        foreach ($entries[1] as $entry) {
            $worker = [];

            foreach (['name', 'cpu', 'memory'] as $key) {
                if (preg_match('/'.$key.":\s*'([^']+)'/", $entry, $m) === 1) {
                    $worker[$key] = $m[1];
                }
            }

            foreach (['replicas', 'terminationGracePeriodSeconds'] as $key) {
                if (preg_match('/'.$key.':\s*(\d+)/', $entry, $m) === 1) {
                    $worker[$key] = (int) $m[1];
                }
            }

            if (isset($worker['name'], $worker['cpu'], $worker['memory'])) {
                $workers[] = $worker;
            }
        }

        $this->assertNotEmpty($workers, "Parsed no workers from {$environment}.bicepparam.");

        return $workers;
    }

    /** @return array<string, string> */
    private function scalarParams(string $environment): array
    {
        $source = file_get_contents(base_path("infra/environments/{$environment}.bicepparam"));
        $params = [];

        preg_match_all("/^param\s+([A-Za-z0-9_]+)\s*=\s*'?([^'\n]+?)'?\s*$/m", $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $params[$match[1]] = trim($match[2]);
        }

        return $params;
    }
}

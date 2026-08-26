<?php

namespace Tests\Feature\Azure;

use App\Jobs\Ai\Requirements\FinalizeRequirementExtractionRun;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionChunk;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionRun;
use App\Jobs\Ai\Wiki\FinalizeEnterpriseWikiIngest;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiSection;
use App\Jobs\Doffin\PrepareDoffinSupplierHarvestRun;
use App\Jobs\Doffin\PrepareSupplierLookupRun;
use App\Jobs\Doffin\ProcessDoffinSupplierHarvestNotice;
use App\Jobs\Doffin\ProcessSupplierLookupNotice;
use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiClaimVerification;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiMaintainerDecisionBatches;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiMaintainerDecisionBatch;
use App\Jobs\EnterpriseWiki\RunPostIngestQa;
use App\Jobs\EnterpriseWiki\VerifyEnterpriseWikiClaim;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Azure migration readiness — queue topology contract.
 *
 * Procynia's queue behaviour is currently described in four independent places:
 *
 *   1. The job classes            ($queue, $timeout, $tries, $backoff)
 *   2. docker-compose.yml         (--queue / --timeout / --tries / --backoff, REDIS_QUEUE_RETRY_AFTER)
 *   3. infra/environments/*.bicepparam  (the Azure Container Apps worker array)
 *   4. routes/console.php         (the per-queue heartbeat jobs)
 *
 * Nothing forces those four to agree. Before the Azure migration that was survivable, because
 * docker-compose.yml was the only runtime. After the migration a silent disagreement between (2)
 * and (3) means a queue that no Container App consumes — jobs would sit in Redis forever with no
 * failure anywhere.
 *
 * This test makes the four sources one contract. It asserts, per queue:
 *   max(job timeout) <= worker --timeout < REDIS_QUEUE_RETRY_AFTER
 * which is the invariant that keeps Laravel from releasing and re-running a job that is still
 * executing — the specific failure mode that a 35 minute ai-requirements job is exposed to.
 *
 * It changes no queue semantics; it only refuses to let them drift apart.
 */
class QueueTopologyContractTest extends TestCase
{
    /**
     * Every queue Procynia actually runs a dedicated worker for, and the job classes routed to it.
     * Derived from the audit of app/Jobs plus the queue-* services in docker-compose.yml.
     *
     * @var array<string, list<class-string>>
     */
    private const QUEUE_JOBS = [
        'default' => [],
        'supplier-harvests' => [
            PrepareDoffinSupplierHarvestRun::class,
            ProcessDoffinSupplierHarvestNotice::class,
        ],
        'supplier-lookups' => [
            PrepareSupplierLookupRun::class,
            ProcessSupplierLookupNotice::class,
        ],
        'ai-requirements' => [
            ProcessRequirementExtractionRun::class,
            ProcessRequirementExtractionChunk::class,
            FinalizeRequirementExtractionRun::class,
        ],
        'enterprise-wiki' => [
            RunEnterpriseWikiDocumentFlow::class,
            ContinueEnterpriseWikiDocumentFlowAfterPages::class,
            FinalizeEnterpriseWikiClaimVerification::class,
            FinalizeEnterpriseWikiPageGeneration::class,
            RunPostIngestQa::class,
            ProcessEnterpriseWikiIngest::class,
            ProcessEnterpriseWikiSection::class,
            FinalizeEnterpriseWikiIngest::class,
        ],
        'enterprise-wiki-reconciliation' => [
            ReconcileEnterpriseWikiClaimSourcesForDocument::class,
        ],
        'enterprise-wiki-claim-verification' => [
            VerifyEnterpriseWikiClaim::class,
        ],
        'enterprise-wiki-maintainer-batches' => [
            RunEnterpriseWikiMaintainerDecisionBatch::class,
            FinalizeEnterpriseWikiMaintainerDecisionBatches::class,
        ],
        'enterprise-wiki-pages' => [
            GenerateEnterpriseWikiAppliedPage::class,
        ],
    ];

    public function test_docker_compose_declares_a_worker_for_every_known_queue(): void
    {
        $workers = $this->composeWorkers();
        $covered = [];

        foreach ($workers as $service => $worker) {
            foreach ($worker['queues'] as $queue) {
                $covered[$queue] = $service;
            }
        }

        foreach (array_keys(self::QUEUE_JOBS) as $queue) {
            $this->assertArrayHasKey(
                $queue,
                $covered,
                sprintf('No docker-compose.yml worker consumes the [%s] queue. Jobs on it would never run.', $queue),
            );
        }
    }

    public function test_no_compose_worker_consumes_an_unknown_queue(): void
    {
        foreach ($this->composeWorkers() as $service => $worker) {
            foreach ($worker['queues'] as $queue) {
                $this->assertArrayHasKey(
                    $queue,
                    self::QUEUE_JOBS,
                    sprintf(
                        'docker-compose.yml service [%s] consumes queue [%s], which this contract does not know about. '
                        .'Add it to QUEUE_JOBS and to infra/environments/*.bicepparam, or the Azure workers will not consume it.',
                        $service,
                        $queue,
                    ),
                );
            }
        }
    }

    public function test_job_timeout_never_exceeds_the_worker_timeout_it_runs_under(): void
    {
        $workers = $this->composeWorkers();

        foreach (self::QUEUE_JOBS as $queue => $jobClasses) {
            $worker = $this->workerForQueue($workers, $queue);

            if ($worker === null || $jobClasses === []) {
                continue;
            }

            foreach ($jobClasses as $jobClass) {
                $jobTimeout = $this->jobTimeout($jobClass);

                if ($jobTimeout === null) {
                    // No explicit timeout: the job inherits the worker --timeout, which is safe by
                    // definition.
                    continue;
                }

                $this->assertLessThanOrEqual(
                    $worker['timeout'],
                    $jobTimeout,
                    sprintf(
                        '%s declares timeout=%ds but runs on queue [%s], whose worker (%s) uses --timeout=%ds. '
                        .'The worker would kill the job before the job times out itself.',
                        $jobClass,
                        $jobTimeout,
                        $queue,
                        $worker['service'],
                        $worker['timeout'],
                    ),
                );
            }
        }
    }

    public function test_every_worker_retry_after_exceeds_its_timeout(): void
    {
        foreach ($this->composeWorkers() as $service => $worker) {
            $this->assertNotNull(
                $worker['retryAfter'],
                sprintf('docker-compose.yml service [%s] sets no REDIS_QUEUE_RETRY_AFTER.', $service),
            );

            $this->assertGreaterThan(
                $worker['timeout'],
                $worker['retryAfter'],
                sprintf(
                    'docker-compose.yml service [%s] has REDIS_QUEUE_RETRY_AFTER=%d and --timeout=%d. '
                    .'Laravel would release the job back onto the queue while it is still running, '
                    .'so a long AI/Wiki job would execute twice.',
                    $service,
                    $worker['retryAfter'],
                    $worker['timeout'],
                ),
            );
        }
    }

    /**
     * The Azure worker array must consume exactly the same queues as docker-compose.yml, with the
     * same timeout and retry_after. Anything else means a queue silently loses its consumer the
     * moment traffic moves to Container Apps.
     */
    #[DataProvider('bicepEnvironments')]
    public function test_bicep_workers_match_the_compose_queue_topology(string $environment): void
    {
        $composeWorkers = $this->composeWorkers();
        $bicepWorkers = $this->bicepWorkers($environment);

        $composeQueues = [];
        foreach ($composeWorkers as $worker) {
            $composeQueues[implode(',', $worker['queues'])] = $worker;
        }

        $bicepQueues = [];
        foreach ($bicepWorkers as $worker) {
            $bicepQueues[$worker['queues']] = $worker;
        }

        $this->assertSame(
            array_keys($composeQueues),
            array_keys($bicepQueues),
            sprintf(
                'infra/environments/%s.bicepparam declares a different set of queue groups than docker-compose.yml. '
                .'Compose: [%s]. Bicep: [%s].',
                $environment,
                implode(' | ', array_keys($composeQueues)),
                implode(' | ', array_keys($bicepQueues)),
            ),
        );

        foreach ($composeQueues as $queueList => $composeWorker) {
            $bicepWorker = $bicepQueues[$queueList];

            $this->assertSame(
                $composeWorker['timeout'],
                $bicepWorker['timeout'],
                sprintf('Queue group [%s]: compose --timeout=%d but %s.bicepparam timeout=%d.',
                    $queueList, $composeWorker['timeout'], $environment, $bicepWorker['timeout']),
            );

            $this->assertSame(
                $composeWorker['retryAfter'],
                $bicepWorker['retryAfter'],
                sprintf('Queue group [%s]: compose REDIS_QUEUE_RETRY_AFTER=%d but %s.bicepparam retryAfter=%d.',
                    $queueList, $composeWorker['retryAfter'], $environment, $bicepWorker['retryAfter']),
            );

            $this->assertSame(
                $composeWorker['tries'],
                $bicepWorker['tries'],
                sprintf('Queue group [%s]: compose --tries=%d but %s.bicepparam tries=%d.',
                    $queueList, $composeWorker['tries'], $environment, $bicepWorker['tries']),
            );
        }
    }

    /**
     * A long-running worker must never be given a shorter Azure termination grace period than the
     * job needs, without that being a deliberate, visible decision. Azure caps the grace period at
     * 600s while ai-requirements runs for up to 2100s, so this test does not demand grace >=
     * timeout (that is impossible). It demands that every worker whose timeout exceeds the grace
     * period uses the maximum Azure allows, so the exposure is as small as the platform permits.
     */
    #[DataProvider('bicepEnvironments')]
    public function test_long_running_workers_use_the_maximum_azure_termination_grace_period(string $environment): void
    {
        $azureGraceCeiling = 600;

        foreach ($this->bicepWorkers($environment) as $worker) {
            if ($worker['timeout'] <= $worker['terminationGracePeriodSeconds']) {
                continue;
            }

            $this->assertSame(
                $azureGraceCeiling,
                $worker['terminationGracePeriodSeconds'],
                sprintf(
                    'Worker [%s] in %s.bicepparam has timeout=%ds but terminationGracePeriodSeconds=%ds. '
                    .'A job can outlive the grace period, so it must at least use the Azure maximum (%ds).',
                    $worker['name'],
                    $environment,
                    $worker['timeout'],
                    $worker['terminationGracePeriodSeconds'],
                    $azureGraceCeiling,
                ),
            );
        }
    }

    /**
     * Nothing may autoscale a worker: Container Apps would evict a replica in the middle of a
     * 35 minute job. The IaC expresses that as replicas being a single fixed number.
     */
    #[DataProvider('bicepEnvironments')]
    public function test_no_worker_can_scale_to_zero(string $environment): void
    {
        foreach ($this->bicepWorkers($environment) as $worker) {
            $this->assertGreaterThanOrEqual(
                1,
                $worker['replicas'],
                sprintf('Worker [%s] in %s.bicepparam must keep at least one replica.', $worker['name'], $environment),
            );
        }
    }

    public function test_scheduler_registers_a_heartbeat_for_every_queue_in_the_contract(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        foreach (array_keys(self::QUEUE_JOBS) as $queue) {
            $this->assertStringContainsString(
                sprintf("OpsQueueHeartbeatJob('%s')", $queue),
                $source,
                sprintf(
                    'routes/console.php registers no heartbeat for queue [%s]. In Azure, worker liveness is only '
                    .'observable through these heartbeats, because Container Apps probes cannot run "php artisan".',
                    $queue,
                ),
            );
        }
    }

    public function test_every_job_class_declares_the_queue_this_contract_expects(): void
    {
        foreach (self::QUEUE_JOBS as $queue => $jobClasses) {
            foreach ($jobClasses as $jobClass) {
                $this->assertSame(
                    $queue,
                    $this->declaredQueue($jobClass),
                    sprintf('%s is expected to run on the [%s] queue.', $jobClass, $queue),
                );
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function bicepEnvironments(): array
    {
        return [
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }

    // -----------------------------------------------------------------------
    // Source parsing
    // -----------------------------------------------------------------------

    /**
     * Parse the queue-* services out of docker-compose.yml.
     *
     * @return array<string, array{service: string, queues: list<string>, timeout: int, tries: int, backoff: int|null, retryAfter: int|null}>
     */
    private function composeWorkers(): array
    {
        $lines = file(base_path('docker-compose.yml'), FILE_IGNORE_NEW_LINES);
        $services = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^ {4}([a-z0-9_-]+):\s*$/', $line, $m) === 1) {
                $current = $m[1];
                $services[$current] = '';

                continue;
            }

            if ($current !== null) {
                $services[$current] .= $line."\n";
            }
        }

        $workers = [];

        foreach ($services as $name => $block) {
            if (preg_match('/--queue=([a-z0-9,_-]+)/', $block, $queueMatch) !== 1) {
                continue;
            }

            preg_match('/--timeout=(\d+)/', $block, $timeoutMatch);
            preg_match('/--tries=(\d+)/', $block, $triesMatch);
            preg_match('/--backoff=(\d+)/', $block, $backoffMatch);
            preg_match('/REDIS_QUEUE_RETRY_AFTER:\s*(\d+)/', $block, $retryMatch);

            $workers[$name] = [
                'service' => $name,
                'queues' => explode(',', $queueMatch[1]),
                'timeout' => (int) ($timeoutMatch[1] ?? 0),
                'tries' => (int) ($triesMatch[1] ?? 0),
                'backoff' => isset($backoffMatch[1]) ? (int) $backoffMatch[1] : null,
                'retryAfter' => isset($retryMatch[1]) ? (int) $retryMatch[1] : null,
            ];
        }

        $this->assertNotEmpty($workers, 'Parsed no queue workers out of docker-compose.yml.');

        return $workers;
    }

    /**
     * Parse the `param workers = [ ... ]` array out of an environment .bicepparam file.
     *
     * @return list<array{name: string, queues: string, processes: int, tries: int, timeout: int, retryAfter: int, replicas: int, terminationGracePeriodSeconds: int}>
     */
    private function bicepWorkers(string $environment): array
    {
        $path = base_path("infra/environments/{$environment}.bicepparam");
        $this->assertFileExists($path, 'Azure infrastructure parameter file is missing.');

        $source = file_get_contents($path);

        $start = strpos($source, 'param workers = [');
        $this->assertNotFalse($start, "No 'param workers' array in {$environment}.bicepparam.");

        $body = substr($source, $start);
        $workers = [];

        preg_match_all('/\{(.*?)\}/s', $body, $entries);

        foreach ($entries[1] as $entry) {
            $worker = [];

            foreach ([
                'name' => '/name:\s*\'([^\']+)\'/',
                'queues' => '/queues:\s*\'([^\']+)\'/',
                'cpu' => '/cpu:\s*\'([^\']+)\'/',
            ] as $key => $pattern) {
                if (preg_match($pattern, $entry, $m) === 1) {
                    $worker[$key] = $m[1];
                }
            }

            foreach ([
                'processes',
                'tries',
                'backoff',
                'timeout',
                'retryAfter',
                'replicas',
                'terminationGracePeriodSeconds',
            ] as $key) {
                if (preg_match('/'.$key.':\s*(\d+)/', $entry, $m) === 1) {
                    $worker[$key] = (int) $m[1];
                }
            }

            if (isset($worker['queues'], $worker['timeout'], $worker['retryAfter'])) {
                $workers[] = $worker;
            }
        }

        $this->assertNotEmpty($workers, "Parsed no workers out of {$environment}.bicepparam.");

        return $workers;
    }

    /** @param array<string, array{queues: list<string>, timeout: int, service: string, retryAfter: int|null, tries: int, backoff: int|null}> $workers */
    private function workerForQueue(array $workers, string $queue): ?array
    {
        foreach ($workers as $worker) {
            if (in_array($queue, $worker['queues'], true)) {
                return $worker;
            }
        }

        return null;
    }

    /** @param class-string $jobClass */
    private function jobTimeout(string $jobClass): ?int
    {
        $defaults = (new ReflectionClass($jobClass))->getDefaultProperties();
        $timeout = $defaults['timeout'] ?? null;

        return is_int($timeout) ? $timeout : null;
    }

    /**
     * The queue is assigned in the constructor (onQueue(...) or $this->queue = ...), so it is read
     * from the class source rather than from a default property value.
     *
     * @param  class-string  $jobClass
     */
    private function declaredQueue(string $jobClass): ?string
    {
        $file = (new ReflectionClass($jobClass))->getFileName();
        $source = file_get_contents($file);

        if (preg_match('/\$this->queue\s*=\s*\'([a-z0-9-]+)\'/', $source, $m) === 1) {
            return $m[1];
        }

        // Some Doffin jobs read their queue from config with a literal fallback. Resolve the
        // effective runtime value rather than the literal, so the test follows the real config.
        if (preg_match('/\$this->queue\s*=\s*\(string\)\s*config\(\'([a-z0-9_.]+)\',\s*\'([a-z0-9-]+)\'\)/', $source, $m) === 1) {
            return (string) config($m[1], $m[2]);
        }

        if (preg_match('/onQueue\(\'([a-z0-9-]+)\'\)/', $source, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/onQueue\(self::(QUEUE|QUEUE_NAME)\)/', $source) === 1
            || preg_match('/\$this->queue\s*=\s*self::(QUEUE|QUEUE_NAME)/', $source) === 1) {
            $reflection = new ReflectionClass($jobClass);

            foreach (['QUEUE', 'QUEUE_NAME'] as $constant) {
                if ($reflection->hasConstant($constant)) {
                    return (string) $reflection->getConstant($constant);
                }
            }
        }

        // Dispatched with an explicit ->onQueue() by the caller rather than by the job itself.
        $reflection = new ReflectionClass($jobClass);

        foreach (['QUEUE', 'QUEUE_NAME'] as $constant) {
            if ($reflection->hasConstant($constant)) {
                return (string) $reflection->getConstant($constant);
            }
        }

        return null;
    }
}

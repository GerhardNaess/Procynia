<?php

namespace App\Support;

/**
 * Rammeverkskatalogen (config/frameworks.php): the standards and regulatory frameworks a module can
 * let a customer choose, by a stable id.
 *
 * Choosing a framework puts it in a piece of work's scope; it never says that the framework's
 * requirements are covered. A module that can say more keeps its own mapping under
 * config/<module>.php 'framework_coverage', and coverage() reports whether one exists and whether it
 * is professionally verified.
 */
final class FrameworkCatalog
{
    public const TYPE_STANDARD = 'standard';

    public const TYPE_REGULATION = 'regulation';

    public const COVERAGE_VERIFIED = 'verified';

    public const COVERAGE_UNVERIFIED = 'unverified';

    public const COVERAGE_NONE = 'none';

    /** @return array<string, array<string, mixed>> */
    private function catalog(): array
    {
        return (array) config('frameworks.catalog', []);
    }

    public function exists(string $key): bool
    {
        return isset($this->catalog()[$key]);
    }

    /** @return list<string> the ids a module offers, in catalog order */
    public function selectableKeys(string $module): array
    {
        return array_keys(array_filter(
            $this->catalog(),
            fn (array $framework): bool => in_array($module, (array) ($framework['modules'] ?? []), true),
        ));
    }

    public function version(string $key): ?string
    {
        $version = $this->catalog()[$key]['version'] ?? null;

        return $version !== null ? (string) $version : null;
    }

    /** «ISO 9001 Kvalitetsledelse» — the designation and the readable name; an unknown id as itself. */
    public function label(string $key): string
    {
        $framework = $this->catalog()[$key] ?? null;

        return $framework === null ? $key : $framework['reference'].' '.__('procynia.framework_catalog.names.'.$key);
    }

    /** Whether the module maps the framework's requirements onto its own work, and how far to trust it. */
    public function coverage(string $module, string $key): string
    {
        $mapping = config($module.'.framework_coverage.'.$key);

        if (! is_array($mapping)) {
            return self::COVERAGE_NONE;
        }

        return ($mapping['verified'] ?? false) === true ? self::COVERAGE_VERIFIED : self::COVERAGE_UNVERIFIED;
    }

    /**
     * The module's choices for a form, translated.
     *
     * @return list<array{key: string, label: string, type: string, type_label: string, domain_label: string, coverage: string}>
     */
    public function options(string $module): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->label($key),
            'type' => (string) $this->catalog()[$key]['type'],
            'type_label' => __('procynia.framework_catalog.types.'.$this->catalog()[$key]['type']),
            'domain_label' => __('procynia.framework_catalog.domains.'.$this->catalog()[$key]['domain']),
            'coverage' => $this->coverage($module, $key),
        ], $this->selectableKeys($module));
    }
}

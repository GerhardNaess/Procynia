<?php

namespace App\Support;

/**
 * The permissions Procynia defines. The customer names roles; Procynia names what a role can hold.
 *
 * This is the whole vocabulary a customer role can be built from. It is code, not data, because
 * every key here must have an enforcement point somewhere in the product — see the migration that
 * creates customer_role_permissions for why that matters.
 *
 * Nothing in anbud appears here. Bid Manager, Kommersiell eier, Contributor and QA keep their own
 * authorization untouched; this catalogue starts where the customer's own vocabulary starts.
 */
final class CustomerPermissionCatalog
{
    public const DOMAIN_QUALITY = 'quality';

    public const DOMAIN_WIKI = 'wiki';

    public const DOMAIN_RISK = 'risk';

    public const DOMAIN_OBJECTIVE = 'objective';

    public const DOMAIN_IMPROVEMENT = 'improvement';

    public const QUALITY_VIEW = 'quality.view';

    public const QUALITY_CREATE = 'quality.create';

    public const QUALITY_EDIT = 'quality.edit';

    public const QUALITY_APPROVE = 'quality.approve';

    public const QUALITY_DELETE = 'quality.delete';

    public const WIKI_VIEW = 'wiki.view';

    public const WIKI_EDIT = 'wiki.edit';

    public const WIKI_REVIEW = 'wiki.review';

    public const WIKI_APPROVE = 'wiki.approve';

    public const WIKI_DELETE = 'wiki.delete';

    public const WIKI_SOURCE_MANAGE = 'wiki.source.manage';

    /*
     * Risiko. Unlike the other domains these say what a role may do, never where: a risk is only
     * reached through a fagområde the same role reaches. See RiskAccessService.
     */
    public const RISK_VIEW = 'risk.view';

    public const RISK_CREATE = 'risk.create';

    public const RISK_EDIT = 'risk.edit';

    /*
     * Registering a likelihood/consequence assessment. Separate from risk.edit on purpose: a
     * fagperson can assess a risk in their area without being able to rewrite what the risk is.
     */
    public const RISK_ASSESS = 'risk.assess';

    /*
     * Accepting the residual risk of an assessment, and revoking that acceptance. A decision, not
     * an edit: neither risk.edit nor risk.assess implies it, and it implies neither of them.
     */
    public const RISK_ACCEPT = 'risk.accept';

    public const RISK_DELETE = 'risk.delete';

    /*
     * Mål og KPI. Area-scoped like Risiko: a role says what, its fagområder say where. An objective
     * carries the fagområde; a KPI reaches it through its objective. See ObjectiveAccessService.
     */
    public const OBJECTIVE_VIEW = 'objective.view';

    /** Creating and changing objectives and their KPIs. */
    public const OBJECTIVE_EDIT = 'objective.edit';

    /*
     * Registering, correcting and withdrawing KPI measurements. Separate from objective.edit for
     * the same reason risk.assess is separate from risk.edit: the person who reports a number need
     * not be the one who may redefine what is measured.
     */
    public const OBJECTIVE_MEASURE = 'objective.measure';

    public const OBJECTIVE_DELETE = 'objective.delete';

    /*
     * Avvik og forbedringer. Area-scoped like Risiko and Mål og KPI: a role says what, its
     * fagområder say where. One case type carries both avvik and forbedring, so one set of keys
     * governs both. See ImprovementCaseAccessService.
     */
    public const IMPROVEMENT_VIEW = 'improvement.view';

    /** Registering and changing cases, and starting their handling (Start behandling). */
    public const IMPROVEMENT_EDIT = 'improvement.edit';

    /*
     * Closing, cancelling and reopening a case. A decision about the case, not an edit of it:
     * improvement.edit does not imply it, and it does not imply improvement.edit.
     */
    public const IMPROVEMENT_CLOSE = 'improvement.close';

    public const IMPROVEMENT_DELETE = 'improvement.delete';

    /**
     * Permission keys grouped by the domain they govern, in the order they should be presented.
     *
     * @return array<string, list<string>>
     */
    public static function domains(): array
    {
        return [
            self::DOMAIN_QUALITY => [
                self::QUALITY_VIEW,
                self::QUALITY_CREATE,
                self::QUALITY_EDIT,
                self::QUALITY_APPROVE,
                self::QUALITY_DELETE,
            ],
            self::DOMAIN_WIKI => [
                self::WIKI_VIEW,
                self::WIKI_EDIT,
                self::WIKI_REVIEW,
                self::WIKI_APPROVE,
                self::WIKI_DELETE,
                self::WIKI_SOURCE_MANAGE,
            ],
            self::DOMAIN_RISK => [
                self::RISK_VIEW,
                self::RISK_CREATE,
                self::RISK_EDIT,
                self::RISK_ASSESS,
                self::RISK_ACCEPT,
                self::RISK_DELETE,
            ],
            self::DOMAIN_OBJECTIVE => [
                self::OBJECTIVE_VIEW,
                self::OBJECTIVE_EDIT,
                self::OBJECTIVE_MEASURE,
                self::OBJECTIVE_DELETE,
            ],
            self::DOMAIN_IMPROVEMENT => [
                self::IMPROVEMENT_VIEW,
                self::IMPROVEMENT_EDIT,
                self::IMPROVEMENT_CLOSE,
                self::IMPROVEMENT_DELETE,
            ],
        ];
    }

    /**
     * The domains whose rights are scoped by fagområde: a key of one of these reaches content only
     * in the areas the same role reaches (BusinessAreaGrants). Kvalitet and Wiki are not scoped.
     *
     * @return list<string>
     */
    public static function areaScopedDomains(): array
    {
        return [self::DOMAIN_RISK, self::DOMAIN_OBJECTIVE, self::DOMAIN_IMPROVEMENT];
    }

    public static function isAreaScoped(string $permissionKey): bool
    {
        foreach (self::areaScopedDomains() as $domain) {
            if (in_array($permissionKey, self::domains()[$domain], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::domains()));
    }

    public static function exists(string $permissionKey): bool
    {
        return in_array($permissionKey, self::all(), true);
    }

    /**
     * Drop keys the running code no longer knows. A role that was given a permission retired in a
     * later release keeps its other permissions and simply grants one thing less.
     *
     * @param  iterable<mixed>  $permissionKeys
     * @return list<string>
     */
    public static function filterKnown(iterable $permissionKeys): array
    {
        $known = [];

        foreach ($permissionKeys as $key) {
            if (is_string($key) && self::exists($key) && ! in_array($key, $known, true)) {
                $known[] = $key;
            }
        }

        return $known;
    }

    /**
     * The translation suffix for a key. `quality.view` would read as nested array access to
     * Laravel's `__()`, so the dots become underscores: procynia.customer_env.roles.permissions.quality_view.
     */
    public static function translationKey(string $permissionKey): string
    {
        return str_replace('.', '_', $permissionKey);
    }

    public static function label(string $permissionKey): string
    {
        return __('procynia.customer_env.roles.permissions.'.self::translationKey($permissionKey));
    }

    public static function domainLabel(string $domain): string
    {
        return __('procynia.customer_env.roles.domains.'.$domain);
    }
}

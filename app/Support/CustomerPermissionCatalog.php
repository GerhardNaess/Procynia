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
        ];
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

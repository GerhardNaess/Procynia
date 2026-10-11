<?php

namespace App\Support\Compliance;

/**
 * «Gjøres i Procynia»: where in Procynia the work behind a library requirement is done — Risiko for
 * 6.1, Mål og KPI for 6.2, Revisjoner for 9.2, and so on. Navigation hints only: no link is stored
 * between a requirement and anything in these modules, and nothing here says a requirement is met.
 *
 * Each key is what a library node lists under related_modules. A hint is shown only when the
 * customer holds the module and the user holds the permission that opens it (null: the module alone
 * decides), the same gates as the rail.
 */
final class FrameworkModuleLinks
{
    /** @var array<string, array{module: string, permission: string|null, href: string}> */
    public const LINKS = [
        'quality' => ['module' => 'quality', 'permission' => 'quality.view', 'href' => '/app/quality'],
        'risk' => ['module' => 'risk', 'permission' => 'risk.view', 'href' => '/app/risk'],
        'objectives' => ['module' => 'objectives', 'permission' => 'objective.view', 'href' => '/app/objectives'],
        'improvements' => ['module' => 'improvements', 'permission' => 'improvement.view', 'href' => '/app/improvements'],
        'compliance_audits' => ['module' => 'compliance', 'permission' => 'compliance.view', 'href' => '/app/compliance/audits'],
        'management_review' => ['module' => 'management_review', 'permission' => 'management_review.view', 'href' => '/app/management-reviews'],
        'supplier' => ['module' => 'supplier', 'permission' => 'supplier.view', 'href' => '/app/supplier-management'],
        'wiki' => ['module' => 'wiki', 'permission' => 'wiki.view', 'href' => '/app/wiki'],
        'tender' => ['module' => 'tender', 'permission' => null, 'href' => '/app/notices'],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::LINKS[$key]);
    }
}

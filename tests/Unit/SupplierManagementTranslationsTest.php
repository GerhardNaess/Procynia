<?php

namespace Tests\Unit;

use App\Support\CustomerPermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Leverandøroppfølging ships in both languages with the same structure: its own strings (including
 * PageHelp with sections), the Tilganger labels of its four permissions and the domain, and the rail
 * and Styring labels. A key added to one language only fails here.
 */
class SupplierManagementTranslationsTest extends TestCase
{
    public function test_both_languages_carry_the_same_supplier_strings_and_every_permission_label(): void
    {
        $no = require dirname(__DIR__, 2).'/lang/no/procynia.php';
        $en = require dirname(__DIR__, 2).'/lang/en/procynia.php';

        $this->assertSame($this->keys($no['supplier_management']), $this->keys($en['supplier_management']));
        $this->assertNotEmpty($no['supplier_management']['help']['index']['sections']);

        foreach ([$no, $en] as $strings) {
            $roles = $strings['customer_env']['roles'];
            $this->assertNotSame('', $roles['domains'][CustomerPermissionCatalog::DOMAIN_SUPPLIER] ?? '');

            foreach (CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_SUPPLIER] as $key) {
                $this->assertNotSame('', $roles['permissions'][CustomerPermissionCatalog::translationKey($key)] ?? '', $key);
            }

            $this->assertNotSame('', $strings['navigation']['modules']['suppliers'] ?? '');
            $this->assertNotSame('', $strings['governance']['descriptions']['suppliers'] ?? '');
        }

        $this->assertSame(
            ['Leverandøroppfølging', 'Leverandører', 'Leverandører'],
            [$no['supplier_management']['module_name'], $no['supplier_management']['index_heading'], $no['navigation']['modules']['suppliers']],
        );
    }

    /** @return list<string> */
    private function keys(array $strings, string $prefix = ''): array
    {
        $keys = [];

        foreach ($strings as $key => $value) {
            $path = $prefix.$key;
            $keys = [...$keys, ...(is_array($value) ? $this->keys($value, $path.'.') : [$path])];
        }

        sort($keys);

        return $keys;
    }
}

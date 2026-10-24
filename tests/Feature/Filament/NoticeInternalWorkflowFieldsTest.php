<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\NoticeResource\Pages\EditNotice;
use App\Models\Notice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The old internal workflow fields on notices (K16) are hidden from every serialization (D25), and
 * the Filament edit form, which fills itself from attributesToArray(), still shows them.
 */
class NoticeInternalWorkflowFieldsTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
    }

    public function test_the_edit_form_still_shows_the_hidden_fields(): void
    {
        $admin = User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt(Str::random(32)),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
        $notice = Notice::query()->create([
            'notice_id' => 'DOFFIN-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'internal_status' => 'interesting',
            'internal_comment' => 'Intern merknad',
            'assigned_to_user_id' => $admin->id,
        ]);

        $this->assertArrayNotHasKey('internal_comment', $notice->toArray());

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditNotice::class, ['record' => $notice->getRouteKey()])
            ->assertFormSet([
                'internal_status' => 'interesting',
                'internal_comment' => 'Intern merknad',
                'assigned_to_user_id' => $admin->id,
            ]);
    }
}

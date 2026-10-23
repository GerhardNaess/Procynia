<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeNoGoDecision;
use App\Models\SavedNoticeStatusChange;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\SavedNoticeStatusService;
use App\Services\Security\ActorChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Phase 0, step 17: changing a case's bid status — Go, No-Go, Levert, Vunnet, Tapt, Trukket — and
 * reopening a No-Go are a person's decisions about the bid. SavedNoticeStatusService is the only
 * way they happen: the guard, the page's own rule on the case under lock, and a
 * saved_notice_status_changes row naming the person.
 */
class TenderBidStatusDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_every_status_change_on_the_page_names_the_person(): void
    {
        $world = $this->world();
        $notice = $world['notice'];

        foreach ([
            SavedNotice::BID_STATUS_QUALIFYING,
            SavedNotice::BID_STATUS_GO_NO_GO,
            SavedNotice::BID_STATUS_IN_PROGRESS,
            SavedNotice::BID_STATUS_SUBMITTED,
            SavedNotice::BID_STATUS_WON,
        ] as $status) {
            $this->actingAs($world['contributor'])
                ->patch(route('app.notices.saved.status.update', ['savedNotice' => $notice->id]), ['status' => $status])
                ->assertRedirect(route('app.notices.saved.show', ['savedNotice' => $notice->id]));
        }

        $this->assertSame(SavedNotice::BID_STATUS_WON, $notice->fresh()->bid_status);
        $changes = $notice->statusChanges()->get();

        $this->assertSame(
            [
                ['discovered', 'qualifying'],
                ['qualifying', 'go_no_go'],
                ['go_no_go', 'in_progress'],
                ['in_progress', 'submitted'],
                ['submitted', 'won'],
            ],
            $changes->map(fn (SavedNoticeStatusChange $change): array => [$change->from_status, $change->to_status])->all(),
        );
        $this->assertSame([$world['contributor']->id], $changes->pluck('changed_by_user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $this->assertTrue($changes->every(fn (SavedNoticeStatusChange $change): bool => (int) $change->customer_id === (int) $world['customer']->id));
    }

    public function test_no_go_and_its_reopening_are_both_recorded(): void
    {
        $world = $this->world();
        $notice = $world['notice'];

        $this->actingAs($world['contributor'])
            ->patch(route('app.notices.saved.status.update', ['savedNotice' => $notice->id]), [
                'status' => SavedNotice::BID_STATUS_NO_GO,
                'bid_closure_reason' => SavedNotice::BID_CLOSURE_REASON_CAPACITY,
                'bid_closure_note' => 'Ingen ledige folk.',
            ])
            ->assertRedirect();

        $decision = SavedNoticeNoGoDecision::query()->where('saved_notice_id', $notice->id)->sole();
        $this->assertSame($world['contributor']->id, (int) $decision->closed_by_user_id);

        $this->actingAs($world['manager'])
            ->patch(route('app.notices.saved.reopen-after-no-go', ['savedNotice' => $notice->id]), [
                'reopen_reason' => 'Kapasiteten er frigjort.',
                'confirm_reopen' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(SavedNotice::BID_STATUS_GO_NO_GO, $notice->fresh()->bid_status);
        $this->assertSame($world['manager']->id, (int) $decision->fresh()->reopened_by_user_id);

        $changes = $notice->statusChanges()->get();
        $this->assertSame([['discovered', 'no_go'], ['no_go', 'go_no_go']], $changes->map(fn ($c): array => [$c->from_status, $c->to_status])->all());
        $this->assertSame([$world['contributor']->id, $world['manager']->id], $changes->pluck('changed_by_user_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([SavedNotice::BID_CLOSURE_REASON_CAPACITY, 'Kapasiteten er frigjort.'], $changes->pluck('reason')->all());
        $this->assertSame('Ingen ledige folk.', $changes->first()->note);
    }

    /** Called directly on the service, past the controller: the page's rule still holds. */
    public function test_the_service_refuses_anyone_outside_the_rule_and_writes_nothing(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');

        $integration = $this->person($world['customer'], 'Integrasjon', User::BID_ROLE_CONTRIBUTOR);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $this->grantCase($world['notice'], $integration, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);

        $inactive = $this->person($world['customer'], 'Inaktiv', User::BID_ROLE_CONTRIBUTOR);
        $inactive->forceFill(['is_active' => false])->save();
        $this->grantCase($world['notice'], $inactive, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);

        $this->assertRefused($world, $integration, HumanDecisionRefused::class, 'integration with case access');
        $this->assertRefused($world, $inactive, HumanDecisionRefused::class, 'inactive person');
        $this->assertRefused($world, $world['caseViewer'], AuthorizationException::class, 'per-case viewer');
        $this->assertRefused($world, $world['bystander'], AuthorizationException::class, 'colleague without the case');
        $this->assertRefused($world, $other['owner'], AuthorizationException::class, 'another customer\'s System Owner');
        $this->assertRefused($world, $other['contributor'], AuthorizationException::class, 'another customer\'s contributor');

        $archived = $this->world('Arkiv AS');
        $archived['notice']->forceFill(['archived_at' => now(), 'history_type' => 'lost'])->save();
        $this->assertRefused($archived, $archived['contributor'], AuthorizationException::class, 'archived case');

        $withoutModule = $this->world('Uten Anbud AS');
        $withoutModule['customer']->packageEntitlements()->delete();
        $this->assertRefused($withoutModule, $withoutModule['contributor'], AuthorizationException::class, 'customer without Anbud');
    }

    public function test_the_api_channel_is_refused_even_for_a_person_who_holds_the_decision(): void
    {
        $world = $this->world();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertRefused($world, $world['contributor'], HumanDecisionRefused::class, 'api channel');
    }

    /** Reopening keeps its own rule: a Bid Manager on the case, or System Owner — never a contributor. */
    public function test_reopening_a_no_go_keeps_its_own_rule_in_the_service(): void
    {
        $world = $this->world();
        $service = app(SavedNoticeStatusService::class);
        $service->transition($world['contributor'], $world['notice'], SavedNotice::BID_STATUS_NO_GO, SavedNotice::BID_CLOSURE_REASON_CAPACITY);
        $before = $this->snapshot();

        foreach (['contributor' => $world['contributor'], 'per-case viewer' => $world['caseViewer']] as $who => $actor) {
            try {
                $service->reopenAfterNoGo($actor, $world['notice'], 'Forsøk.', true);
                $this->fail("{$who} must not reopen");
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame($before, $this->snapshot());

        $service->reopenAfterNoGo($world['owner'], $world['notice'], 'System Owner gjenåpner.', true);
        $this->assertSame(SavedNotice::BID_STATUS_GO_NO_GO, $world['notice']->fresh()->bid_status);
    }

    /**
     * Nothing else in app/ moves a bid status: the service is the only caller of
     * SavedNotice::transitionBidStatus() and the only user of the No-Go record service, and nothing
     * else assigns the column.
     */
    public function test_only_the_status_service_changes_a_bid_status(): void
    {
        $offenders = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            $path = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            $source = $file->getContents();

            if (str_contains($source, '->transitionBidStatus(') && ! in_array($path, [
                'app/Services/SavedNoticeStatusService.php',
                // closeAsNoGo() moves the status as the No-Go record's own half, called only from the service.
                'app/Services/SavedNoticeNoGoDecisionService.php',
            ], true)) {
                $offenders[] = "{$path} calls transitionBidStatus()";
            }

            if (str_contains($source, 'SavedNoticeNoGoDecisionService') && ! in_array($path, [
                'app/Services/SavedNoticeStatusService.php',
                'app/Services/SavedNoticeNoGoDecisionService.php',
            ], true)) {
                $offenders[] = "{$path} uses SavedNoticeNoGoDecisionService";
            }

            if (preg_match('/->bid_status\s*=(?!=)/', $source) === 1 && ! in_array($path, [
                'app/Models/SavedNotice.php',
                'app/Http/Controllers/App/NoticeController.php',
            ], true)) {
                $offenders[] = "{$path} assigns bid_status";
            }
        }

        $this->assertSame([], $offenders);

        // The model assigns it only inside transitionBidStatus(); the controller only for a new case.
        preg_match_all('/->bid_status\s*=(?!=)\s*([^;]+);/', File::get(app_path('Models/SavedNotice.php')), $model);
        $this->assertSame(['$toStatus'], array_map('trim', $model[1]));
        preg_match_all('/->bid_status\s*=(?!=)\s*([^;]+);/', File::get(app_path('Http/Controllers/App/NoticeController.php')), $controller);
        $this->assertSame(['SavedNotice::BID_STATUS_DISCOVERED'], array_map('trim', $controller[1]));
    }

    private function assertRefused(array $world, User $actor, string $refusal, string $who): void
    {
        $before = $this->snapshot();
        $service = app(SavedNoticeStatusService::class);

        $attempts = [
            'go' => fn () => $service->transition($actor, $world['notice'], SavedNotice::BID_STATUS_QUALIFYING),
            'no_go' => fn () => $service->transition($actor, $world['notice'], SavedNotice::BID_STATUS_NO_GO, SavedNotice::BID_CLOSURE_REASON_CAPACITY),
            'reopen' => fn () => $service->reopenAfterNoGo($actor, $world['noGo'], 'Forsøk.', true),
        ];

        foreach ($attempts as $decision => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e::class.': '.$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'notices' => DB::table('saved_notices')->orderBy('id')->get(['id', 'bid_status', 'archived_at', 'bid_closure_reason'])->map(fn ($row): array => (array) $row)->all(),
            'changes' => DB::table('saved_notice_status_changes')->count(),
            'no_go' => DB::table('saved_notice_no_go_decisions')->orderBy('id')->get(['id', 'reopened_at'])->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /**
     * One customer with Anbud, with «Se alle saker» held by System Owner only so that access comes
     * from being part of the case. Two cases: one open, one already No-Go.
     *
     * @return array<string, mixed>
     */
    private function world(string $name = 'Anbud AS'): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'permission_settings' => [Customer::PERMISSION_VIEW_ALL_CASES => ['system_owner']],
        ]);

        $owner = $this->person($customer, 'Eier', User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);
        $contributor = $this->person($customer, 'Bidragsyter', User::BID_ROLE_CONTRIBUTOR);
        $manager = $this->person($customer, 'Bid Manager', User::BID_ROLE_BID_MANAGER);
        $caseViewer = $this->person($customer, 'Leser', User::BID_ROLE_CONTRIBUTOR);
        $bystander = $this->person($customer, 'Kollega', User::BID_ROLE_CONTRIBUTOR);

        $notice = $this->notice($customer, $owner, SavedNotice::BID_STATUS_DISCOVERED);
        $noGo = $this->notice($customer, $owner, SavedNotice::BID_STATUS_NO_GO);

        foreach ([$notice, $noGo] as $case) {
            $this->grantCase($case, $contributor, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);
            $this->grantCase($case, $manager, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);
            $this->grantCase($case, $caseViewer, SavedNoticeUserAccess::ACCESS_ROLE_VIEWER);
        }

        return compact('customer', 'owner', 'contributor', 'manager', 'caseViewer', 'bystander', 'notice', 'noGo');
    }

    private function person(Customer $customer, string $label, string $bidRole, string $role = User::ROLE_USER): User
    {
        return User::factory()->create([
            'name' => $label,
            'email' => Str::slug($label).'.'.Str::lower(Str::random(6)).'@example.test',
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function notice(Customer $customer, User $savedBy, string $status): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $savedBy->id,
            'bid_status' => $status,
            'bid_closure_reason' => $status === SavedNotice::BID_STATUS_NO_GO ? SavedNotice::BID_CLOSURE_REASON_CAPACITY : null,
            'bid_closed_at' => $status === SavedNotice::BID_STATUS_NO_GO ? now() : null,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'ST-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'external_url' => 'https://doffin.no/notices/st',
            'summary' => 'Kort',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);
    }

    private function grantCase(SavedNotice $notice, User $user, string $accessRole): void
    {
        SavedNoticeUserAccess::query()->create([
            'saved_notice_id' => $notice->id,
            'user_id' => $user->id,
            'granted_by_user_id' => $notice->saved_by_user_id,
            'access_role' => $accessRole,
        ]);
    }
}

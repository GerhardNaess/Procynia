<?php

namespace Tests\Unit;

use App\Models\SavedNotice;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SavedNoticeBidLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bid_status_label_returns_expected_label(): void
    {
        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_NEGOTIATION,
        ]);

        $this->assertSame('Forhandling', $notice->bid_status_label);
    }

    public function test_bid_closure_reason_label_returns_expected_label(): void
    {
        $notice = new SavedNotice([
            'bid_closure_reason' => SavedNotice::BID_CLOSURE_REASON_CAPACITY,
        ]);

        $this->assertSame('Manglende kapasitet', $notice->bid_closure_reason_label);
    }

    public function test_linear_progress_transitions_are_allowed(): void
    {
        $cases = [
            SavedNotice::BID_STATUS_DISCOVERED => SavedNotice::BID_STATUS_QUALIFYING,
            SavedNotice::BID_STATUS_QUALIFYING => SavedNotice::BID_STATUS_GO_NO_GO,
            SavedNotice::BID_STATUS_GO_NO_GO => SavedNotice::BID_STATUS_IN_PROGRESS,
            SavedNotice::BID_STATUS_IN_PROGRESS => SavedNotice::BID_STATUS_SUBMITTED,
            SavedNotice::BID_STATUS_SUBMITTED => SavedNotice::BID_STATUS_NEGOTIATION,
        ];

        foreach ($cases as $fromStatus => $toStatus) {
            $notice = new SavedNotice([
                'bid_status' => $fromStatus,
            ]);

            $notice->transitionBidStatus($toStatus);

            $this->assertSame($toStatus, $notice->bid_status);
            $this->assertNull($notice->bid_closed_at);
        }
    }

    public function test_no_go_requires_closure_reason(): void
    {
        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $notice->transitionBidStatus(SavedNotice::BID_STATUS_NO_GO);
    }

    public function test_withdrawn_requires_closure_reason(): void
    {
        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $notice->transitionBidStatus(SavedNotice::BID_STATUS_WITHDRAWN);
    }

    public function test_closure_transitions_are_allowed_with_reason(): void
    {
        $cases = [
            SavedNotice::BID_STATUS_NO_GO => [
                SavedNotice::BID_STATUS_DISCOVERED,
                SavedNotice::BID_STATUS_QUALIFYING,
                SavedNotice::BID_STATUS_GO_NO_GO,
            ],
            SavedNotice::BID_STATUS_WITHDRAWN => [
                SavedNotice::BID_STATUS_IN_PROGRESS,
                SavedNotice::BID_STATUS_SUBMITTED,
                SavedNotice::BID_STATUS_NEGOTIATION,
            ],
        ];

        foreach ($cases as $toStatus => $fromStatuses) {
            foreach ($fromStatuses as $fromStatus) {
                $notice = new SavedNotice([
                    'bid_status' => $fromStatus,
                ]);

                $notice->transitionBidStatus(
                    $toStatus,
                    SavedNotice::BID_CLOSURE_REASON_CAPACITY,
                    'Team capacity is not available.',
                );

                $this->assertSame($toStatus, $notice->bid_status);
                $this->assertSame(SavedNotice::BID_CLOSURE_REASON_CAPACITY, $notice->bid_closure_reason);
                $this->assertSame('Team capacity is not available.', $notice->bid_closure_note);
            }
        }
    }

    public function test_won_and_lost_are_allowed_only_from_submitted_and_negotiation(): void
    {
        foreach ([SavedNotice::BID_STATUS_WON, SavedNotice::BID_STATUS_LOST] as $toStatus) {
            foreach ([SavedNotice::BID_STATUS_SUBMITTED, SavedNotice::BID_STATUS_NEGOTIATION] as $fromStatus) {
                $notice = new SavedNotice([
                    'bid_status' => $fromStatus,
                ]);

                $notice->transitionBidStatus($toStatus);

                $this->assertSame($toStatus, $notice->bid_status);
            }
        }

        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $notice->transitionBidStatus(SavedNotice::BID_STATUS_WON);
    }

    public function test_archived_is_allowed_only_from_terminal_statuses(): void
    {
        foreach ([
            SavedNotice::BID_STATUS_WON,
            SavedNotice::BID_STATUS_LOST,
            SavedNotice::BID_STATUS_NO_GO,
            SavedNotice::BID_STATUS_WITHDRAWN,
        ] as $fromStatus) {
            $notice = new SavedNotice([
                'bid_status' => $fromStatus,
                'bid_closed_at' => Carbon::parse('2026-03-31 09:15:00'),
            ]);

            $notice->transitionBidStatus(SavedNotice::BID_STATUS_ARCHIVED);

            $this->assertSame(SavedNotice::BID_STATUS_ARCHIVED, $notice->bid_status);
            $this->assertNotNull($notice->archived_at);
        }
    }

    public function test_invalid_bid_status_transitions_are_rejected_explicitly(): void
    {
        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $notice->transitionBidStatus(SavedNotice::BID_STATUS_SUBMITTED);
    }

    public function test_archived_cannot_transition_to_any_other_status(): void
    {
        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_ARCHIVED,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $notice->transitionBidStatus(SavedNotice::BID_STATUS_QUALIFYING);
    }

    public function test_closing_statuses_set_bid_closed_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 09:15:00'));

        $cases = [
            SavedNotice::BID_STATUS_NO_GO => SavedNotice::BID_STATUS_DISCOVERED,
            SavedNotice::BID_STATUS_WITHDRAWN => SavedNotice::BID_STATUS_IN_PROGRESS,
            SavedNotice::BID_STATUS_WON => SavedNotice::BID_STATUS_SUBMITTED,
            SavedNotice::BID_STATUS_LOST => SavedNotice::BID_STATUS_NEGOTIATION,
        ];

        foreach ($cases as $toStatus => $fromStatus) {
            $notice = new SavedNotice([
                'bid_status' => $fromStatus,
            ]);

            $notice->transitionBidStatus(
                $toStatus,
                in_array($toStatus, [SavedNotice::BID_STATUS_NO_GO, SavedNotice::BID_STATUS_WITHDRAWN], true)
                    ? SavedNotice::BID_CLOSURE_REASON_CAPACITY
                    : null,
            );

            $this->assertNotNull($notice->bid_closed_at);
            $this->assertTrue($notice->bid_closed_at->equalTo(now()));
        }
    }

    public function test_archived_preserves_existing_bid_closed_at(): void
    {
        $closedAt = Carbon::parse('2026-03-20 11:30:00');
        $notice = new SavedNotice([
            'bid_status' => SavedNotice::BID_STATUS_WON,
            'bid_closed_at' => $closedAt,
        ]);

        $notice->transitionBidStatus(SavedNotice::BID_STATUS_ARCHIVED);

        $this->assertSame(SavedNotice::BID_STATUS_ARCHIVED, $notice->bid_status);
        $this->assertTrue($notice->bid_closed_at->equalTo($closedAt));
    }
}

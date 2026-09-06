<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only check that the Wiki approval data matches the model the code now assumes.
 *
 * Written to be run either side of a deploy in an environment nobody can open a console into and
 * page through by hand. It answers one question — "is anything here in a state the workflow cannot
 * express?" — and it answers it without touching a row.
 *
 * It deliberately repairs nothing. Every failure it can find is either structural corruption, which
 * needs a person to decide what the right value was, or a legacy state whose correct resolution is a
 * product decision (reopen the page? name a new owner?). Guessing either would put a name or a
 * version against work nobody agreed to.
 *
 * Document-owner approval rows are reported but never touched: retiring stale ones is the job of the
 * ordinary sync, by explicit decision in step 1 of docs/enterprise-wiki-approval-model.md.
 */
class EnterpriseWikiAuditApprovalModel extends Command
{
    protected $signature = 'enterprise-wiki:audit-approval-model
                            {--customer= : Limit the audit to one customer id}';

    protected $description = 'Read-only audit of Enterprise Wiki ownership, review and publication data';

    /** Anomalies that mean the workflow cannot proceed correctly, and that a person must resolve. */
    private const SERIOUS = [
        'published_pointer_wrong_page',
        'approved_without_published_version',
        'pending_review_without_assignment',
        'self_review_assignment',
        'reviewer_wrong_customer',
        'page_owner_wrong_customer',
        'multiple_current_versions',
        'review_event_wrong_page',
        'duplicate_notification_key',
    ];

    public function handle(): int
    {
        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $findings = $this->findings($customerId);

        $rows = [];

        foreach ($findings as $check => $ids) {
            $rows[] = [
                in_array($check, self::SERIOUS, true) ? 'ALVORLIG' : 'info',
                $check,
                count($ids),
                count($ids) > 0 ? $this->summarise($ids) : '—',
            ];
        }

        $this->table(['Alvorlighet', 'Kontroll', 'Antall', 'Id-er'], $rows);

        $serious = collect(self::SERIOUS)->sum(fn (string $check): int => count($findings[$check] ?? []));

        if ($serious === 0) {
            $this->info('Ingen alvorlige avvik. Data samsvarer med godkjenningsmodellen.');

            return self::SUCCESS;
        }

        // Non-zero so a deploy pipeline can stop on it. Nothing is fixed here on purpose.
        $this->error("{$serious} alvorlig(e) avvik krever manuell vurdering. Ingenting er endret.");

        return self::FAILURE;
    }

    /**
     * @return array<string, list<int>>
     */
    private function findings(?int $customerId): array
    {
        // Qualified: several of the checks below join tables that also have a customer_id.
        $pages = fn () => EnterpriseWikiPage::query()
            ->when($customerId !== null, fn ($query) => $query->where('enterprise_wiki_pages.customer_id', $customerId));

        return [
            // Structural: a published pointer must name a version of its own page. Retrieval fails
            // closed on this, so it is silent data loss rather than an error anyone would notice.
            'published_pointer_wrong_page' => $pages()
                ->join('enterprise_wiki_page_versions as v', 'v.id', '=', 'enterprise_wiki_pages.published_version_id')
                ->whereColumn('v.enterprise_wiki_page_id', '!=', 'enterprise_wiki_pages.id')
                ->pluck('enterprise_wiki_pages.id')->all(),

            // An approved page that names nothing published cannot serve the content it approved.
            'approved_without_published_version' => $pages()
                ->where('status', EnterpriseWikiPage::STATUS_APPROVED)
                ->whereNull('published_version_id')
                ->pluck('id')->all(),

            // Since step 7 a page only reaches review by being submitted, which always names a
            // submitter and a reviewer. Anything else is pre-change data that cannot be decided.
            'pending_review_without_assignment' => $pages()
                ->where('status', EnterpriseWikiPage::STATUS_PENDING_REVIEW)
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('enterprise_wiki_page_versions as v')
                        ->whereColumn('v.enterprise_wiki_page_id', 'enterprise_wiki_pages.id')
                        ->where('v.is_current', true)
                        ->whereNotNull('v.reviewer_user_id')
                        ->whereNotNull('v.submitted_by_user_id')
                        ->whereNotNull('v.submitted_at');
                })
                ->pluck('id')->all(),

            'self_review_assignment' => $pages()
                ->join('enterprise_wiki_page_versions as v', 'v.enterprise_wiki_page_id', '=', 'enterprise_wiki_pages.id')
                ->whereColumn('v.reviewer_user_id', 'v.submitted_by_user_id')
                ->pluck('enterprise_wiki_pages.id')->all(),

            'reviewer_wrong_customer' => $pages()
                ->join('enterprise_wiki_page_versions as v', 'v.enterprise_wiki_page_id', '=', 'enterprise_wiki_pages.id')
                ->join('users as r', 'r.id', '=', 'v.reviewer_user_id')
                ->whereColumn('r.customer_id', '!=', 'enterprise_wiki_pages.customer_id')
                ->pluck('enterprise_wiki_pages.id')->all(),

            'page_owner_wrong_customer' => $pages()
                ->join('users as o', 'o.id', '=', 'enterprise_wiki_pages.owner_user_id')
                ->whereColumn('o.customer_id', '!=', 'enterprise_wiki_pages.customer_id')
                ->pluck('enterprise_wiki_pages.id')->all(),

            // A partial unique index should already prevent this; the check exists because a
            // constraint that was added later may not hold for rows written before it.
            'multiple_current_versions' => $pages()
                ->join('enterprise_wiki_page_versions as v', 'v.enterprise_wiki_page_id', '=', 'enterprise_wiki_pages.id')
                ->where('v.is_current', true)
                ->groupBy('enterprise_wiki_pages.id')
                ->havingRaw('count(*) > 1')
                ->pluck('enterprise_wiki_pages.id')->all(),

            'review_event_wrong_page' => EnterpriseWikiPageReviewEvent::query()
                ->join('enterprise_wiki_page_versions as v', 'v.id', '=', 'enterprise_wiki_page_review_events.enterprise_wiki_page_version_id')
                ->whereColumn('v.enterprise_wiki_page_id', '!=', 'enterprise_wiki_page_review_events.enterprise_wiki_page_id')
                ->pluck('enterprise_wiki_page_review_events.id')->all(),

            'duplicate_notification_key' => DB::table('user_notifications')
                ->whereNotNull('dedupe_key')
                ->groupBy('dedupe_key')
                ->havingRaw('count(*) > 1')
                ->pluck('dedupe_key')->all(),

            // --- Informational: known, accepted, or resolved by ordinary use ---

            // Ownership could not be derived, so it was deliberately left unset rather than guessed.
            'page_without_owner' => $pages()->whereNull('owner_user_id')->pluck('id')->all(),

            // A page sent back that carries no recorded reason predates the mandatory-reason rule.
            // The reason cannot be reconstructed, and inventing one would be worse than its absence.
            'changes_requested_without_reason' => $pages()
                ->where('status', EnterpriseWikiPage::STATUS_REJECTED)
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('enterprise_wiki_page_review_events as e')
                        ->whereColumn('e.enterprise_wiki_page_id', 'enterprise_wiki_pages.id');
                })
                ->pluck('id')->all(),

            // Left for the ordinary sync to retire — never repaired here. See step 1.
            'stale_looking_owner_approvals' => EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->join('enterprise_wiki_page_versions as v', 'v.id', '=', 'enterprise_wiki_page_version_document_owner_approvals.enterprise_wiki_page_version_id')
                ->join('enterprise_wiki_pages as p', 'p.id', '=', 'v.enterprise_wiki_page_id')
                ->when($customerId !== null, fn ($query) => $query->where('p.customer_id', $customerId))
                ->whereNull('enterprise_wiki_page_version_document_owner_approvals.superseded_at')
                ->where('v.is_current', false)
                ->pluck('enterprise_wiki_page_version_document_owner_approvals.id')->all(),
        ];
    }

    /** @param list<int|string> $ids */
    private function summarise(array $ids): string
    {
        $shown = array_slice($ids, 0, 8);
        $suffix = count($ids) > count($shown) ? ', …' : '';

        return implode(', ', $shown).$suffix;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\EnterpriseWiki\EnterpriseWikiCoverageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:coverage {--customer= : Customer ID}')]
#[Description('Compute enterprise wiki coverage and quality metrics for a customer.')]
class EnterpriseWikiCoverage extends Command
{
    public function handle(EnterpriseWikiCoverageService $service): int
    {
        $customerId = $this->option('customer');

        if (! $customerId) {
            $this->error('--customer option is required.');

            return self::FAILURE;
        }

        $customer = Customer::query()->find((int) $customerId);

        if ($customer === null) {
            $this->error("Customer [{$customerId}] not found.");

            return self::FAILURE;
        }

        $this->info("Computing wiki coverage for customer: {$customer->name} (ID: {$customer->id})");
        $this->newLine();

        $result = $service->computeForCustomer($customer->id);

        $this->printSourceCoverage($result['source_coverage']);
        $this->newLine();
        $this->printPageQuality($result['page_quality']);
        $this->newLine();
        $this->printClaimCoverage($result['claim_coverage']);
        $this->newLine();
        $this->printLint($result['lint']);

        return self::SUCCESS;
    }

    private function printSourceCoverage(array $sc): void
    {
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Kildedekning</>');
        $this->components->twoColumnDetail('Extracted dokumenter', (string) $sc['extracted_documents']);
        $this->components->twoColumnDetail('Med applied run', (string) $sc['documents_with_applied_run']);
        $this->components->twoColumnDetail('Artikkel-side opprettet', (string) $sc['documents_with_article']);
        $this->components->twoColumnDetail('Sammendrag-side opprettet', (string) $sc['documents_with_summary']);
        $this->components->twoColumnDetail('Artikkel med innhold', (string) $sc['documents_with_article_content']);
        $this->components->twoColumnDetail('Sammendrag med innhold', (string) $sc['documents_with_summary_content']);

        if (! empty($sc['gaps'])) {
            $this->newLine();
            $this->line('  <fg=yellow>Gaps:</>');

            foreach ($sc['gaps'] as $gap) {
                $missing = implode(', ', $gap['missing']);
                $this->line("    • {$gap['filename']} — mangler: {$missing}");
            }
        }
    }

    private function printPageQuality(array $pq): void
    {
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Sidekvalitet</>');
        $this->components->twoColumnDetail('Totalt antall sider', (string) $pq['total']);
        $this->components->twoColumnDetail('Med gjeldende versjon', (string) $pq['with_current_version']);
        $this->components->twoColumnDetail('Uten gjeldende versjon', (string) $pq['without_current_version']);
        $this->components->twoColumnDetail('Uten innhold', (string) $pq['without_content']);
        $this->components->twoColumnDetail('Med claims', (string) $pq['with_claims']);
        $this->components->twoColumnDetail('Uten claims', (string) $pq['without_claims']);

        if (! empty($pq['by_page_type'])) {
            $this->newLine();
            $this->line('  <fg=gray>Per side-type:</>');
            foreach ($pq['by_page_type'] as $type => $count) {
                $this->line("    {$type}: {$count}");
            }
        }

        if (! empty($pq['by_status'])) {
            $this->newLine();
            $this->line('  <fg=gray>Per status:</>');
            foreach ($pq['by_status'] as $status => $count) {
                $this->line("    {$status}: {$count}");
            }
        }
    }

    private function printClaimCoverage(array $cc): void
    {
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Claim-dekning</>');
        $this->components->twoColumnDetail('Totalt claims', (string) $cc['claims_total']);
        $this->components->twoColumnDetail('Med kildereferanse', (string) $cc['claims_with_source_reference']);
        $this->components->twoColumnDetail('Uten kildereferanse', (string) $cc['claims_without_source_reference']);

        $pct = $cc['claim_coverage_pct'] !== null
            ? $cc['claim_coverage_pct'] . '%'
            : 'N/A';
        $this->components->twoColumnDetail('Dekningsgrad', $pct);
    }

    private function printLint(array $lint): void
    {
        $errorLabel   = $lint['open_errors']   > 0 ? "<fg=red>{$lint['open_errors']}</>" : '0';
        $warningLabel = $lint['open_warnings'] > 0 ? "<fg=yellow>{$lint['open_warnings']}</>" : '0';

        $this->components->twoColumnDetail('<fg=cyan;options=bold>Lint og struktur</>');
        $this->components->twoColumnDetail('Åpne feil', $errorLabel);
        $this->components->twoColumnDetail('Åpne advarsler', $warningLabel);
        $this->components->twoColumnDetail('Info-funn', (string) $lint['open_info']);
        $this->components->twoColumnDetail('Foreldreløse sider', (string) $lint['orphan_pages']);
    }
}

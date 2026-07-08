<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Services\Ai\Wiki\EnterpriseWikiLintService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:lint {--customer= : Customer ID} {--page= : Wiki page ID}')]
#[Description('Run enterprise wiki lint checks and record findings in enterprise_wiki_lint_findings.')]
class EnterpriseWikiLint extends Command
{
    public function handle(EnterpriseWikiLintService $service): int
    {
        if ($pageId = $this->option('page')) {
            $page = EnterpriseWikiPage::query()->find((int) $pageId);

            if ($page === null) {
                $this->error("Wiki page [{$pageId}] not found.");

                return self::FAILURE;
            }

            $result = $service->lintPage($page);
            $this->printResult($result);

            return self::SUCCESS;
        }

        if ($customerId = $this->option('customer')) {
            $customer = Customer::query()->find((int) $customerId);

            if ($customer === null) {
                $this->error("Customer [{$customerId}] not found.");

                return self::FAILURE;
            }

            $result = $service->lintCustomer((int) $customerId);
            $this->printResult($result);

            return self::SUCCESS;
        }

        // No filter: lint all active customers
        $opened = 0;
        $resolved = 0;

        Customer::query()
            ->where('is_active', true)
            ->chunk(50, function ($customers) use ($service, &$opened, &$resolved): void {
                foreach ($customers as $customer) {
                    $result = $service->lintCustomer($customer->id);
                    $opened += $result['opened'];
                    $resolved += $result['resolved'];
                }
            });

        $this->printResult(compact('opened', 'resolved'));

        return self::SUCCESS;
    }

    /** @param array{opened: int, resolved: int} $result */
    private function printResult(array $result): void
    {
        $this->line(sprintf(
            '[wiki:lint] Opened: %d | Resolved: %d',
            $result['opened'],
            $result['resolved'],
        ));

        if ($result['opened'] > 0) {
            $this->warn(sprintf('%d new finding(s) detected.', $result['opened']));
        } else {
            $this->info('OK — no new findings.');
        }
    }
}

<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use PHPUnit\Framework\TestCase;

class ReconcileEnterpriseWikiClaimSourcesForDocumentJobTest extends TestCase
{
    public function test_queue_name_is_enterprise_wiki_reconciliation(): void
    {
        $job = new ReconcileEnterpriseWikiClaimSourcesForDocument(1);

        $this->assertSame('enterprise-wiki-reconciliation', $job->queue);
    }
}

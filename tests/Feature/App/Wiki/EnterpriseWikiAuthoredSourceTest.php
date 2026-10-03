<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Models\EnterpriseWikiDocument;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * A Wiki source does not have to arrive as a file.
 *
 * Kvalitet's prosessaktiviteter are places where the virksomhet knows something no document
 * records, and the article somebody writes there is a genuine new source. It has to enter the Wiki
 * the way every other source does, or the ingest run never sees it — which is exactly how articles
 * created from an activity used to end up without the concepts, entities and summaries every other
 * source produces.
 *
 * What these tests hold onto is that storeAuthoredText() is the SAME store as store(), not a second
 * one: the same customer-scoped private path, the same SHA-256 identity and deduplication answer,
 * the same claim-source reconciliation. The only difference is that there is nothing to extract.
 */
class EnterpriseWikiAuthoredSourceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_authored_text_becomes_an_extracted_source_with_its_bytes_on_disk(): void
    {
        Queue::fake();

        $customer = $this->createWikiCustomer();

        $stored = app(EnterpriseWikiDocumentUploadService::class)->storeAuthoredText(
            customerId: (int) $customer->id,
            filename: 'Terskelverdier.md',
            text: "# Terskelverdier\n\nSlik vurderes de.",
            ownerUserId: null,
            uploadedByUserId: null,
        );

        $document = $stored['document'];

        $this->assertFalse($stored['reused']);
        $this->assertSame('Terskelverdier.md', $document->original_filename);
        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, $document->document_status);
        $this->assertSame("# Terskelverdier\n\nSlik vurderes de.", $document->extracted_text);

        // The document IS the file: download, re-ingest and the source list all read it.
        $this->assertStringStartsWith("customers/{$customer->id}/wiki-documents/", (string) $document->file_path);
        $this->assertSame(
            "# Terskelverdier\n\nSlik vurderes de.",
            Storage::disk('local')->get((string) $document->file_path),
        );

        // A new source is a new answer to "which document backs this claim", exactly as an upload
        // is.
        Queue::assertPushed(ReconcileEnterpriseWikiClaimSourcesForDocument::class);
    }

    /** Identity is the text, and the same answer the uploaded path gives a file. */
    public function test_identical_text_is_the_same_source_rather_than_a_second_copy(): void
    {
        Queue::fake();

        $customer = $this->createWikiCustomer();
        $service = app(EnterpriseWikiDocumentUploadService::class);

        $first = $service->storeAuthoredText((int) $customer->id, 'En.md', 'Samme tekst.', null, null);
        $second = $service->storeAuthoredText((int) $customer->id, 'To.md', 'Samme tekst.', null, null);

        $this->assertFalse($first['reused']);
        $this->assertTrue($second['reused']);
        $this->assertSame((int) $first['document']->id, (int) $second['document']->id);
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    /** A different customer's identical text is a different source. Identity is per tenant. */
    public function test_another_customers_identical_text_is_its_own_source(): void
    {
        Queue::fake();

        $customer = $this->createWikiCustomer();
        $other = $this->createWikiCustomer('Annen Kunde AS');
        $service = app(EnterpriseWikiDocumentUploadService::class);

        $mine = $service->storeAuthoredText((int) $customer->id, 'En.md', 'Samme tekst.', null, null);
        $theirs = $service->storeAuthoredText((int) $other->id, 'En.md', 'Samme tekst.', null, null);

        $this->assertNotSame((int) $mine['document']->id, (int) $theirs['document']->id);
    }

    /** An empty source is not a source, and is refused before anything is written. */
    public function test_empty_text_is_refused(): void
    {
        Queue::fake();

        $customer = $this->createWikiCustomer();

        $this->expectException(InvalidArgumentException::class);

        app(EnterpriseWikiDocumentUploadService::class)
            ->storeAuthoredText((int) $customer->id, 'Tom.md', "   \n\n ", null, null);
    }
}

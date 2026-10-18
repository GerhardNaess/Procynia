<?php

namespace Tests\Feature\App\Wiki;

use App\Http\Controllers\App\WikiController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Publication invariant (docs/enterprise-wiki-knowledge-integration-plan.md §3.3, criterion 1): a
 * Wiki page is published in exactly one place — the reviewer's approval in WikiController::approve().
 * No generation, repair, relink, recovery or partial-completion path may write
 * published_version_id or move a page to approved by itself.
 */
class EnterpriseWikiPublicationInvariantArchitectureTest extends TestCase
{
    private const PUBLISH_WRITE_PATTERNS = [
        // Assigning a version as the published one (a read-back like
        // `'published_version_id' => $page->published_version_id ...` in a payload is not a write).
        '/[\'"]published_version_id[\'"]\s*=>\s*\$(?!page->published_version_id\b)/',
        '/->published_version_id\s*=(?!=)/',
        '/[\'"]status[\'"]\s*=>\s*EnterpriseWikiPage::STATUS_APPROVED\b/',
        '/->status\s*=\s*EnterpriseWikiPage::STATUS_APPROVED\b/',
    ];

    public function test_only_the_reviewers_approval_publishes_a_wiki_page(): void
    {
        $approve = new ReflectionMethod(WikiController::class, 'approve');
        $approveFile = realpath($approve->getFileName());
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = realpath($file->getPathname());

            foreach (file($path) as $index => $line) {
                $lineNumber = $index + 1;

                foreach (self::PUBLISH_WRITE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $line) !== 1) {
                        continue;
                    }

                    $insideApprove = $path === $approveFile
                        && $lineNumber >= $approve->getStartLine()
                        && $lineNumber <= $approve->getEndLine();

                    if (! $insideApprove) {
                        $violations[] = str_replace(base_path().'/', '', $path).':'.$lineNumber.': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Only WikiController::approve() may publish a Wiki page:\n".implode("\n", $violations));
    }

    public function test_the_guard_recognises_a_publishing_write_and_ignores_a_read_back(): void
    {
        $this->assertSame(1, preg_match(self::PUBLISH_WRITE_PATTERNS[0], "'published_version_id' => \$workingVersion->id,"));
        $this->assertSame(0, preg_match(self::PUBLISH_WRITE_PATTERNS[0], "'published_version_id' => \$page->published_version_id !== null ? 1 : null,"));
        $this->assertSame(1, preg_match(self::PUBLISH_WRITE_PATTERNS[2], "'status' => EnterpriseWikiPage::STATUS_APPROVED,"));
    }
}

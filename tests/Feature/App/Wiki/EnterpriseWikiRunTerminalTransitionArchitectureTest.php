<?php

namespace Tests\Feature\App\Wiki;

use App\Services\EnterpriseWiki\EnterpriseWikiRunTerminalTransition;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Keeps EnterpriseWikiRunTerminalTransition the only writer of a terminal ingest-run status.
 *
 * A direct `'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED` (or failed / escalated /
 * cancelled) anywhere else would bypass the single-owner guarantee and the afterTerminal()
 * extension point that arbeidspakke C uses for exactly one final notification per run.
 */
class EnterpriseWikiRunTerminalTransitionArchitectureTest extends TestCase
{
    private const TERMINAL_WRITE_PATTERNS = [
        "/'status'\\s*=>\\s*EnterpriseWikiIngestRun::STATUS_(COMPLETED|FAILED|ESCALATED|CANCELLED)\\b/",
        '/->status\\s*=\\s*EnterpriseWikiIngestRun::STATUS_(COMPLETED|FAILED|ESCALATED|CANCELLED)\\b/',
    ];

    public function test_no_code_outside_the_transition_writes_a_terminal_run_status(): void
    {
        $allowed = realpath((new ReflectionClass(EnterpriseWikiRunTerminalTransition::class))->getFileName());
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || realpath($file->getPathname()) === $allowed) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                foreach (self::TERMINAL_WRITE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $violations[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Terminal ingest-run statuses must be written through EnterpriseWikiRunTerminalTransition:\n".implode("\n", $violations));
    }

    public function test_the_guard_recognises_a_direct_terminal_write(): void
    {
        $line = "            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,";

        $this->assertSame(1, preg_match(self::TERMINAL_WRITE_PATTERNS[0], $line));
        $this->assertSame(0, preg_match(self::TERMINAL_WRITE_PATTERNS[0], "            'status' => EnterpriseWikiIngestRun::STATUS_QA,"));
    }
}

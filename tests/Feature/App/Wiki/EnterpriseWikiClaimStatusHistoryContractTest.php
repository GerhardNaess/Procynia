<?php

namespace Tests\Feature\App\Wiki;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A4 (docs/external-api-phase-0-plan.md §2a): a claim's approval status and blocking change only
 * through EnterpriseWikiClaimDecisionRecorder, which writes the change and its history event in one
 * transaction. This test reads every PHP file under app/ that works with claims and fails on code
 * that writes those columns any other way — the status change without history this work closed.
 *
 * What it looks for: one of the state columns as an array key or a property assignment, and the
 * call the key is passed to.
 * - update(), fill(), forceFill(), save-style writes: refused, unless listed in WRITES_ELSEWHERE
 *   because the row is not a claim.
 * - create(), firstOrCreate(), insert(): a new claim starts pending; approval_status must be
 *   APPROVAL_STATUS_PENDING there, and the other columns are not set.
 * - change() (the recorder): allowed — that is the path.
 * - anywhere else (an array returned or held in a variable): only in KEYS_ELSEWHERE, files that
 *   present a claim or copy one through the recorder, each with its reason. A new place has to be
 *   reviewed and added here, or routed through the recorder.
 */
class EnterpriseWikiClaimStatusHistoryContractTest extends TestCase
{
    private const STATE_COLUMNS = [
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_comment',
        'blocking_override',
        'blocking_override_by_user_id',
        'blocking_override_at',
    ];

    private const WRITE_CALLS = ['update', 'updateQuietly', 'fill', 'forceFill', 'updateOrCreate', 'updateOrInsert', 'upsert', 'increment', 'decrement'];

    private const CREATE_CALLS = ['create', 'firstOrCreate', 'insert', 'createQuietly', 'make'];

    /** Calls that only combine arrays: the key belongs to the call around them. */
    private const TRANSPARENT_CALLS = ['array_merge', 'array_replace'];

    /** Write calls with these keys that are not claims. file => reason */
    private const WRITES_ELSEWHERE = [
        'Services/EnterpriseWiki/EnterpriseWikiDocumentOwnerApprovalService.php' => 'a document-owner requirement row; its history is enterprise_wiki_document_owner_decisions (step 5)',
    ];

    /** Keys outside any write or create call. file => reason */
    private const KEYS_ELSEWHERE = [
        'Models/EnterpriseWikiClaim.php' => 'casts',
        'Models/EnterpriseWikiCanonicalFact.php' => 'casts of its own columns',
        'Http/Controllers/App/WikiController.php' => 'presents the claim to the page',
        'Services/EnterpriseWiki/EnterpriseWikiRunFindingsService.php' => 'presents the claim in Kjøringer',
        'Services/EnterpriseWiki/EnterpriseWikiClaimContentRepairService.php' => 'claimClonePayload(): the copy for a new version; copyClaim() records the carried-over state through the recorder',
    ];

    public function test_no_code_writes_a_claim_status_without_the_recorder(): void
    {
        $violations = [];
        $seen = [];

        foreach ($this->claimFiles() as $relative => $tokens) {
            foreach ($this->stateKeyUses($tokens) as [$line, $column, $call, $value]) {
                $seen[$relative] = true;
                $where = "{$relative}:{$line} '{$column}'";

                if ($call === 'change') {
                    continue;
                }

                if (in_array($call, self::WRITE_CALLS, true) || $call === 'assignment') {
                    if (! array_key_exists($relative, self::WRITES_ELSEWHERE)) {
                        $violations[] = "{$where} written by {$call}() — use EnterpriseWikiClaimDecisionRecorder::change()";
                    }

                    continue;
                }

                if (in_array($call, self::CREATE_CALLS, true)) {
                    if ($column !== 'approval_status' || ! str_ends_with($value, '::APPROVAL_STATUS_PENDING')) {
                        $violations[] = "{$where} set on create to {$value} — a new claim starts pending";
                    }

                    continue;
                }

                if (! array_key_exists($relative, self::KEYS_ELSEWHERE)) {
                    $violations[] = "{$where} outside a recorded change (in ".($call ?? 'an array').') — route it through the recorder or add a reviewed reason to KEYS_ELSEWHERE';
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));

        // The scan must still see the paths it guards, or it proves nothing.
        foreach ([
            'Services/EnterpriseWiki/EnterpriseWikiClaimDecisionService.php',
            'Services/EnterpriseWiki/EnterpriseWikiAppliedRunLintService.php',
            'Services/EnterpriseWiki/EnterpriseWikiDocumentDeletionService.php',
        ] as $expected) {
            $this->assertArrayHasKey($expected, $seen, "{$expected} no longer seen by the scan");
        }

        foreach (array_keys(self::WRITES_ELSEWHERE + self::KEYS_ELSEWHERE) as $listed) {
            $this->assertArrayHasKey($listed, $seen, "{$listed} is listed but no longer writes or holds a state column — remove it from the list");
        }
    }

    /**
     * Every place in app/ that creates a source link says who made it: a person
     * (linkedByUser), a named pipeline step (linkedBySystem), or, for a copy, the link it copies
     * (linkAttribution, through sourceReferenceClonePayload). Without it the row would read
     * «unrecorded», which only rows from before A4 may.
     */
    public function test_every_source_link_the_application_creates_says_who_made_it(): void
    {
        $sites = [];

        foreach ($this->claimFiles() as $relative => $tokens) {
            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[1] !== 'EnterpriseWikiSourceReference'
                    || ! is_array($tokens[$i + 1] ?? null) || $tokens[$i + 1][0] !== T_DOUBLE_COLON) {
                    continue;
                }

                $statement = '';
                $method = null;

                for ($j = $i; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
                    $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    $statement .= $text;

                    if ($method === null && in_array($text, ['create', 'updateOrCreate', 'firstOrCreate', 'insert'], true)) {
                        $method = $text;
                    }
                }

                if ($method === null) {
                    continue;
                }

                $sites[] = "{$relative}:{$token[2]}";
                $this->assertMatchesRegularExpression(
                    '/linkedByUser\(|linkedBySystem\(|sourceReferenceClonePayload\(/',
                    $statement,
                    "{$relative}:{$token[2]} creates a source link without saying who made it",
                );
            }
        }

        // Pipeline (5), «Koble kilde» (1) and the version copy (1).
        $this->assertCount(7, $sites, implode("\n", $sites));
    }

    /** The scan itself: each rule fires on the code it is meant for. */
    public function test_the_scan_recognises_each_way_of_writing_a_state_column(): void
    {
        $uses = $this->stateKeyUses($this->tokens(<<<'PHP'
            <?php
            $claim->update(['approval_status' => 'approved']);
            EnterpriseWikiClaim::query()->whereIn('id', $ids)->update(array_merge($x, ['blocking_override' => true]));
            $claim->approved_by_user_id = 4;
            EnterpriseWikiClaim::query()->create(['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED, 'x' => match (true) { default => 1 }]);
            $recorder->change($claim, $actor, 'approval_status', ['approval_status' => 'pending']);
            $state = ['approval_comment' => null];
            PHP));

        $this->assertSame([
            [2, 'approval_status', 'update', "'approved'"],
            [3, 'blocking_override', 'update', 'true'],
            [4, 'approved_by_user_id', 'assignment', '4'],
            [5, 'approval_status', 'create', 'EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED'],
            [6, 'approval_status', 'change', "'pending'"],
            [7, 'approval_comment', null, 'null'],
        ], $uses);
    }

    /** @return iterable<string, list<mixed>> relative path => tokens */
    private function claimFiles(): iterable
    {
        $root = dirname(__DIR__, 4).'/app';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'EnterpriseWikiClaim')) {
                continue;
            }

            yield substr($file->getPathname(), strlen($root) + 1) => $this->tokens($source);
        }
    }

    /** @return list<mixed> tokens without whitespace and comments */
    private function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /**
     * @param  list<mixed>  $tokens
     * @return list<array{0: int, 1: string, 2: ?string, 3: string}> line, column, enclosing call (or 'assignment', or null), value as written
     */
    private function stateKeyUses(array $tokens): array
    {
        $uses = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($token[1], '\'"'), self::STATE_COLUMNS, true)
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
                $uses[] = [$token[2], trim($token[1], '\'"'), $this->enclosingCall($tokens, $i), $this->valueAfter($tokens, $i + 2)];
            }

            if ($token[0] === T_OBJECT_OPERATOR
                && is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][1], self::STATE_COLUMNS, true)
                && ($tokens[$i + 2] ?? null) === '=') {
                $uses[] = [$tokens[$i + 1][2], $tokens[$i + 1][1], 'assignment', $this->valueAfter($tokens, $i + 3)];
            }
        }

        return $uses;
    }

    /** @param list<mixed> $tokens */
    private function enclosingCall(array $tokens, int $from): ?string
    {
        $depth = 0;

        for ($j = $from - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token)) {
                continue;
            }

            if (in_array($token, [')', ']', '}'], true)) {
                $depth++;
            } elseif ($token === '[') {
                $depth = max(0, $depth - 1);
            } elseif ($token === '{') {
                if ($depth === 0) {
                    return null;
                }

                $depth--;
            } elseif ($token === '(') {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }

                $name = $tokens[$j - 1] ?? null;
                $name = is_array($name) ? $name[1] : null;

                if (! in_array($name, self::TRANSPARENT_CALLS, true)) {
                    return $name;
                }
            } elseif ($token === ';' && $depth === 0) {
                return null;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private function valueAfter(array $tokens, int $from): string
    {
        $value = '';

        for ($j = $from; isset($tokens[$j]) && ! in_array($tokens[$j], [',', ']', ')', ';'], true); $j++) {
            $value .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }

        return $value;
    }
}

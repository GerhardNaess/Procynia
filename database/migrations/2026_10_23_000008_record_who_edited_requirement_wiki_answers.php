<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbud: who last edited a Wiki answer by hand, and when — A43, docs/external-api-phase-0-plan.md §2l.
 *
 * A hand-edit replaced answer_text in place with no trace of who did it, and the next generation
 * overwrote it without asking. RequirementWikiAnswerService now records the editor here, refuses to
 * replace an edited answer unless the person asks for it, and clears both columns when a new
 * generation replaces the text.
 *
 * Existing rows: an answer whose text no longer matches the sections the engine produced
 * (research_trace.answer.answer_sections) was edited by hand. It is marked edited at its updated_at,
 * by an unknown person (null), so it is protected from here on. A row without that trace is left
 * unmarked — there is nothing to compare it with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->foreignId('edited_by_user_id')->nullable()->after('generated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by_user_id');
        });

        DB::table('saved_notice_ai_requirement_wiki_answers')
            ->whereNotNull('research_trace')
            ->whereNotNull('answer_text')
            ->orderBy('id')
            ->select(['id', 'answer_text', 'research_trace', 'updated_at'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $trace = json_decode((string) $row->research_trace, true);
                    $sections = $trace['answer']['answer_sections'] ?? null;

                    if (! is_array($sections) || $sections === []) {
                        continue;
                    }

                    $generated = implode("\n\n", array_map(static fn ($section): string => is_array($section) ? (string) ($section['text'] ?? '') : '', $sections));
                    $normalize = static fn (string $text): string => trim(str_replace(["\r\n", "\r"], "\n", $text));

                    if ($normalize($generated) !== $normalize((string) $row->answer_text)) {
                        DB::table('saved_notice_ai_requirement_wiki_answers')
                            ->where('id', $row->id)
                            ->update(['edited_at' => $row->updated_at ?? now()]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('edited_by_user_id');
            $table->dropColumn('edited_at');
        });
    }
};

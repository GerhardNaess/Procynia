<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbud: that a Wiki answer was edited by hand, and by whom and when — A43,
 * docs/external-api-phase-0-plan.md §2l.
 *
 * A hand-edit replaced answer_text in place with no trace, and the next generation overwrote it
 * without asking. RequirementWikiAnswerService now sets is_hand_edited with the editor and the time,
 * refuses to replace a hand-edited answer unless the person asks for it, and clears all three when a
 * new generation replaces the text.
 *
 * Existing rows: an answer whose text no longer matches the sections the engine produced
 * (research_trace.answer.answer_sections) was edited by hand, so it gets is_hand_edited and is
 * protected from here on. Who edited it and when were never recorded and are left empty: updated_at
 * is not the edit time, because marking an answer stale writes the row too. A row without that trace
 * is left unmarked — there is nothing to compare it with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->boolean('is_hand_edited')->default(false)->after('generated_at');
            $table->foreignId('edited_by_user_id')->nullable()->after('is_hand_edited')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by_user_id');
        });

        DB::table('saved_notice_ai_requirement_wiki_answers')
            ->whereNotNull('research_trace')
            ->whereNotNull('answer_text')
            ->orderBy('id')
            ->select(['id', 'answer_text', 'research_trace'])
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
                        // Only the fact; the editor and the time stay unknown.
                        DB::table('saved_notice_ai_requirement_wiki_answers')
                            ->where('id', $row->id)
                            ->update(['is_hand_edited' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('edited_by_user_id');
            $table->dropColumn(['edited_at', 'is_hand_edited']);
        });
    }
};

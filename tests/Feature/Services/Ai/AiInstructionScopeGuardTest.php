<?php

namespace Tests\Feature\Services\Ai;

use Tests\TestCase;

/**
 * Purpose: Lock in WHERE the customer AI instruction is allowed to reach. It governs how the AI
 * phrases an answer — tone, terminology, style, capitalization — and is deliberately kept out of
 * every flow that decides what is TRUE: requirement extraction, Excel structure discovery,
 * Enterprise Wiki generation, Wiki research/navigation and retrieval. Widening that surface must be
 * an explicit product decision, so this test fails the moment an instruction reference appears in
 * one of those services.
 * Inputs: None.
 * Returns: None.
 * Side effects: Reads source files only.
 */
class AiInstructionScopeGuardTest extends TestCase
{
    /**
     * Services that legitimately receive the customer instruction, as a style-only, subordinate
     * layer beneath grounded facts and sources.
     */
    private const ALLOWED_CONSUMERS = [
        'app/Services/Ai/Wiki/RequirementWikiAnswerService.php',
        'app/Services/Ai/Wiki/RequirementWikiAnswerAiClient.php',
        'app/Services/Ai/Wiki/RequirementWikiAssessmentService.php',
        'app/Services/Ai/Wiki/RequirementWikiAssessmentAiClient.php',
        'app/Services/Ai/Requirements/RequirementAnswerDraftService.php',
        'app/Services/RequirementAssessmentService.php',
    ];

    /**
     * Flows that must never be shaped by the instruction, because they establish facts, structure
     * or source selection rather than wording.
     */
    private const FORBIDDEN_DIRECTORIES_AND_FILES = [
        'app/Services/Ai/Requirements/RequirementExtractionPipeline.php',
        'app/Services/Ai/Requirements/RequirementExtractionPromptBuilder.php',
        'app/Services/Ai/Requirements/RequirementSegmentExtractionPromptBuilder.php',
        'app/Services/Ai/Requirements/FullDocumentRequirementExtractionPrompt.php',
        'app/Services/Ai/Requirements/DocumentSplitPlanner.php',
        'app/Services/Ai/Requirements/DocumentSplitPlannerPrompt.php',
        'app/Services/Ai/Requirements/RequirementCandidateExtractor.php',
        'app/Services/Ai/Requirements/Excel',
        'app/Services/Ai/Wiki/EnterpriseWikiIngestService.php',
        'app/Services/Ai/Wiki/WikiArticleAiClient.php',
        'app/Services/Ai/Wiki/WikiPageContentAiClient.php',
        'app/Services/Ai/Wiki/WikiSectionAiClient.php',
        'app/Services/Ai/Wiki/WikiClaimVerificationAiClient.php',
        'app/Services/Ai/Wiki/RequirementWikiResearchService.php',
        'app/Services/Ai/Wiki/RequirementWikiResearchAiClient.php',
        'app/Services/Ai/Wiki/WikiQuestionAnswerAiClient.php',
        'app/Services/Ai/Retrieval',
    ];

    public function test_only_the_answer_and_assessment_flows_consume_the_customer_ai_instruction(): void
    {
        foreach (self::ALLOWED_CONSUMERS as $relativePath) {
            $this->assertStringContainsString(
                'caseInstructions',
                (string) file_get_contents(base_path($relativePath)),
                "{$relativePath} is listed as an AI-instruction consumer but no longer references it.",
            );
        }
    }

    public function test_fact_establishing_ai_flows_never_reference_the_ai_instruction(): void
    {
        foreach (self::FORBIDDEN_DIRECTORIES_AND_FILES as $relativePath) {
            foreach ($this->phpFilesUnder(base_path($relativePath)) as $file) {
                $contents = (string) file_get_contents($file);

                foreach (['caseInstructions', 'case_instructions', 'ai_instructions', 'aiInstructions'] as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        sprintf(
                            '%s references "%s". The AI instruction governs wording only and must stay out of extraction, Excel discovery, Wiki generation, research and retrieval.',
                            str_replace(base_path().'/', '', $file),
                            $needle,
                        ),
                    );
                }
            }
        }
    }

    /**
     * saved_notices.ai_instructions is a retired transitional column: it is kept only so the schema
     * change stays reversible, and there is deliberately no fallback to it. If the customer has no
     * instruction, there is no instruction.
     */
    public function test_no_active_code_reads_the_saved_notice_ai_instructions_column(): void
    {
        foreach ($this->phpFilesUnderRecursively(app_path()) as $file) {
            $contents = (string) file_get_contents($file);
            $relative = str_replace(base_path().'/', '', $file);

            foreach (['$record->ai_instructions', '$savedNotice->ai_instructions', '$notice->ai_instructions'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$relative} still reads the retired saved_notices.ai_instructions column.",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnderRecursively(string $path): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $this->assertDirectoryExists($path);

        return array_values(array_filter(
            glob(rtrim($path, '/').'/*.php') ?: [],
            static fn (string $file): bool => is_file($file),
        ));
    }
}

<?php

/*
 * Ledelsens gjennomgåelse (docs/management-review-v1-plan.md §3.4).
 *
 * The sections themselves are code (ManagementReviewSectionCatalog): each has a builder. What lives
 * here is configuration that may grow without code — the frameworks' coverage mappings and the limits.
 *
 * FRAMEWORK_COVERAGE is an aid, never a verdict. Which frameworks a review can name is the shared
 * catalog (config/frameworks.php, App\Support\FrameworkCatalog); a framework appears here only where
 * Procynia maps the inputs the standard expects a management review to consider onto the review's
 * sections, so the overview can say which inputs have been assessed. A framework without a mapping
 * can still be chosen — it is then in the review's scope, and the page says that no coverage analysis
 * is available. Never add a mapping that has not been written against the standard.
 *
 * The mapping is Procynia's own reading and is NOT professionally verified ('verified' => false is
 * shown on the page); it never states that a review complies with the standard. The input texts are
 * our own short wording (lang: management_review.frameworks.*), never the standard's. It is written
 * against the edition named in the catalog ('version'), which is stored on the review when it is
 * finalized, so a later revision does not change what an old review was checked against.
 *
 * An input mapped to 'decisions' is covered when the review records at least one decision or tiltak.
 * A mapped input on 'stakeholder_feedback' is what adds that section to a review.
 */
return [

    // Rows per list kept in a snapshot section, per fagområde; and shown on screen.
    'list_limit' => 50,

    // «Planlegg neste ledelsens gjennomgåelse» appears in Mine oppgaver this many days ahead.
    'next_review_task_days' => 30,

    'framework_coverage' => [
        'iso9001' => [
            'verified' => false,
            'inputs' => [
                ['key' => 'previous_actions', 'clause' => '9.3.2 a', 'sections' => ['previous_decisions']],
                ['key' => 'context_changes', 'clause' => '9.3.2 b', 'sections' => ['context_changes']],
                ['key' => 'customer_feedback', 'clause' => '9.3.2 c1', 'sections' => ['stakeholder_feedback']],
                ['key' => 'objectives', 'clause' => '9.3.2 c2', 'sections' => ['objectives']],
                ['key' => 'process_performance', 'clause' => '9.3.2 c3', 'sections' => ['quality']],
                ['key' => 'nonconformities', 'clause' => '9.3.2 c4', 'sections' => ['improvements']],
                ['key' => 'monitoring', 'clause' => '9.3.2 c5', 'sections' => ['objectives']],
                ['key' => 'audits', 'clause' => '9.3.2 c6', 'sections' => ['compliance']],
                ['key' => 'external_providers', 'clause' => '9.3.2 c7', 'sections' => ['suppliers']],
                ['key' => 'resources', 'clause' => '9.3.2 d', 'sections' => ['resources']],
                ['key' => 'risks', 'clause' => '9.3.2 e', 'sections' => ['risks']],
                ['key' => 'improvement', 'clause' => '9.3.2 f / 9.3.3', 'sections' => ['decisions']],
            ],
        ],
        'iso27001' => [
            'verified' => false,
            'inputs' => [
                ['key' => 'previous_actions', 'clause' => '9.3.2 a', 'sections' => ['previous_decisions']],
                ['key' => 'context_changes', 'clause' => '9.3.2 b', 'sections' => ['context_changes']],
                ['key' => 'interested_parties', 'clause' => '9.3.2 c', 'sections' => ['context_changes']],
                ['key' => 'nonconformities', 'clause' => '9.3.2 d', 'sections' => ['improvements']],
                ['key' => 'monitoring', 'clause' => '9.3.2 d', 'sections' => ['objectives']],
                ['key' => 'audits', 'clause' => '9.3.2 d', 'sections' => ['compliance']],
                ['key' => 'objectives', 'clause' => '9.3.2 d', 'sections' => ['objectives']],
                ['key' => 'stakeholder_feedback', 'clause' => '9.3.2 e', 'sections' => ['stakeholder_feedback']],
                ['key' => 'risks', 'clause' => '9.3.2 f', 'sections' => ['risks']],
                ['key' => 'improvement', 'clause' => '9.3.2 g / 9.3.3', 'sections' => ['decisions']],
            ],
        ],
    ],

];

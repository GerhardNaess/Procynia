<?php

namespace App\Services\Quality;

use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;

/**
 * Everything one activity needs to be written about, and nothing else.
 *
 * WHY THIS EXISTS.
 *
 * "Kontroller leverandørens informasjonssikkerhet" told on its own is a step title, and an article
 * written from a step title is an article about information security in general — true of every
 * company and useful to none. What makes the article this company's is the position the step stands
 * in: the process it belongs to, the role that carries it out, the decision that sent the work here,
 * what happens to the result next, and what the user has already settled about their own process.
 * That is what this assembles.
 *
 * WHY IT IS A NEIGHBOURHOOD AND NOT THE FLOW.
 *
 * The whole kvalitetssystem is not context, it is noise — a thirty-step flow handed over wholesale
 * buries the one step the article is about, and the model starts writing about the process instead.
 * So the reach is one hop: what leads directly into the activity, what it leads directly to, and
 * the branch conditions on those edges, because a condition is the difference between "this is done
 * for every supplier" and "this is done when the supplier is critical". Both directions are bounded.
 *
 * WHAT IS DELIBERATELY ABSENT.
 *
 * Nothing is read from Wiki, from documents, from other processes' flows or from anywhere outside
 * the one process being looked at. An article drafted from an activity is a scaffold for a human
 * author, not a grounded Wiki answer, and dressing it up with retrieved material would make it look
 * like one. See ProcessActivityArticleAiClient.
 *
 * Everything here is read, never written. Lapsed clarification resolutions are left alone — see
 * QualityFlowClarificationService::settled().
 */
class QualityActivityArticleContextBuilder
{
    /** How many steps on each side of the activity travel with it. One hop, and a short one. */
    private const MAX_NEIGHBOURS = 5;

    /** How many outcomes of a neighbouring decision are named. */
    private const MAX_OUTCOMES = 4;

    /** How many settled clarifications travel. The user's most recent word on their own process. */
    private const MAX_CLARIFICATIONS = 8;

    /** A referenced process's description is context, not the article's subject. */
    private const MAX_SUBPROCESS_DESCRIPTION = 1200;

    public function __construct(
        private readonly QualityFlowClarificationService $clarifications,
        private readonly QualityProcessSubprocessService $subprocesses,
    ) {}

    /**
     * The context for one activity of one flow.
     *
     * Null when the flow has no such node — the same answer QualityActivityArticleService::activity()
     * gives, for the same reason: an activity that is not on the flow is not an activity.
     *
     * @return array<string, mixed>|null
     */
    public function build(QualityItem $item, QualityProcessBlueprint $blueprint, string $activityKey): ?array
    {
        $nodes = [];

        foreach ($blueprint->nodes() as $node) {
            $nodes[(string) ($node['key'] ?? '')] = $node;
        }

        $activity = $nodes[$activityKey] ?? null;

        if ($activity === null) {
            return null;
        }

        $lanes = [];

        foreach ($blueprint->lanes() as $lane) {
            $lanes[(string) ($lane['key'] ?? '')] = trim((string) ($lane['label'] ?? ''));
        }

        $edges = $blueprint->edges();
        $customerId = (int) $item->customer_id;

        return [
            'process_title' => trim((string) $item->title),
            'process_description' => trim((string) ($blueprint->description ?? '')),
            'activity_label' => trim((string) ($activity['label'] ?? '')),
            'activity_description' => trim((string) ($activity['description'] ?? '')),
            'activity_role' => $lanes[(string) ($activity['lane'] ?? '')] ?? '',
            'preceding' => $this->preceding($activityKey, $nodes, $lanes, $edges),
            'following' => $this->following($activityKey, $nodes, $lanes, $edges),
            // The activity is itself a whole process somewhere else. The article is still about
            // doing this step here, so what travels is the name and how that process is described —
            // never its flow, which is the other process's article to write.
            'subprocess' => $this->subprocess($customerId, $activity),
            // And the other direction: this flow may be a step inside somebody else's.
            'parent_processes' => $this->subprocesses->parentsOf($customerId, (int) $item->id),
            'clarifications' => array_slice(
                $this->clarifications->settled($item, $blueprint->description),
                0,
                self::MAX_CLARIFICATIONS,
            ),
        ];
    }

    /**
     * What leads into the activity, and on what condition.
     *
     * A decision above the activity is the most load-bearing thing in the whole context: its branch
     * label is the answer to "when is this done", and it is the one piece of knowledge a model
     * would otherwise invent. Its other branches travel with it, because "otherwise the supplier is
     * approved directly" is what makes the condition mean something.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, string>  $lanes
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function preceding(string $activityKey, array $nodes, array $lanes, array $edges): array
    {
        $preceding = [];

        foreach ($edges as $edge) {
            if ((string) ($edge['to'] ?? '') !== $activityKey) {
                continue;
            }

            $source = $nodes[(string) ($edge['from'] ?? '')] ?? null;

            if ($source === null) {
                continue;
            }

            $step = $this->step($source, $lanes, (string) ($edge['label'] ?? ''));

            if ($this->isDecision($source)) {
                $step['other_outcomes'] = $this->outcomes(
                    (string) ($edge['from'] ?? ''),
                    $nodes,
                    $edges,
                    except: $activityKey,
                );
            }

            $preceding[] = $step;

            if (count($preceding) >= self::MAX_NEIGHBOURS) {
                break;
            }
        }

        return $preceding;
    }

    /**
     * What the activity leads to.
     *
     * A decision below it is where the result of the work is judged, so its outcomes travel too:
     * they say what the activity has to produce for the process to go one way rather than the other.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, string>  $lanes
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function following(string $activityKey, array $nodes, array $lanes, array $edges): array
    {
        $following = [];

        foreach ($edges as $edge) {
            if ((string) ($edge['from'] ?? '') !== $activityKey) {
                continue;
            }

            $target = $nodes[(string) ($edge['to'] ?? '')] ?? null;

            if ($target === null) {
                continue;
            }

            $step = $this->step($target, $lanes, (string) ($edge['label'] ?? ''));

            if ($this->isDecision($target)) {
                $step['other_outcomes'] = $this->outcomes((string) ($edge['to'] ?? ''), $nodes, $edges);
            }

            $following[] = $step;

            if (count($following) >= self::MAX_NEIGHBOURS) {
                break;
            }
        }

        return $following;
    }

    /**
     * The branches out of one decision, as "condition -> where it goes".
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<array{condition: string, leads_to: string}>
     */
    private function outcomes(string $decisionKey, array $nodes, array $edges, string $except = ''): array
    {
        $outcomes = [];

        foreach ($edges as $edge) {
            if ((string) ($edge['from'] ?? '') !== $decisionKey) {
                continue;
            }

            $targetKey = (string) ($edge['to'] ?? '');

            if ($targetKey === $except || ! isset($nodes[$targetKey])) {
                continue;
            }

            $outcomes[] = [
                'condition' => trim((string) ($edge['label'] ?? '')),
                'leads_to' => trim((string) ($nodes[$targetKey]['label'] ?? '')),
            ];

            if (count($outcomes) >= self::MAX_OUTCOMES) {
                break;
            }
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $lanes
     * @return array<string, mixed>
     */
    private function step(array $node, array $lanes, string $condition): array
    {
        return [
            'label' => trim((string) ($node['label'] ?? '')),
            'type' => (string) ($node['type'] ?? QualityProcessBlueprint::NODE_STEP),
            'role' => $lanes[(string) ($node['lane'] ?? '')] ?? '',
            'condition' => trim($condition),
        ];
    }

    /** @param  array<string, mixed>  $node */
    private function isDecision(array $node): bool
    {
        return (string) ($node['type'] ?? '') === QualityProcessBlueprint::NODE_DECISION;
    }

    /**
     * The process this activity stands for, when it stands for one.
     *
     * Tenant-scoped the same way every other read of a reference is, and resolved fresh: the node
     * holds an id and nothing else, so what is sent is what that process is called today.
     *
     * @param  array<string, mixed>  $activity
     * @return array{title: string, description: string}|null
     */
    private function subprocess(int $customerId, array $activity): ?array
    {
        $id = (int) ($activity['subprocess_quality_item_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        $referenced = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_PROCESS)
            ->whereKey($id)
            ->first(['id', 'title']);

        if ($referenced === null) {
            return null;
        }

        $blueprint = QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $id)
            ->first(['description']);

        $description = trim((string) ($blueprint->description ?? ''));

        return [
            'title' => (string) $referenced->title,
            'description' => mb_substr($description, 0, self::MAX_SUBPROCESS_DESCRIPTION),
        ];
    }
}

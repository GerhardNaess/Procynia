<?php

namespace App\Services\Doffin;

use App\Models\Department;
use App\Models\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DoffinImportService
{
    public function __construct(
        private readonly DoffinClient $client,
        private readonly DoffinRelevanceService $relevanceService,
        private readonly DoffinNoticeAttentionService $attentionService,
    ) {
    }

    public function importFirstNotice(): array
    {
        Log::info('Starting Doffin first-notice import.');

        $searchResponse = $this->client->search();
        $noticeIds = $this->extractNoticeIds($searchResponse, 1);

        Log::info('Found Doffin notice candidate.', ['notice_id' => $noticeIds[0]]);

        return $this->importNoticeById($noticeIds[0]);
    }

    public function importNoticeById(string $noticeId): array
    {
        Log::info('Starting Doffin notice import by ID.', ['notice_id' => $noticeId]);

        $xml = $this->client->download($noticeId);
        $storedNotice = $this->storeNoticeXml($noticeId, $xml);
        $notice = $storedNotice['notice'];

        Log::info('Stored Doffin notice and raw XML.', [
            'notice_id' => $noticeId,
            'notice_row_id' => $notice->id,
        ]);

        return [
            'notice' => $notice,
            'notice_id' => $noticeId,
            'operation' => $storedNotice['operation'],
            'created' => $storedNotice['operation'] === 'created',
            'updated' => $storedNotice['operation'] === 'updated',
            'xml_stored' => $notice->rawXml !== null,
        ];
    }

    public function extractNoticeIds(array $searchResponse, int $limit): array
    {
        if ($limit < 1) {
            throw new RuntimeException('The Doffin import limit must be at least 1.');
        }

        $candidateLists = [];

        foreach (['hits', 'items', 'results', 'notices', 'data'] as $key) {
            if (isset($searchResponse[$key]) && is_array($searchResponse[$key])) {
                $candidateLists[] = $searchResponse[$key];
            }
        }

        if ($this->isList($searchResponse)) {
            $candidateLists[] = $searchResponse;
        }

        $noticeIds = [];
        $seenNoticeIds = [];

        foreach ($candidateLists as $candidateList) {
            foreach ($candidateList as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $noticeId = $this->extractNoticeIdFromCandidate($candidate);

                if ($noticeId === null || isset($seenNoticeIds[$noticeId])) {
                    continue;
                }

                $noticeIds[] = $noticeId;
                $seenNoticeIds[$noticeId] = true;

                if (count($noticeIds) >= $limit) {
                    return $noticeIds;
                }
            }
        }

        Log::warning('No valid notice ID was found in the Doffin search response.', [
            'search_response_keys' => array_keys($searchResponse),
        ]);

        throw new RuntimeException('No valid notice_id was found in the Doffin search response.');
    }

    public function updateDepartmentVisibility(Notice $notice): void
    {
        $notice->loadMissing('cpvCodes');

        $departmentScores = [];
        $visibleToDepartments = [];

        $departments = Department::query()
            ->with([
                'watchProfiles' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with('cpvCodes')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        foreach ($departments as $department) {
            $department->setRelation(
                'watchProfiles',
                $department->watchProfiles
                    ->filter(fn ($watchProfile): bool => (int) $watchProfile->customer_id === (int) $department->customer_id)
                    ->values(),
            );

            if ($department->watchProfiles->isEmpty()) {
                continue;
            }

            $result = $this->relevanceService->evaluateForDepartment($notice, $department);

            $departmentScores[(string) $department->id] = [
                'score' => (int) $result['relevance_score'],
                'level' => (string) $result['relevance_level'],
                'watch_profile_id' => $result['watch_profile_id'] ?? null,
                'watch_profile_name' => $result['watch_profile_name'] ?? null,
                'cpv_match' => (int) data_get($result, 'score_breakdown.cpv_match', 0),
                'keyword_match' => (int) data_get($result, 'score_breakdown.keyword_match', 0),
            ];

            if (($result['visible'] ?? false) === true) {
                $visibleToDepartments[] = $department->id;
            }
        }

        $notice->fill([
            'department_scores' => $departmentScores,
            'visible_to_departments' => $visibleToDepartments,
        ])->save();

        $this->attentionService->refreshForNotice($notice);
    }

    private function storeNoticeXml(string $noticeId, string $xml): array
    {
        $downloadedAt = now();

        return DB::transaction(function () use ($noticeId, $xml, $downloadedAt) {
            $notice = Notice::query()->firstOrCreate(
                ['notice_id' => $noticeId],
                ['raw_xml_stored' => false],
            );
            $operation = $notice->wasRecentlyCreated ? 'created' : 'updated';

            $notice->fill([
                'raw_xml_stored' => true,
                'downloaded_at' => $downloadedAt,
            ])->save();

            $notice->rawXml()->updateOrCreate(
                ['notice_id' => $notice->id],
                [
                    'xml_content' => $xml,
                    'downloaded_at' => $downloadedAt,
                ],
            );

            return [
                'notice' => $notice->fresh('rawXml'),
                'operation' => $operation,
            ];
        });
    }

    private function extractNoticeIdFromCandidate(array $candidate): ?string
    {
        foreach (['notice_id', 'noticeId', 'id'] as $key) {
            $value = $candidate[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}

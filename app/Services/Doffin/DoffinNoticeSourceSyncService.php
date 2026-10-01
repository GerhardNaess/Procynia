<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\NoticeSource;

/**
 * Keeps a notice's Doffin source record in step with the import.
 *
 * The backfill in the create migration only answers for the notices that existed the day it ran.
 * Every import after that — a new notice, the same notice again, an updated one — has to say the
 * same fact about itself, or the table quietly stops describing reality.
 *
 * ONE PATH, TWO MOMENTS.
 *
 * Both callers are in the Doffin import, and both are deliberate:
 *
 *   DoffinImportService::storeNoticeXml()      identity, the moment Doffin hands us the record
 *   DoffinNoticePipelineService::process()     the publication date, once parsing has read it
 *
 * They are not two places that happen to do the same thing. storeNoticeXml() is where the notice
 * row is created, so it is where the source record must exist and where "we just saw this" is
 * true; but at that point the XML has not been parsed, so a brand-new notice has no publication
 * date yet. Calling the same service again at the end of the pipeline is what fills it in, on the
 * first import rather than the next one. Both calls are upserts, so the second costs one write
 * and can never produce a duplicate.
 *
 * The source key comes from DoffinSourceAdapter, which is the one authority for it. There is no
 * "Doffin", no "DOFFIN", no "doffin-api".
 */
class DoffinNoticeSourceSyncService
{
    public function __construct(
        private readonly DoffinSourceAdapter $adapter,
    ) {}

    /**
     * Ensure this notice has its Doffin source record, and that the record is current.
     *
     * Returns null for a notice with no external id, which cannot be identified in any source and
     * so has nothing to record. Never writes a null over a value that is already known: a re-import
     * that arrives before parsing must not erase a publication date an earlier one established.
     */
    public function syncForNotice(Notice $notice): ?NoticeSource
    {
        $externalId = trim((string) $notice->notice_id);

        if ($externalId === '') {
            return null;
        }

        $now = now();

        $attributes = [
            'notice_id' => $notice->id,
            'source_url' => $this->adapter->sourceUrl($externalId),
            // The source just handed us this record, whether or not anything about it changed.
            'last_seen_at' => $now,
        ];

        if ($notice->publication_date !== null) {
            $attributes['published_at'] = $notice->publication_date;
        }

        $source = NoticeSource::query()->firstOrNew([
            'source' => $this->adapter->sourceKey(),
            'external_id' => $externalId,
        ]);

        if (! $source->exists) {
            // Only ever set once. A record we have seen before was not first seen again.
            $attributes['first_seen_at'] = $now;
        }

        $source->fill($attributes)->save();

        return $source;
    }
}

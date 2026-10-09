<?php

namespace App\Services\EnterpriseWiki\Knowledge;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What a module provides so one of its records can be handed over to Enterprise Wiki.
 *
 * A module only answers domain questions: which record, may this person hand it over, and what
 * does it know. It never sees a model name, a prompt, a retry or a token — the Wiki owns all AI
 * processing after the handoff (WikiKnowledgeHandoffService → ordinary document flow).
 */
interface WikiKnowledgeSource
{
    /** The record type stored as `source_type` (risk, compliance_audit, supplier, ...). */
    public function sourceType(): string;

    /** The technical module key from config/procynia_modules.php. */
    public function sourceModule(): string;

    /** @return class-string<Model> */
    public function modelClass(): string;

    /** Whether the person may open the module at all — a 403 otherwise, as on the module's own pages. */
    public function canOpenModule(User $user): bool;

    /**
     * The record as this person may see it in the module, scoped to their own customer. Null for a
     * hidden, foreign or missing record alike — the caller answers 404 for all three.
     */
    public function findForUser(User $user, int $sourceId): ?Model;

    /** The module's own write permission for this record. The Wiki permission is checked separately. */
    public function canHandOff(User $user, Model $source): bool;

    /** The structured knowledge this record can contribute, built from the module's own data. */
    public function draft(User $user, Model $source): WikiKnowledgeDraft;

    /** The module-gated route the shared dialog posts to. */
    public function storeUrl(Model $source): string;
}

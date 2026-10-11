<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One node of a framework in Kravbiblioteket: a section (a chapter or a sub-clause that only groups
 * others) or a requirement — always a leaf, and the unit a customer assesses.
 *
 * Two things are kept apart on purpose:
 *  - applicability_rule is the requirement's normative character in the standard: mandatory within
 *    the management system's scope; conditional — it may be determined not applicable, with a
 *    justification, where the organisation's activities make it so; or control — a control from a
 *    reference set such as ISO/IEC 27001 Annex A, which the organisation includes or leaves out in
 *    its risk treatment and justifies either way (the basis for a Statement of Applicability);
 *  - the customer's relevance judgement, which lives on the customer's ComplianceRequirement
 *    (applicability), never here.
 *
 * Withdrawn (withdrawn_at) means removed from the library by a later import. It is never deleted:
 * a customer requirement may still point at it.
 */
class ComplianceFrameworkNode extends Model
{
    public const KIND_SECTION = 'section';

    public const KIND_REQUIREMENT = 'requirement';

    public const RULE_MANDATORY = 'mandatory';

    public const RULE_CONDITIONAL = 'conditional';

    public const RULE_CONTROL = 'control';

    public const RULES = [self::RULE_MANDATORY, self::RULE_CONDITIONAL, self::RULE_CONTROL];

    public const TYPES = ['activity', 'leadership', 'documented_information', 'control'];

    public const DOCUMENTED_INFORMATION = ['maintain', 'retain', 'both'];

    protected $fillable = [
        'framework_id',
        'parent_id',
        'node_key',
        'clause',
        'kind',
        'depth',
        'position',
        'title',
        'description',
        'guidance',
        'suggested_documentation',
        'requirement_type',
        'documented_information',
        'applicability_rule',
        'related_modules',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'suggested_documentation' => 'array',
            'related_modules' => 'array',
            'withdrawn_at' => 'datetime',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isRequirement(): bool
    {
        return $this->kind === self::KIND_REQUIREMENT;
    }

    /** Whether the standard lets an organisation determine this requirement not applicable (or leave the control out). */
    public function mayBeNotApplicable(): bool
    {
        return in_array($this->applicability_rule, [self::RULE_CONDITIONAL, self::RULE_CONTROL], true);
    }

    /** A selectable control (Annex A), not a requirement of the management system itself. */
    public function isControl(): bool
    {
        return $this->applicability_rule === self::RULE_CONTROL;
    }

    /**
     * Whether a relevance judgement needs a begrunnelse: leaving a requirement out always does; for
     * a control, including it does too — the Statement of Applicability justifies both.
     */
    public function requiresApplicabilityRationale(string $applicability): bool
    {
        if ($applicability === ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE) {
            return true;
        }

        return $this->isControl() && $applicability === ComplianceRequirement::APPLICABILITY_APPLIES;
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }
}

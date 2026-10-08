<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leverandørprofil: the facts about a supplier's delivery that later decide which control
 * requirements apply (docs/supplier-assurance-v2-plan.md §4). Facts, not an assessment, a score or a
 * status. One row per supplier, current state; how it got there is in SupplierProfileChange.
 *
 * Every answer is nullable and null means «not answered». The yes/no/unknown questions keep unknown
 * (Ikke avklart) apart from no: an unknown answer is treated as if the requirement applies, a no is
 * not. The two lists are arrays of fixed codes; [] is the answer «none of these».
 *
 * The four criticality questions (processes_personal_data, has_system_access,
 * supports_critical_delivery, hard_to_replace) are not here: they stay on the supplier, change
 * through Endre kritikalitet, and decide which of the profile's questions are asked at all.
 *
 * Nothing is mass assignable on purpose: SupplierProfileService is the only writer, and it writes
 * the history row in the same transaction. Customer-wide; reach it only through a supplier from
 * SupplierAccessService::visibleSuppliers().
 */
class SupplierProfile extends Model
{
    public const ANSWER_YES = 'yes';

    public const ANSWER_NO = 'no';

    public const ANSWER_UNKNOWN = 'unknown';

    /** Ja · Nei · Ikke avklart. */
    public const ANSWERS = [self::ANSWER_YES, self::ANSWER_NO, self::ANSWER_UNKNOWN];

    /** Databehandler · Selvstendig behandlingsansvarlig · Ikke avklart. */
    public const DATA_ROLES = ['processor', 'controller', self::ANSWER_UNKNOWN];

    /** Norge · Annet EØS-land · Utenfor EØS · Ikke avklart. */
    public const DATA_LOCATIONS = ['norway', 'eea', 'outside_eea', self::ANSWER_UNKNOWN];

    /** Plan §4.3. Regulatory triggers, several may apply; not the register's single category. */
    public const SECTORS = ['ict', 'construction', 'cleaning', 'staffing', 'health_care', 'transport', 'facility_services', 'goods'];

    /** Plan §4.3. */
    public const HIGH_RISK_CATEGORIES = ['textiles', 'electronics', 'medical_consumables', 'food_agriculture', 'construction_materials', 'furniture_wood', 'other'];

    /**
     * Every profile question, in the order and groups the profile shows them (plan §4.2). The value
     * says what kind of answer it takes: 'answer' (yes/no/unknown), a list of single choices, or
     * 'list' for a multi-choice of fixed codes.
     */
    public const GROUPS = [
        'data_access' => ['data_role', 'special_category_data', 'stores_our_data', 'confidential_information', 'privileged_access', 'data_location'],
        'supply_chain' => ['uses_subcontractors', 'production_outside_eea', 'high_risk_categories'],
        'work' => ['on_site_work', 'labour_intensive', 'sectors'],
        'regulatory' => ['public_contract_terms', 'significant_environmental_impact'],
    ];

    /** The yes/no/unknown questions. */
    public const ANSWER_FIELDS = [
        'special_category_data',
        'stores_our_data',
        'confidential_information',
        'privileged_access',
        'uses_subcontractors',
        'production_outside_eea',
        'on_site_work',
        'labour_intensive',
        'public_contract_terms',
        'significant_environmental_impact',
    ];

    /** The multi-choice lists and their codes. */
    public const LIST_FIELDS = [
        'high_risk_categories' => self::HIGH_RISK_CATEGORIES,
        'sectors' => self::SECTORS,
    ];

    /** The single-choice questions and their values. */
    public const CHOICE_FIELDS = [
        'data_role' => self::DATA_ROLES,
        'data_location' => self::DATA_LOCATIONS,
    ];

    protected $primaryKey = 'supplier_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'high_risk_categories' => 'array',
            'sectors' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * A profile with nothing answered.
     *
     * @return array<string, null>
     */
    public static function emptyAnswers(): array
    {
        return array_fill_keys(self::fields(), null);
    }

    /**
     * The questions asked for this supplier, given the criticality answers on the supplier and the
     * profile's own answers (plan §4.2 «Vises når»): the personal-data questions only when the
     * supplier processes personal data on our behalf, privileged access only with system access, and
     * where the data is only when the supplier stores our data or it is not clear that it does not.
     * A supplier not yet classified has neither personal data nor system access recorded.
     *
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function visibleFields(Supplier $supplier, array $answers): array
    {
        $hidden = [];

        if ($supplier->processes_personal_data !== true) {
            $hidden[] = 'data_role';
            $hidden[] = 'special_category_data';
        }

        if ($supplier->has_system_access !== true) {
            $hidden[] = 'privileged_access';
        }

        if (($answers['stores_our_data'] ?? null) === self::ANSWER_NO) {
            $hidden[] = 'data_location';
        }

        return array_values(array_diff(self::fields(), $hidden));
    }

    /**
     * Komplett: every question asked for the supplier is answered — Ikke avklart and «none of these»
     * count as answers.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function isComplete(Supplier $supplier, array $answers): bool
    {
        foreach (self::visibleFields($supplier, $answers) as $field) {
            if (($answers[$field] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The answers as one value, the shape the history snapshots hold.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = [];

        foreach (self::fields() as $field) {
            $answers[$field] = $this->getAttribute($field);
        }

        return $answers;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

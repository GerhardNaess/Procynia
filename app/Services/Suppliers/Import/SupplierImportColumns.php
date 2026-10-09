<?php

namespace App\Services\Suppliers\Import;

use App\Models\Supplier;
use App\Models\SupplierProfile;

/**
 * The columns «Importer leverandører» understands: the supplier's own fields and nothing else
 * (docs/supplier-management-v1-plan.md, «Excel-import av leverandører»). No assessment, control,
 * decision, document or history — those come from working in Procynia, never from a spreadsheet.
 *
 * A column is recognised by its header, in Norwegian or English, with or without «*» and
 * punctuation, so the template's headers and the headers of a customer's own register both work, in
 * any order. A header nobody recognises is listed and ignored, never guessed.
 */
final class SupplierImportColumns
{
    /** The supplier's master data. `owner` is the intern ansvarlig's e-mail address or name. */
    public const MASTER = [
        'name',
        'organization_number',
        'category',
        'deliverable_description',
        'initial_status',
        'owner',
        'contact_name',
        'contact_email',
        'contact_phone',
        'note',
    ];

    /** Kritikalitet: the level, the interval and the four ja/nei answers — all of them, or none. */
    public const CLASSIFICATION = [
        'criticality',
        'review_interval_months',
        ...Supplier::CRITICALITY_QUESTIONS,
    ];

    /** Without these the file cannot register a single supplier. */
    public const REQUIRED = ['name', 'category', 'deliverable_description'];

    /**
     * Headers a customer's own register commonly uses, besides the template's own in both languages.
     * Compared after normalize().
     */
    private const ALIASES = [
        'name' => ['navn', 'leverandør', 'leverandørnavn', 'firmanavn', 'firma', 'selskap', 'selskapsnavn', 'name', 'supplier', 'company', 'companyname', 'vendor'],
        'organization_number' => ['orgnr', 'organisasjonsnr', 'orgnummer', 'organisasjonsnummer', 'foretaksnummer', 'organizationnumber', 'organisationnumber', 'orgnumber', 'companynumber', 'registrationnumber'],
        'category' => ['kategori', 'category'],
        'deliverable_description' => ['leveranse', 'leverer', 'beskrivelse', 'tjeneste', 'tjenester', 'hvaleverer', 'deliverable', 'description', 'services'],
        'initial_status' => ['status'],
        'owner' => ['internansvarlig', 'ansvarlig', 'eier', 'owner', 'internalowner', 'responsible'],
        'contact_name' => ['kontaktperson', 'kontakt', 'kontaktnavn', 'contact', 'contactperson', 'contactname'],
        'contact_email' => ['epost', 'email', 'kontaktepost', 'contactemail'],
        'contact_phone' => ['telefon', 'tlf', 'mobil', 'telefonnummer', 'phone', 'telephone', 'contactphone'],
        'note' => ['notat', 'merknad', 'kommentar', 'note', 'notes', 'comment'],
        'criticality' => ['kritikalitet', 'criticality'],
        'review_interval_months' => ['vurderingsintervall', 'reviewinterval'],
    ];

    /**
     * Every column, in the template's order: master data, then Kritikalitet, then Leverandørprofil.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [...self::MASTER, ...self::CLASSIFICATION, ...SupplierProfile::fields()];
    }

    public static function isProfileField(string $field): bool
    {
        return in_array($field, SupplierProfile::fields(), true);
    }

    /** The column's header in the given language, as the template writes it. */
    public static function header(string $field, ?string $locale = null): string
    {
        $key = self::isProfileField($field)
            ? "procynia.supplier_management.profile.labels.{$field}"
            : "procynia.supplier_management.import.columns.{$field}";

        return (string) __($key, [], $locale);
    }

    /** Lower case, letters and digits only: «Org.nr. *» and «orgnr» are the same header. */
    public static function normalize(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    }

    /**
     * Normalized header → column.
     *
     * @return array<string, string>
     */
    public static function headerMap(): array
    {
        $map = [];

        foreach (self::fields() as $field) {
            foreach (['no', 'en'] as $locale) {
                $map[self::normalize(self::header($field, $locale))] = $field;
            }

            $map[self::normalize($field)] = $field;
        }

        // The template's own headers win over an alias that happens to collide with them.
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $map[self::normalize($alias)] ??= $field;
            }
        }

        return $map;
    }
}

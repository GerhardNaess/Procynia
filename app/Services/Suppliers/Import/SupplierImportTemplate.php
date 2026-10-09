<?php

namespace App\Services\Suppliers\Import;

use App\Models\Supplier;
use App\Models\SupplierProfile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel template for «Importer leverandører», in the person's language: a sheet to fill in with
 * one header row and nothing else — no example row that could be imported by mistake — and a
 * Veiledning sheet saying, for every column, whether it is required, what it means and which values
 * are allowed.
 *
 * Every cell is written as text, never as a formula, whatever it starts with. The template names no
 * person: the intern ansvarlig is written as an e-mail address the person already knows.
 */
class SupplierImportTemplate
{
    public function __construct(
        private readonly SupplierImportValues $values,
    ) {}

    /** Writes the template to a new temporary file and returns its path; the caller deletes it. */
    public function write(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'supplier-import-template-');
        $tr = fn (string $key): string => (string) __("procynia.supplier_management.import.template.{$key}");
        $bold = (new Style)->setFontBold();
        $wrap = (new Style)->setShouldWrapText();

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName($tr('data_sheet'));
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));

            $headers = [];

            foreach (SupplierImportColumns::fields() as $index => $field) {
                $header = SupplierImportColumns::header($field);
                $headers[] = in_array($field, SupplierImportColumns::REQUIRED, true) ? "{$header} *" : $header;
                $sheet->setColumnWidth(SupplierImportColumns::isProfileField($field) ? 28 : 24, $index + 1);
            }

            $writer->addRow($this->row($headers, $bold));

            $writer->addNewSheetAndMakeItCurrent();
            $guide = $writer->getCurrentSheet();
            $guide->setName($tr('guide_sheet'));
            $guide->setColumnWidth(36, 1);
            $guide->setColumnWidth(14, 2);
            $guide->setColumnWidth(70, 3);
            $guide->setColumnWidth(60, 4);

            $writer->addRow($this->row([$tr('guide_title')], $bold));

            foreach ((array) __('procynia.supplier_management.import.template.guide_intro') as $line) {
                $writer->addRow($this->row([(string) $line], $wrap));
            }

            $writer->addRow($this->row([]));
            $writer->addRow($this->row([$tr('col_column'), $tr('col_required'), $tr('col_explanation'), $tr('col_allowed')], $bold));

            foreach (SupplierImportColumns::fields() as $field) {
                $writer->addRow($this->row([
                    SupplierImportColumns::header($field),
                    in_array($field, SupplierImportColumns::REQUIRED, true) ? $tr('required_yes') : $tr('required_no'),
                    $this->explanation($field),
                    $this->allowedValues($field),
                ], $wrap));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    public function fileName(): string
    {
        return (string) __('procynia.supplier_management.import.template.file_name').'.xlsx';
    }

    private function explanation(string $field): string
    {
        if (SupplierImportColumns::isProfileField($field)) {
            return (string) __("procynia.supplier_management.profile.questions.{$field}");
        }

        return (string) __("procynia.supplier_management.import.template.explanations.{$field}");
    }

    private function allowedValues(string $field): string
    {
        $labels = match (true) {
            $field === 'category' => $this->values->labels(Supplier::CATEGORIES, 'procynia.supplier_management.categories'),
            $field === 'initial_status' => $this->values->labels(Supplier::INITIAL_STATUSES, 'procynia.supplier_management.statuses'),
            $field === 'criticality' => $this->values->labels(Supplier::CRITICALITIES, 'procynia.supplier_management.criticalities'),
            $field === 'review_interval_months' => array_map('strval', Supplier::REVIEW_INTERVALS),
            in_array($field, Supplier::CRITICALITY_QUESTIONS, true) => $this->values->labels(['yes', 'no'], 'procynia.supplier_management.criticality'),
            in_array($field, SupplierProfile::ANSWER_FIELDS, true) => $this->values->labels(SupplierProfile::ANSWERS, 'procynia.supplier_management.profile.answers'),
            $field === 'data_role' => $this->values->labels(SupplierProfile::DATA_ROLES, 'procynia.supplier_management.profile.data_roles'),
            $field === 'data_location' => $this->values->labels(SupplierProfile::DATA_LOCATIONS, 'procynia.supplier_management.profile.data_locations'),
            isset(SupplierProfile::LIST_FIELDS[$field]) => [
                ...$this->values->labels(SupplierProfile::LIST_FIELDS[$field], "procynia.supplier_management.profile.{$field}"),
                (string) __('procynia.supplier_management.profile.none_selected'),
            ],
            default => [],
        };

        $separator = isset(SupplierProfile::LIST_FIELDS[$field]) ? "\n" : ' · ';
        $text = implode($separator, $labels);

        if (isset(SupplierProfile::LIST_FIELDS[$field])) {
            $text = __('procynia.supplier_management.import.template.list_hint')."\n".$text;
        }

        return $text;
    }

    /** @param  list<string>  $values */
    private function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn (string $value): Cell => new StringCell($value, null), $values), $style);
    }
}

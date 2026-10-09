<?php

namespace App\Services\Suppliers\Import;

use App\Models\Supplier;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierProfileService;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierCriticalityService;
use App\Services\Suppliers\SupplierRegistration;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * What «Importer leverandører» would do with each row, against the register as it is now. Reads only;
 * writes nothing. The preview shows it, and SupplierImportService runs it again — inside its lock —
 * before carrying the import out, so what is written is decided on the register at that moment.
 *
 * Every row gets exactly one status:
 *
 *  - error              the row cannot be imported as it is; the messages say why
 *  - file_duplicate     an earlier row in the file is the same supplier (same organisation number,
 *                       or the same name without one); only the first is used
 *  - existing           the customer already has a supplier with this organisation number. It is
 *                       never changed unless the person chooses «Oppdater eksisterende», and then
 *                       only its master data, cell by cell, as listed in `changes`
 *  - possible_duplicate no organisation number, and the customer has a supplier with the same name.
 *                       Not a sure identity: never merged, never updated, skipped
 *  - new                registered as a new supplier, by the same rules as «Registrer leverandør»
 *
 * Identity is the organisation number within the person's own customer, and nothing else: another
 * customer's suppliers are never read (SupplierAccessService::visibleSuppliers()).
 */
class SupplierImportAnalyzer
{
    public const STATUS_NEW = 'new';

    public const STATUS_EXISTING = 'existing';

    public const STATUS_POSSIBLE_DUPLICATE = 'possible_duplicate';

    public const STATUS_FILE_DUPLICATE = 'file_duplicate';

    public const STATUS_ERROR = 'error';

    /** The master data an existing supplier may get from the file, when the person chooses so. */
    public const UPDATABLE_FIELDS = [
        'name',
        'category',
        'deliverable_description',
        'owner_user_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'note',
    ];

    /** @var array<int, bool> owner id => may own suppliers */
    private array $validOwners = [];

    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierImportValues $values,
    ) {}

    /**
     * Every row's status, what the page shows of it and — under `write`, never sent to the page —
     * what would be written; the counts; and a hash of it all, which the confirmation must match.
     *
     * @param  list<array{row: int, cells: array<string, string>}>  $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, hash: string}
     */
    public function analyze(User $actor, array $rows): array
    {
        $this->validOwners = [];
        $customerId = (int) $actor->customer_id;
        $users = User::query()->where('customer_id', $customerId)->get(['id', 'name', 'email', 'is_active', 'customer_id']);
        [$byNumber, $byName] = $this->existingSuppliers($actor);

        $seen = ['numbers' => [], 'names' => [], 'names_without_number' => []];
        $analyzed = [];

        foreach ($rows as $row) {
            $analyzed[] = $this->analyzeRow($actor, $row, $users, $byNumber, $byName, $seen);
        }

        $summary = [
            'total' => count($analyzed),
            'new' => 0,
            'existing' => 0,
            'updatable' => 0,
            'error' => 0,
            'file_duplicate' => 0,
            'possible_duplicate' => 0,
        ];

        foreach ($analyzed as $row) {
            $summary[$row['status']]++;
            $summary['updatable'] += $row['can_update'] ? 1 : 0;
        }

        return [
            'rows' => $analyzed,
            'summary' => $summary,
            // What the person saw: compared again before the import is carried out.
            'hash' => hash('sha256', json_encode(array_map(fn (array $row): array => array_diff_key($row, ['messages' => true]), $analyzed), JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * The customer's suppliers, by organisation number and by name — read through the person's own
     * access, so nothing of another customer can match. The whole register is read once: names are
     * compared in PHP, the same way for the file and the register, whatever the database collation.
     *
     * @return array{0: Collection<string, Supplier>, 1: Collection<string, Collection<int, Supplier>>}
     */
    private function existingSuppliers(User $actor): array
    {
        $suppliers = $this->access->visibleSuppliers($actor)
            ->with('owner:id,name')
            ->orderBy('suppliers.id')
            ->get();

        return [
            $suppliers->whereNotNull('organization_number')->keyBy('organization_number'),
            $suppliers->groupBy(fn (Supplier $supplier): string => self::nameKey($supplier->name)),
        ];
    }

    /** One line, single spaces, lower case: how two names are compared. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param  array{row: int, cells: array<string, string>}  $row
     * @param  Collection<int, User>  $users
     * @param  Collection<string, Supplier>  $byNumber
     * @param  Collection<string, Collection<int, Supplier>>  $byName
     * @param  array{numbers: array<string, int>, names: array<string, int>, names_without_number: array<string, int>}  $seen
     * @return array<string, mixed>
     */
    private function analyzeRow(User $actor, array $row, Collection $users, Collection $byNumber, Collection $byName, array &$seen): array
    {
        $cells = $row['cells'];
        $name = trim((string) preg_replace('/\s+/u', ' ', $cells['name'] ?? ''));
        $nameKey = self::nameKey($name);
        $number = SupplierRegistration::normalizeOrganizationNumber($cells['organization_number'] ?? null);
        $numberValid = $number === null || self::isValidOrganizationNumber($number);

        $result = [
            'row' => (int) $row['row'],
            'status' => self::STATUS_NEW,
            'can_update' => false,
            'values' => $this->displayValues($cells, $name, $number),
            'existing' => null,
            'duplicate_of_row' => null,
            'changes' => [],
            'messages' => [],
            'write' => null,
        ];

        // The first row with an identity keeps it; any later row with the same is a duplicate in the
        // file, whatever else it says. The same name counts as the same supplier when either row has
        // no organisation number — as it does against the register.
        $duplicateOf = match (true) {
            $number !== null && $numberValid => $seen['numbers'][$number] ?? ($nameKey !== '' ? ($seen['names_without_number'][$nameKey] ?? null) : null),
            $number === null && $nameKey !== '' => $seen['names'][$nameKey] ?? null,
            default => null,
        };

        if ($number !== null && $numberValid) {
            $seen['numbers'][$number] ??= $result['row'];
        }

        if ($nameKey !== '') {
            $seen['names'][$nameKey] ??= $result['row'];

            if ($number === null) {
                $seen['names_without_number'][$nameKey] ??= $result['row'];
            }
        }

        if ($duplicateOf !== null) {
            $result['status'] = self::STATUS_FILE_DUPLICATE;
            $result['duplicate_of_row'] = $duplicateOf;
            $result['messages'][] = $this->notice('file_duplicate', ['row' => $duplicateOf]);

            return $result;
        }

        if (! $numberValid) {
            $result['status'] = self::STATUS_ERROR;
            $result['messages'][] = $this->error('organization_number_invalid', ['value' => $number]);

            return $result;
        }

        $existing = $number !== null ? $byNumber->get($number) : null;

        if ($existing instanceof Supplier) {
            return $this->existingRow($actor, $result, $cells, $existing, $users);
        }

        // The same name and no sure identity on one side: it may be the same company, so it is
        // neither merged nor registered again. Without a number in the file any namesake counts; with
        // one, only a namesake registered without a number.
        $namesakes = $nameKey !== '' ? $byName->get($nameKey, collect()) : collect();
        $uncertain = $number === null ? $namesakes->first() : $namesakes->firstWhere('organization_number', null);

        if ($uncertain instanceof Supplier) {
            $result['status'] = self::STATUS_POSSIBLE_DUPLICATE;
            $result['existing'] = $this->existingPayload($uncertain);
            $result['messages'][] = $this->notice($number === null ? 'possible_duplicate' : 'possible_duplicate_no_number', ['name' => $uncertain->name]);

            return $result;
        }

        return $this->newRow($actor, $result, $cells, $name, $number, $nameKey, $users, $byName);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $cells
     * @param  Collection<int, User>  $users
     * @param  Collection<string, Collection<int, Supplier>>  $byName
     * @return array<string, mixed>
     */
    private function newRow(User $actor, array $result, array $cells, string $name, ?string $number, string $nameKey, Collection $users, Collection $byName): array
    {
        $errors = [];

        // The same name as a supplier with another organisation number: a different company (a
        // subsidiary, a namesake) by the only sure identity there is — registered, and said.
        if ($number !== null && $byName->has($nameKey)) {
            $other = $byName->get($nameKey)->first();
            $result['messages'][] = $this->notice('same_name_other_number', [
                'name' => $other->name,
                'number' => $other->organization_number ?? '—',
            ]);
        }

        $category = $this->choiceCell($cells, 'category', Supplier::CATEGORIES, 'procynia.supplier_management.categories', $errors);

        $initialStatus = Supplier::STATUS_ACTIVE;

        if (($cells['initial_status'] ?? '') !== '') {
            $initialStatus = $this->values->choice($cells['initial_status'], Supplier::STATUSES, 'procynia.supplier_management.statuses');

            if ($initialStatus === null || ! in_array($initialStatus, Supplier::INITIAL_STATUSES, true)) {
                $errors[] = $this->error('initial_status_invalid', ['value' => $cells['initial_status']]);
                $initialStatus = Supplier::STATUS_ACTIVE;
            }
        }

        $owner = $this->owner($actor, $cells['owner'] ?? '', $users, true, $errors);

        $fields = [
            'name' => $name,
            'organization_number' => $number,
            'category' => $category,
            'deliverable_description' => $cells['deliverable_description'] ?? '',
            'owner_user_id' => $owner?->id,
            'contact_name' => $cells['contact_name'] ?? null,
            'contact_email' => $cells['contact_email'] ?? null,
            'contact_phone' => $cells['contact_phone'] ?? null,
            'note' => $cells['note'] ?? null,
        ];

        // «Registrer leverandør»'s own rules — with the owner left out when it could not be found,
        // so that is said once, not twice.
        $rules = SupplierRegistration::masterDataRules();

        if ($owner === null) {
            unset($rules['owner_user_id']);
        }

        if ($category === null && ($cells['category'] ?? '') !== '') {
            unset($rules['category']);
        }

        $errors = [...$errors, ...$this->validate($fields, $rules)];

        $classification = $this->classification($cells, $errors);
        $profile = $this->profile($cells, $classification, $result, $errors);

        $result['values']['category'] = $category;
        $result['values']['initial_status'] = $initialStatus;
        $result['values']['owner'] = $owner !== null ? ['id' => (int) $owner->id, 'name' => $owner->name, 'is_default' => ($cells['owner'] ?? '') === ''] : null;
        $result['values']['criticality'] = $classification['criticality'] ?? null;
        $result['values']['review_interval_months'] = $classification['review_interval_months'] ?? null;
        $result['values']['profile_answers'] = $profile !== null ? count(array_filter($profile, fn ($answer): bool => $answer !== null)) : 0;

        if ($errors !== []) {
            $result['status'] = self::STATUS_ERROR;
            $result['messages'] = [...$errors, ...$result['messages']];

            return $result;
        }

        $result['write'] = [
            'action' => 'create',
            'fields' => array_map(fn ($value) => is_string($value) && $value === '' ? null : $value, $fields),
            'initial_status' => $initialStatus,
            'classification' => $classification,
            'profile' => $profile,
        ];

        return $result;
    }

    /**
     * An organisation number the customer already has. What would change is worked out cell by cell:
     * an empty cell keeps what is there. Only master data — status, criticality and the profile have
     * their own history with a reason, and are changed on the supplier page.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $cells
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function existingRow(User $actor, array $result, array $cells, Supplier $existing, Collection $users): array
    {
        $result['status'] = self::STATUS_EXISTING;
        $result['existing'] = $this->existingPayload($existing);
        $errors = [];

        if (self::nameKey($existing->name) !== self::nameKey($cells['name'] ?? '') && ($cells['name'] ?? '') !== '') {
            $result['messages'][] = $this->notice('existing_other_name', ['name' => $existing->name]);
        }

        $ignored = array_filter(
            ['initial_status', ...SupplierImportColumns::CLASSIFICATION, ...SupplierProfile::fields()],
            fn (string $field): bool => ($cells[$field] ?? '') !== '',
        );

        if ($ignored !== []) {
            $result['messages'][] = $this->notice('existing_fields_ignored');
        }

        if ($existing->isEnded()) {
            $result['messages'][] = $this->notice('existing_ended');

            return $result;
        }

        $candidate = [];

        foreach (['name', 'deliverable_description', 'contact_name', 'contact_email', 'contact_phone', 'note'] as $field) {
            if (($cells[$field] ?? '') !== '') {
                $candidate[$field] = $field === 'name' ? trim((string) preg_replace('/\s+/u', ' ', $cells[$field])) : $cells[$field];
            }
        }

        if (($cells['category'] ?? '') !== '') {
            $candidate['category'] = $this->choiceCell($cells, 'category', Supplier::CATEGORIES, 'procynia.supplier_management.categories', $errors);
        }

        if (($cells['owner'] ?? '') !== '') {
            $candidate['owner_user_id'] = $this->owner($actor, $cells['owner'], $users, false, $errors)?->id;
        }

        $rules = array_intersect_key(SupplierRegistration::masterDataRules(), array_filter($candidate, fn ($value): bool => $value !== null));
        $errors = [...$errors, ...$this->validate($candidate, $rules)];

        if ($errors !== []) {
            $result['messages'] = [...$errors, $this->notice('existing_not_updatable'), ...$result['messages']];

            return $result;
        }

        $changes = [];
        $write = [];

        foreach (self::UPDATABLE_FIELDS as $field) {
            if (! array_key_exists($field, $candidate)) {
                continue;
            }

            $current = $existing->getAttribute($field);
            $new = $candidate[$field];

            if ((string) $current === (string) $new) {
                continue;
            }

            $write[$field] = $new;
            $changes[] = [
                'field' => $field,
                'from' => $field === 'owner_user_id' ? $existing->owner?->name : $current,
                'to' => $field === 'owner_user_id' ? $users->firstWhere('id', $new)?->name : $new,
            ];
        }

        if ($changes === []) {
            $result['messages'][] = $this->notice('existing_unchanged');

            return $result;
        }

        $result['changes'] = $changes;
        $result['can_update'] = true;
        $result['write'] = [
            'action' => 'update',
            'supplier_id' => (int) $existing->id,
            'fields' => $write,
        ];

        return $result;
    }

    /**
     * Kritikalitet, all or nothing: nothing filled in is «Ikke vurdert», classified later on the
     * supplier page; anything filled in needs the level and all four answers, and an interval for
     * Viktig and Kritisk — SupplierCriticalityService's rules.
     *
     * @param  array<string, string>  $cells
     * @param  list<array{type: string, text: string}>  $errors
     * @return array<string, mixed>|null
     */
    private function classification(array $cells, array &$errors): ?array
    {
        $filled = array_filter(SupplierImportColumns::CLASSIFICATION, fn (string $field): bool => ($cells[$field] ?? '') !== '');

        if ($filled === []) {
            return null;
        }

        $missing = array_values(array_filter(['criticality', ...Supplier::CRITICALITY_QUESTIONS], fn (string $field): bool => ($cells[$field] ?? '') === ''));

        if ($missing !== []) {
            $errors[] = $this->error('classification_incomplete', ['columns' => $this->columnList($missing)]);

            return null;
        }

        $before = count($errors);
        $data = [
            'criticality' => $this->choiceCell($cells, 'criticality', Supplier::CRITICALITIES, 'procynia.supplier_management.criticalities', $errors),
            'review_interval_months' => null,
        ];

        foreach (Supplier::CRITICALITY_QUESTIONS as $question) {
            $answer = $this->values->yesNo($cells[$question]);

            if ($answer === null) {
                $errors[] = $this->error('unknown_value', ['column' => SupplierImportColumns::header($question), 'value' => $cells[$question], 'allowed' => $this->allowed(['yes', 'no'], 'procynia.supplier_management.criticality')]);
            }

            $data[$question] = $answer;
        }

        if (($cells['review_interval_months'] ?? '') !== '') {
            $months = preg_match('/\d+/', $cells['review_interval_months'], $match) === 1 ? (int) $match[0] : null;

            if (! in_array($months, Supplier::REVIEW_INTERVALS, true)) {
                $errors[] = $this->error('unknown_value', ['column' => SupplierImportColumns::header('review_interval_months'), 'value' => $cells['review_interval_months'], 'allowed' => implode(', ', Supplier::REVIEW_INTERVALS)]);
            }

            $data['review_interval_months'] = $months;
        }

        if (count($errors) > $before) {
            return null;
        }

        $validator = Validator::make($data, SupplierCriticalityService::rules());

        if ($validator->errors()->has('review_interval_months')) {
            $errors[] = ['type' => 'error', 'text' => (string) __('procynia.supplier_management.validation.interval_required')];

            return null;
        }

        if ($validator->fails()) {
            $errors[] = $this->error('classification_incomplete', ['columns' => $this->columnList(['criticality', ...Supplier::CRITICALITY_QUESTIONS])]);

            return null;
        }

        return SupplierCriticalityService::classification($data);
    }

    /**
     * Leverandørprofil answers, each one of the profile's own fixed values — never free text. A
     * question the profile does not ask for this supplier (SupplierProfile::visibleFields()) is not
     * imported, and that is said. Null when nothing is answered.
     *
     * @param  array<string, string>  $cells
     * @param  array<string, mixed>|null  $classification
     * @param  array<string, mixed>  $result
     * @param  list<array{type: string, text: string}>  $errors
     * @return array<string, mixed>|null
     */
    private function profile(array $cells, ?array $classification, array &$result, array &$errors): ?array
    {
        $answers = SupplierProfile::emptyAnswers();
        $any = false;

        foreach (SupplierProfile::fields() as $field) {
            $text = $cells[$field] ?? '';

            if ($text === '') {
                continue;
            }

            $any = true;

            if (in_array($field, SupplierProfile::ANSWER_FIELDS, true)) {
                $answers[$field] = $this->choiceCell($cells, $field, SupplierProfile::ANSWERS, 'procynia.supplier_management.profile.answers', $errors);
            } elseif (isset(SupplierProfile::CHOICE_FIELDS[$field])) {
                $answers[$field] = $this->choiceCell($cells, $field, SupplierProfile::CHOICE_FIELDS[$field], 'procynia.supplier_management.profile.'.($field === 'data_role' ? 'data_roles' : 'data_locations'), $errors);
            } else {
                $answers[$field] = $this->listCell($cells, $field, $errors);
            }
        }

        if (! $any) {
            return null;
        }

        // The questions asked depend on the criticality answers, exactly as on the supplier page.
        $supplier = (new Supplier)->forceFill($classification ?? array_fill_keys(Supplier::CRITICALITY_QUESTIONS, null));
        $visible = SupplierProfile::visibleFields($supplier, $answers);
        $hidden = array_values(array_filter(
            array_diff(SupplierProfile::fields(), $visible),
            fn (string $field): bool => $answers[$field] !== null,
        ));

        if ($hidden !== []) {
            $result['messages'][] = $this->notice('profile_fields_not_asked', ['columns' => $this->columnList($hidden)]);
        }

        $kept = SupplierProfile::emptyAnswers();

        foreach ($visible as $field) {
            $kept[$field] = $answers[$field];
        }

        $input = array_filter($kept, fn ($answer): bool => $answer !== null);

        if ($input === []) {
            return null;
        }

        // SupplierProfileService's own rules, as a last check of what would be written.
        if (Validator::make($input, SupplierProfileService::rules())->fails()) {
            $errors[] = $this->error('profile_invalid');

            return null;
        }

        return SupplierProfileService::answers($input);
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $codes
     * @param  list<array{type: string, text: string}>  $errors
     */
    private function choiceCell(array $cells, string $field, array $codes, string $labelKey, array &$errors): ?string
    {
        $text = $cells[$field] ?? '';

        if ($text === '') {
            return null;
        }

        $code = $this->values->choice($text, $codes, $labelKey);

        if ($code === null) {
            $errors[] = $this->error('unknown_value', [
                'column' => SupplierImportColumns::header($field),
                'value' => mb_substr($text, 0, 100),
                'allowed' => $this->allowed($codes, $labelKey),
            ]);
        }

        return $code;
    }

    /**
     * A multi-choice list: values separated by comma, semicolon or line break, each one of the codes.
     * «Ingen av disse» / «None of these» is the answer «none» ([]).
     *
     * @param  array<string, string>  $cells
     * @param  list<array{type: string, text: string}>  $errors
     * @return list<string>|null
     */
    private function listCell(array $cells, string $field, array &$errors): ?array
    {
        $codes = SupplierProfile::LIST_FIELDS[$field];
        $labelKey = "procynia.supplier_management.profile.{$field}";
        $none = array_map(
            fn (string $locale): string => SupplierImportColumns::normalize((string) __('procynia.supplier_management.profile.none_selected', [], $locale)),
            ['no', 'en'],
        );

        if (in_array(SupplierImportColumns::normalize($cells[$field]), [...$none, 'ingen', 'none'], true)) {
            return [];
        }

        $chosen = [];

        foreach (preg_split('/[,;\n]+/u', $cells[$field]) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $code = $this->values->choice($part, $codes, $labelKey);

            if ($code === null) {
                $errors[] = $this->error('unknown_value', ['column' => SupplierImportColumns::header($field), 'value' => mb_substr($part, 0, 100), 'allowed' => $this->allowed($codes, $labelKey)]);

                return null;
            }

            $chosen[] = $code;
        }

        return array_values(array_intersect($codes, $chosen));
    }

    /**
     * The intern ansvarlig, by e-mail address or by full name, among the person's own customer's
     * users only — and only someone who may be one (SupplierAccessService::isValidOwner()). Nobody
     * named on a new supplier is the person importing, when they may be one themselves.
     *
     * @param  Collection<int, User>  $users
     * @param  list<array{type: string, text: string}>  $errors
     */
    private function owner(User $actor, string $text, Collection $users, bool $defaultToActor, array &$errors): ?User
    {
        $customerId = (int) $actor->customer_id;

        if ($text === '') {
            if ($defaultToActor && $this->isValidOwner($actor, $customerId)) {
                return $actor;
            }

            $errors[] = $this->error('owner_required');

            return null;
        }

        $needle = mb_strtolower(trim($text));
        $owner = $users->first(fn (User $user): bool => mb_strtolower((string) $user->email) === $needle);

        if ($owner === null) {
            $byName = $users->filter(fn (User $user): bool => self::nameKey((string) $user->name) === self::nameKey($text));
            $owner = $byName->count() === 1 ? $byName->first() : null;
        }

        if ($owner === null) {
            $errors[] = $this->error('owner_not_found', ['value' => mb_substr($text, 0, 100)]);

            return null;
        }

        if (! $this->isValidOwner($owner, $customerId)) {
            $errors[] = $this->error('owner_not_allowed', ['name' => $owner->name]);

            return null;
        }

        return $owner;
    }

    private function isValidOwner(User $user, int $customerId): bool
    {
        return $this->validOwners[(int) $user->id] ??= $this->access->isValidOwner($user, $customerId);
    }

    /**
     * «Registrer leverandør»'s validation, with the import's column names in the messages.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     * @return list<array{type: string, text: string}>
     */
    private function validate(array $data, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $attributes = [];

        foreach (array_keys($rules) as $field) {
            $attributes[$field] = SupplierImportColumns::header($field === 'owner_user_id' ? 'owner' : $field);
        }

        $validator = Validator::make($data, $rules, SupplierValidationMessages::messages(), $attributes);

        return array_map(
            fn (string $message): array => ['type' => 'error', 'text' => $message],
            array_values(array_unique($validator->errors()->all())),
        );
    }

    /**
     * Norwegian organisation numbers are nine digits with a modulus-11 check digit, and one that fails
     * it is a typing error — refused, because the number is the supplier's identity here. A foreign
     * number keeps its own format: letters, digits, «.», «-» and «/».
     */
    public static function isValidOrganizationNumber(string $number): bool
    {
        if (mb_strlen($number) > 50 || preg_match('/^[\p{L}\p{N}.\-\/]+$/u', $number) !== 1 || preg_match('/\p{N}/u', $number) !== 1) {
            return false;
        }

        if (preg_match('/^\d{9}$/', $number) !== 1) {
            return true;
        }

        $weights = [3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += (int) $number[$index] * $weight;
        }

        $check = 11 - ($sum % 11);
        $check = $check === 11 ? 0 : $check;

        return $check !== 10 && $check === (int) $number[8];
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, mixed>
     */
    private function displayValues(array $cells, string $name, ?string $number): array
    {
        return [
            'name' => $name,
            'organization_number' => $number,
            'category' => null,
            'deliverable_description' => $cells['deliverable_description'] ?? null,
            'initial_status' => null,
            'owner' => null,
            'owner_text' => $cells['owner'] ?? null,
            'contact_name' => $cells['contact_name'] ?? null,
            'contact_email' => $cells['contact_email'] ?? null,
            'contact_phone' => $cells['contact_phone'] ?? null,
            'criticality' => null,
            'review_interval_months' => null,
            'profile_answers' => 0,
        ];
    }

    /** @return array{id: int, name: string, organization_number: string|null, status: string, url: string} */
    private function existingPayload(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
            'organization_number' => $supplier->organization_number,
            'status' => $supplier->status,
            'url' => route('app.supplier-management.show', ['supplierId' => $supplier->id], false),
        ];
    }

    /** @param  list<string>  $codes */
    private function allowed(array $codes, string $labelKey): string
    {
        return implode(', ', $this->values->labels($codes, $labelKey));
    }

    /** @param  list<string>  $fields */
    private function columnList(array $fields): string
    {
        return implode(', ', array_map(fn (string $field): string => '«'.SupplierImportColumns::header($field).'»', $fields));
    }

    /**
     * @param  array<string, string|int|null>  $parameters
     * @return array{type: string, text: string}
     */
    private function error(string $key, array $parameters = []): array
    {
        return ['type' => 'error', 'text' => (string) __("procynia.supplier_management.import.row_errors.{$key}", $parameters)];
    }

    /**
     * @param  array<string, string|int|null>  $parameters
     * @return array{type: string, text: string}
     */
    private function notice(string $key, array $parameters = []): array
    {
        return ['type' => 'notice', 'text' => (string) __("procynia.supplier_management.import.row_notices.{$key}", $parameters)];
    }
}

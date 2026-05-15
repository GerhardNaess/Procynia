<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationalDeviation extends Model
{
    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_OPERATION = 'drift';

    public const CATEGORY_DOCKER = 'docker';

    public const CATEGORY_DATABASE = 'database';

    public const CATEGORY_AI = 'ai';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_USER_EXPERIENCE = 'user_experience';

    public const CATEGORY_DOCUMENTATION = 'documentation';

    public const CATEGORY_TESTING = 'testing';

    public const CATEGORY_TECHNICAL_DEBT = 'technical_debt';

    public const CATEGORY_PRODUCT = 'product';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORY_INTEGRATIONS = 'integrations';

    public const CATEGORY_DOCUMENT_HANDLING = 'document_handling';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const STATUS_NEW = 'new';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_READY_FOR_VERIFICATION = 'ready_for_verification';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_POSTPONED = 'postponed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_for_verification_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
            'owner_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $deviation): void {
            if (! Schema::hasTable('operational_deviations')) {
                return;
            }

            $deviation->code = self::normalizeCode((string) $deviation->code);
            $deviation->category = self::normalizeValue((string) $deviation->category);
            $deviation->severity = self::normalizeValue((string) $deviation->severity);
            $deviation->status = self::normalizeValue((string) $deviation->status);

            if ($deviation->code === '') {
                throw ValidationException::withMessages([
                    'code' => __('procynia.operational_deviations.messages.code_required'),
                ]);
            }

            $normalizedCode = mb_strtolower($deviation->code, 'UTF-8');
            $codeExists = static::query()
                ->whereRaw('LOWER(code) = ?', [$normalizedCode]);

            if ($deviation->getKey() !== null) {
                $codeExists->whereKeyNot($deviation->getKey());
            }

            if ($codeExists->exists()) {
                throw ValidationException::withMessages([
                    'code' => __('procynia.operational_deviations.messages.code_exists'),
                ]);
            }

            $now = now();

            if ($deviation->isDirty('status')) {
                match ($deviation->status) {
                    self::STATUS_IN_PROGRESS => $deviation->started_at ??= $now,
                    self::STATUS_READY_FOR_VERIFICATION => $deviation->ready_for_verification_at ??= $now,
                    self::STATUS_VERIFIED => $deviation->verified_at ??= $now,
                    self::STATUS_CLOSED => $deviation->closed_at ??= $now,
                    default => null,
                };
            }

            $actorId = Auth::id();

            if ($actorId !== null) {
                if (! $deviation->exists && blank($deviation->created_by_user_id)) {
                    $deviation->created_by_user_id = $actorId;
                }

                $deviation->updated_by_user_id = $actorId;
            }
        });
    }

    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_SECURITY => __('procynia.operational_deviations.categories.security'),
            self::CATEGORY_OPERATION => __('procynia.operational_deviations.categories.operation'),
            self::CATEGORY_DOCKER => __('procynia.operational_deviations.categories.docker'),
            self::CATEGORY_DATABASE => __('procynia.operational_deviations.categories.database'),
            self::CATEGORY_AI => __('procynia.operational_deviations.categories.ai'),
            self::CATEGORY_BILLING => __('procynia.operational_deviations.categories.billing'),
            self::CATEGORY_USER_EXPERIENCE => __('procynia.operational_deviations.categories.user_experience'),
            self::CATEGORY_DOCUMENTATION => __('procynia.operational_deviations.categories.documentation'),
            self::CATEGORY_TESTING => __('procynia.operational_deviations.categories.testing'),
            self::CATEGORY_TECHNICAL_DEBT => __('procynia.operational_deviations.categories.technical_debt'),
            self::CATEGORY_PRODUCT => __('procynia.operational_deviations.categories.product'),
            self::CATEGORY_OTHER => __('procynia.operational_deviations.categories.other'),
            self::CATEGORY_INTEGRATIONS => __('procynia.operational_deviations.categories.integrations'),
            self::CATEGORY_DOCUMENT_HANDLING => __('procynia.operational_deviations.categories.document_handling'),
        ];
    }

    public static function severityOptions(): array
    {
        return [
            self::SEVERITY_CRITICAL => __('procynia.operational_deviations.severity.critical'),
            self::SEVERITY_HIGH => __('procynia.operational_deviations.severity.high'),
            self::SEVERITY_MEDIUM => __('procynia.operational_deviations.severity.medium'),
            self::SEVERITY_LOW => __('procynia.operational_deviations.severity.low'),
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => __('procynia.operational_deviations.status.new'),
            self::STATUS_PLANNED => __('procynia.operational_deviations.status.planned'),
            self::STATUS_IN_PROGRESS => __('procynia.operational_deviations.status.in_progress'),
            self::STATUS_READY_FOR_VERIFICATION => __('procynia.operational_deviations.status.ready_for_verification'),
            self::STATUS_VERIFIED => __('procynia.operational_deviations.status.verified'),
            self::STATUS_CLOSED => __('procynia.operational_deviations.status.closed'),
            self::STATUS_POSTPONED => __('procynia.operational_deviations.status.postponed'),
        ];
    }

    public static function categoryLabel(?string $category): string
    {
        $category = (string) $category;

        return self::categoryOptions()[$category] ?? Str::headline(str_replace('_', ' ', $category));
    }

    public static function severityLabel(?string $severity): string
    {
        $severity = (string) $severity;

        return self::severityOptions()[$severity] ?? Str::headline(str_replace('_', ' ', $severity));
    }

    public static function statusLabel(?string $status): string
    {
        $status = (string) $status;

        return self::statusOptions()[$status] ?? Str::headline(str_replace('_', ' ', $status));
    }

    public static function nextSuggestedCode(): string
    {
        if (! Schema::hasTable('operational_deviations')) {
            return 'AVVIK-001';
        }

        $max = static::query()
            ->pluck('code')
            ->reduce(function (int $carry, mixed $code): int {
                if (! is_string($code) || ! preg_match('/^AVVIK-(\d+)$/i', trim($code), $matches)) {
                    return $carry;
                }

                return max($carry, (int) $matches[1]);
            }, 0);

        return sprintf('AVVIK-%03d', $max + 1);
    }

    public static function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));

        if (preg_match('/^AVVIK-(\d+)$/', $normalized, $matches)) {
            return sprintf('AVVIK-%03d', (int) $matches[1]);
        }

        return $normalized;
    }

    public static function normalizeValue(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    public static function severityOrderExpression(string $column = 'severity'): string
    {
        return sprintf(
            "CASE %s WHEN '%s' THEN 0 WHEN '%s' THEN 1 WHEN '%s' THEN 2 WHEN '%s' THEN 3 ELSE 4 END",
            $column,
            self::SEVERITY_CRITICAL,
            self::SEVERITY_HIGH,
            self::SEVERITY_MEDIUM,
            self::SEVERITY_LOW,
        );
    }

    public static function openFirstOrderExpression(string $column = 'status'): string
    {
        return sprintf(
            "CASE WHEN %s = '%s' THEN 1 ELSE 0 END",
            $column,
            self::STATUS_CLOSED,
        );
    }
}

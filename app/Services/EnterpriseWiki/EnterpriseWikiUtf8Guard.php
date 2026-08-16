<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidUtf8Exception;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the Enterprise Wiki text invariant at AI boundaries: every string passed to, or
 * accepted from, the AI must be valid UTF-8. It deliberately does not use JSON replacement or
 * ignore flags: an unknown encoding must be rejected before it can weaken grounded evidence.
 *
 * Valid UTF-8 control characters are a separate content-policy concern and are not silently
 * removed here. This guard is only about malformed byte sequences.
 */
class EnterpriseWikiUtf8Guard
{
    public function assertValid(mixed $value, string $scope): void
    {
        $this->assertValue($value, $scope, '', null);
    }

    private function assertValue(mixed $value, string $scope, string $path, ?string $sourceElementKey): void
    {
        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                $diagnostic = $this->invalidByteDiagnostic($value);
                $exception = new EnterpriseWikiInvalidUtf8Exception(
                    scope: $scope,
                    fieldPath: $path === '' ? 'value' : $path,
                    byteLength: strlen($value),
                    invalidByteOffset: $diagnostic['offset'],
                    hexWindow: $diagnostic['hex_window'],
                    sourceElementKey: $sourceElementKey,
                );

                Log::warning('[PROCYNIA][ENTERPRISE_WIKI_UTF8] Invalid UTF-8 rejected.', [
                    'issue_code' => 'enterprise_wiki_invalid_utf8',
                    'scope' => $exception->scope,
                    'field_path' => $exception->fieldPath,
                    'source_element_key' => $exception->sourceElementKey,
                    'byte_length' => $exception->byteLength,
                    'invalid_byte_offset' => $exception->invalidByteOffset,
                    'hex_window' => $exception->hexWindow,
                    'normalization_outcome' => 'rejected',
                ]);

                throw $exception;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $currentSourceElementKey = is_string($value['source_element_key'] ?? null)
            ? $value['source_element_key']
            : $sourceElementKey;

        foreach ($value as $key => $child) {
            $childPath = $path === ''
                ? (string) $key
                : (is_int($key) ? $path."[{$key}]" : "{$path}.{$key}");

            $this->assertValue($child, $scope, $childPath, $currentSourceElementKey);
        }
    }

    /** @return array{offset: ?int, hex_window: ?string} */
    private function invalidByteDiagnostic(string $value): array
    {
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            $byte = ord($value[$offset]);

            if ($byte <= 0x7F) {
                $offset++;

                continue;
            }

            $width = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                default => 0,
            };

            if ($width === 0 || $offset + $width > $length) {
                return $this->diagnosticAt($value, $offset);
            }

            for ($index = 1; $index < $width; $index++) {
                if ((ord($value[$offset + $index]) & 0xC0) !== 0x80) {
                    return $this->diagnosticAt($value, $offset + $index);
                }
            }

            $second = ord($value[$offset + 1]);
            if (($byte === 0xE0 && $second < 0xA0)
                || ($byte === 0xED && $second > 0x9F)
                || ($byte === 0xF0 && $second < 0x90)
                || ($byte === 0xF4 && $second > 0x8F)) {
                return $this->diagnosticAt($value, $offset);
            }

            $offset += $width;
        }

        return ['offset' => null, 'hex_window' => null];
    }

    /** @return array{offset: int, hex_window: string} */
    private function diagnosticAt(string $value, int $offset): array
    {
        return [
            'offset' => $offset,
            'hex_window' => bin2hex(substr($value, max(0, $offset - 12), 28)),
        ];
    }
}

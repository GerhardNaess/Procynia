<?php

namespace App\Console\Commands;

use App\Models\CpvCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('cpv:import-catalog')]
#[Description('Import the canonical CPV catalog from database/data/cpv_codes.csv.')]
class ImportCpvCatalog extends Command
{
    public function handle(): int
    {
        $path = base_path('database/data/cpv_codes.csv');

        try {
            $rows = $this->readRows($path);
            $summary = $this->importRows($rows);

            $message = sprintf(
                '[DOFFIN][CPV] Import completed. created=%d updated=%d skipped=%d total=%d',
                $summary['created'],
                $summary['updated'],
                $summary['skipped'],
                count($rows),
            );

            $this->info($message);
            Log::info($message);

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $message = '[DOFFIN][CPV] Import failed: '.$throwable->getMessage();

            $this->error($message);
            Log::error($message);

            return self::FAILURE;
        }
    }

    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Catalog file was not found at {$path}.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Catalog file could not be opened: {$path}.");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('Catalog file is empty.');
        }

        $header = array_map(static fn ($value): string => trim((string) $value), $header);
        $required = ['code', 'description_en', 'description_no'];

        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                fclose($handle);

                throw new RuntimeException("Catalog header is missing required column [{$column}].");
            }
        }

        $indexes = array_flip($header);
        $rows = [];
        $seenCodes = [];
        $line = 1;

        while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if ($data === [null] || collect($data)->every(static fn ($value): bool => trim((string) $value) === '')) {
                continue;
            }

            $row = [
                'code' => trim((string) ($data[$indexes['code']] ?? '')),
                'description_en' => trim((string) ($data[$indexes['description_en']] ?? '')),
                'description_no' => trim((string) ($data[$indexes['description_no']] ?? '')),
            ];

            if ($row['code'] === '') {
                fclose($handle);

                throw new RuntimeException("Catalog row {$line} is missing code.");
            }

            if ($row['description_en'] === '' || $row['description_no'] === '') {
                fclose($handle);

                throw new RuntimeException("Catalog row {$line} for code {$row['code']} is missing one or both descriptions.");
            }

            if (isset($seenCodes[$row['code']])) {
                fclose($handle);

                throw new RuntimeException("Catalog row {$line} duplicates code {$row['code']}.");
            }

            $seenCodes[$row['code']] = true;
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function importRows(array $rows): array
    {
        $existing = CpvCode::query()
            ->select(['code', 'description_en', 'description_no'])
            ->get()
            ->keyBy('code');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $existing, &$created, &$updated, &$skipped): void {
            foreach ($rows as $row) {
                /** @var CpvCode|null $current */
                $current = $existing->get($row['code']);

                if ($current === null) {
                    CpvCode::query()->create($row);
                    $created++;

                    continue;
                }

                if (
                    $current->description_en === $row['description_en']
                    && $current->description_no === $row['description_no']
                ) {
                    $skipped++;

                    continue;
                }

                $current->update([
                    'description_en' => $row['description_en'],
                    'description_no' => $row['description_no'],
                ]);

                $updated++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }
}

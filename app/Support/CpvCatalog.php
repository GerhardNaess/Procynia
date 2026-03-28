<?php

namespace App\Support;

class CpvCatalog
{
    private ?array $englishMap = null;

    private ?array $norwegianMap = null;

    public function english(string $cpvCode): ?string
    {
        return $this->lookup($this->englishMap(), $cpvCode);
    }

    public function norwegian(string $cpvCode): ?string
    {
        return $this->lookup($this->norwegianMap(), $cpvCode);
    }

    private function lookup(array $map, string $cpvCode): ?string
    {
        $description = $map[$cpvCode] ?? null;

        if (! is_string($description)) {
            return null;
        }

        $description = trim($description);

        return $description !== '' ? $description : null;
    }

    private function englishMap(): array
    {
        if (is_array($this->englishMap)) {
            return $this->englishMap;
        }

        return $this->englishMap = $this->loadMap('cpv_codes.php');
    }

    private function norwegianMap(): array
    {
        if (is_array($this->norwegianMap)) {
            return $this->norwegianMap;
        }

        return $this->norwegianMap = $this->loadMap('cpv_codes_no.php');
    }

    private function loadMap(string $filename): array
    {
        $path = base_path("resources/data/{$filename}");

        if (! is_file($path)) {
            return [];
        }

        $map = require $path;

        return is_array($map) ? $map : [];
    }
}

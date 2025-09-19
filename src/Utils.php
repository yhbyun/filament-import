<?php

namespace Konnco\FilamentImport;

class Utils
{
    public static function getValidationSummary(array $validatedData): array
    {
        $totalCount = count($validatedData);
        $validCount = count(array_filter($validatedData, fn ($row) => $row['is_valid']));
        $invalidCount = $totalCount - $validCount;

        return [
            'total' => $totalCount,
            'valid' => $validCount,
            'invalid' => $invalidCount,
        ];
    }
}

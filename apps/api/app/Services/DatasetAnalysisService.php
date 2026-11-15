<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

class DatasetAnalysisService
{
    public function analyze(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $rows = $this->readCsv($path);
        } else {
            $rows = $this->readSpreadsheet($path);
        }

        if ($rows === []) {
            return [
                'row_count' => 0,
                'column_count' => 0,
                'columns' => [],
                'missing_values' => [],
                'duplicate_rows' => 0,
                'preview' => [],
                'data_types' => [],
            ];
        }

        $header = array_values($rows[0]);
        $columnNames = array_map(fn ($column) => is_string($column) ? trim($column) : (string) $column, $header);
        $dataRows = array_slice($rows, 1);

        $dataTypes = [];
        $missingValues = [];

        foreach ($columnNames as $index => $name) {
            $values = array_column($dataRows, $index);
            $nonEmpty = array_filter($values, fn ($value) => $value !== null && $value !== '' && $value !== '');
            $type = 'string';

            if ($nonEmpty !== []) {
                $allNumeric = true;
                foreach ($nonEmpty as $value) {
                    if (! is_numeric((string) $value)) {
                        $allNumeric = false;
                        break;
                    }
                }

                if ($allNumeric) {
                    $type = 'numeric';
                }
            }

            $missingValues[$name] = count(array_filter($values, fn ($value) => $value === null || $value === ''));
            $dataTypes[$name] = $type;
        }

        $duplicateRows = 0;
        $seen = [];
        foreach ($dataRows as $row) {
            $signature = json_encode($row);
            if (isset($seen[$signature])) {
                $duplicateRows++;

                continue;
            }
            $seen[$signature] = true;
        }

        $preview = array_slice($dataRows, 0, 10);

        return [
            'row_count' => count($dataRows),
            'column_count' => count($columnNames),
            'columns' => $columnNames,
            'missing_values' => $missingValues,
            'duplicate_rows' => $duplicateRows,
            'preview' => array_map(fn ($row) => array_combine($columnNames, $row), $preview),
            'data_types' => $dataTypes,
        ];
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $rows = [];

        if ($handle === false) {
            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function readSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        return array_values($rows);
    }
}

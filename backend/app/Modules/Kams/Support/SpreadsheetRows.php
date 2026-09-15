<?php

namespace App\Modules\Kams\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

class SpreadsheetRows
{
    /**
     * @return list<array<string, string>>
     */
    public static function fromUpload(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        abort_unless($path, 422, __('Unable to read the uploaded file.'));

        if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            return self::fromDelimited((string) file_get_contents($path), $extension === 'tsv' ? "\t" : null);
        }

        if (in_array($extension, ['xlsx', 'xlsm'], true)) {
            return self::fromXlsx($path);
        }

        abort(422, __('Save the spreadsheet as .xlsx or CSV, then import it again.'));
    }

    /**
     * @return list<array<string, string>>
     */
    public static function fromDelimited(string $text, ?string $delimiter = null): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '[')) {
            $parsed = json_decode($trimmed, true);

            return is_array($parsed) ? array_values(array_filter($parsed, 'is_array')) : [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));
        if ($lines === []) {
            return [];
        }

        $delimiter ??= str_contains($lines[0], "\t") ? "\t" : ',';
        $headers = self::splitLine($lines[0], $delimiter);
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            $cols = self::splitLine($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cols[$index] ?? '';
            }
            if (self::rowHasValue($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function fromXlsx(string $path): array
    {
        $zip = new ZipArchive();
        abort_unless($zip->open($path) === true, 422, __('Unable to read the Excel file.'));

        $strings = self::sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml')
            ?: $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        abort_unless(is_string($sheetXml) && $sheetXml !== '', 422, __('The Excel file has no readable sheet.'));

        $sheetXml = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $sheetXml) ?: $sheetXml;
        $sheet = simplexml_load_string($sheetXml);
        abort_unless($sheet !== false, 422, __('Unable to parse the Excel sheet.'));

        $grid = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $rowIndex = (int) $row['r'] - 1;
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                if (! preg_match('/^([A-Z]+)/', $ref, $match)) {
                    continue;
                }
                $grid[$rowIndex][self::columnIndex($match[1])] = self::cellValue($cell, $strings);
            }
        }

        if ($grid === []) {
            return [];
        }

        ksort($grid);
        $headerRow = array_shift($grid);
        ksort($headerRow);
        $headers = [];
        foreach ($headerRow as $index => $value) {
            $headers[$index] = trim($value);
        }

        $rows = [];
        foreach ($grid as $cells) {
            ksort($cells);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($cells[$index] ?? ''));
            }
            if (self::rowHasValue($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $xml = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $xml) ?: $xml;
        $parsed = simplexml_load_string($xml);
        if ($parsed === false) {
            return [];
        }

        $values = [];
        foreach ($parsed->si as $item) {
            $text = '';
            if (isset($item->t)) {
                $text = (string) $item->t;
            } else {
                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }
            }
            $values[] = $text;
        }

        return $values;
    }

    private static function cellValue(\SimpleXMLElement $cell, array $strings): string
    {
        $type = (string) $cell['t'];
        $raw = (string) ($cell->v ?? $cell->is->t ?? '');

        if ($type === 's') {
            return (string) ($strings[(int) $raw] ?? '');
        }

        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        return $raw;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * @return list<string>
     */
    private static function splitLine(string $line, string $delimiter): array
    {
        $parsed = str_getcsv($line, $delimiter);

        return array_map(static fn ($value) => trim((string) $value), $parsed);
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function rowHasValue(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}

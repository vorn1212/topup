<?php

namespace App\Services\Admin;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends a CSV file that is safe to open in Excel / Google Sheets.
 *
 * Spreadsheet "formula injection": a cell that starts with = + - or @ can run as a formula.
 * Text like that gets a leading apostrophe. Plain numbers (including negative ones) are left alone.
 */
class CsvExporter
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        if ($text === '') {
            return '';
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return $text;
        }

        if (in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<int,array<int|string,mixed>>  $rows
     */
    public function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');

            // Byte order mark so Excel reads UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_map([self::class, 'cell'], $header), ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], array_values($row)), ',', '"', '\\');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
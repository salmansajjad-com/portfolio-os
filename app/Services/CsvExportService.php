<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportService
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(static fn ($v) => static::neutralise((string) $v), $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map(static fn ($v) => static::neutralise($v === null ? '' : (string) $v), $row));
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Stop a spreadsheet treating exported text as a formula.
     *
     * Descriptions, notes and domains are user-supplied. A value that opens with
     * =, +, - or @ is executed on open by Excel and Sheets, which is how an
     * innocuous-looking expense note turns into a command on a colleague's
     * machine. Prefixing with a tab keeps the text readable while making the
     * cell unambiguously data.
     */
    public static function neutralise(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "\t".$value : $value;
    }
}

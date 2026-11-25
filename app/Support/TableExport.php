<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One place that turns a header row plus rows into a CSV or XLSX download. */
class TableExport
{
    public static function format(?string $requested): string
    {
        return in_array($requested, ['xlsx', 'pdf'], true) ? $requested : 'csv';
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     * @param  list<int>  $numeric  column indexes written as numbers in XLSX (amounts)
     */
    public static function stream(string $basename, string $format, array $headers, iterable $rows, array $numeric = []): StreamedResponse
    {
        $name = $basename.'-'.now()->format('Ymd-His').'.'.$format;

        if ($format === 'pdf') {
            return self::pdf($basename, $name, $headers, $rows, $numeric);
        }

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($headers, $rows, $numeric) {
                $w = new XlsxWriter;
                $w->openToFile('php://output');
                $w->addRow(Row::fromValues($headers));
                foreach ($rows as $r) {
                    $r = array_values($r);
                    foreach ($numeric as $i) {
                        if (isset($r[$i]) && is_numeric(str_replace(',', '', (string) $r[$i]))) {
                            $r[$i] = (float) str_replace(',', '', (string) $r[$i]);
                        }
                    }
                    $w->addRow(Row::fromValues($r));
                }
                $w->close();
            }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($headers, $rows) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, $headers);
            foreach ($rows as $r) {
                fputcsv($o, array_map([self::class, 'safeCell'], array_values($r)));
            }
            fclose($o);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** Neutralise spreadsheet formulas in user-controlled text (CSV injection). */
    private static function safeCell(mixed $v): mixed
    {
        if (is_string($v) && $v !== '' && ! is_numeric($v) && strpbrk($v[0], '=+-@	') !== false) {
            return "'".$v;
        }

        return $v;
    }

    public const PDF_MAX_ROWS = 2000;

    private static function pdf(string $basename, string $name, array $headers, iterable $rows, array $numeric): StreamedResponse
    {
        $kept = [];
        $total = 0;
        foreach ($rows as $r) {
            $total++;
            if ($total <= self::PDF_MAX_ROWS) {
                $kept[] = $r;
            }
        }
        $title = ucwords(str_replace(['-', '_'], ' ', $basename));
        $bytes = Pdf::loadView('exports.table', [
            'title' => $title, 'org' => Settings::get('org_name'), 'by' => request()->user()?->name, 'headers' => $headers,
            'rows' => $kept, 'total' => $total, 'numeric' => $numeric,
        ])->setPaper('a4', count($headers) > 6 ? 'landscape' : 'portrait')->output();

        return response()->streamDownload(fn () => print ($bytes), $name, ['Content-Type' => 'application/pdf']);
    }
}

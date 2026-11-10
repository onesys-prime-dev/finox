<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One place that turns a header row plus rows into a CSV or XLSX download. */
class TableExport
{
    public static function format(?string $requested): string
    {
        return $requested === 'xlsx' ? 'xlsx' : 'csv';
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     * @param  list<int>  $numeric  column indexes written as numbers in XLSX (amounts)
     */
    public static function stream(string $basename, string $format, array $headers, iterable $rows, array $numeric = []): StreamedResponse
    {
        $name = $basename.'-'.now()->format('Ymd-His').'.'.$format;

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
                fputcsv($o, array_values($r));
            }
            fclose($o);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}

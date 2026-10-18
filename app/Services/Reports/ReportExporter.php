<?php

namespace App\Services\Reports;

use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a ReportResult as CSV, XLSX or PDF (SRS FR-REP-003). Exports use the
 * exact result the screen shows for the same filters and user, so they carry
 * the same tenant, branch and role limits (FR-REP-006).
 */
class ReportExporter
{
    public function filename(Report $report, ReportFilters $f, string $ext): string
    {
        return Str::slug($report->title()) . '-' . $f->fromDate() . ($f->fromDate() !== $f->toDate() ? '-to-' . $f->toDate() : '') . '.' . $ext;
    }

    /** Header lines every export starts with. */
    public function heading(Report $report, ReportFilters $f, Tenant $tenant, array $filterLabels): array
    {
        $lines = [[$tenant->name . ' — ' . $report->title()], [$report->asOf() ? 'As of ' . now($f->tz)->format('j M Y, H:i') : 'Period: ' . $f->label()]];
        foreach ($filterLabels as $k => $v) {
            $lines[] = [$k . ': ' . $v];
        }
        $lines[] = ['Printed ' . now($f->tz)->format('j M Y, H:i')];

        return $lines;
    }

    public function csv(Report $report, ReportFilters $f, ReportResult $result, Tenant $tenant, array $filterLabels): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $f, $result, $tenant, $filterLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so Excel reads ₦ and accents correctly
            foreach ($this->heading($report, $f, $tenant, $filterLabels) as $line) {
                fputcsv($out, $line);
            }
            if ($result->cards) {
                fputcsv($out, []);
                fputcsv($out, ['Summary']);
                foreach ($result->cards as $c) {
                    fputcsv($out, [$c['label'], ReportFormat::raw($c['value'], $c['format']), $c['hint'] ?? '']);
                }
            }
            foreach ($result->tables as $t) {
                fputcsv($out, []);
                fputcsv($out, [$t['title']]);
                fputcsv($out, array_column($t['columns'], 'label'));
                foreach ($t['rows'] as $row) {
                    fputcsv($out, array_map(fn ($c) => $this->safe(ReportFormat::raw($row[$c['key']] ?? null, $c['format'])), $t['columns']));
                }
                if ($t['totals']) {
                    fputcsv($out, array_map(fn ($c) => $this->safe(ReportFormat::raw($t['totals'][$c['key']] ?? null, $c['format'])), $t['columns']));
                }
            }
            if ($result->notes) {
                fputcsv($out, []);
                foreach ($result->notes as $n) {
                    fputcsv($out, [$n]);
                }
            }
            fclose($out);
        }, $this->filename($report, $f, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function xlsx(Report $report, ReportFilters $f, ReportResult $result, Tenant $tenant, array $filterLabels): StreamedResponse
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator($tenant->name)->setTitle($report->title());
        $currency = \App\Support\Money::symbol();
        $formats = [
            'money' => '"' . $currency . '"#,##0.00;-"' . $currency . '"#,##0.00', 'int' => '#,##0', 'qty' => '#,##0.###',
            'percent' => '0.0"%"', 'days' => '0.0', 'date' => 'dd mmm yyyy',
        ];

        // Sheet 1: summary.
        $sheet = $book->getActiveSheet()->setTitle('Summary');
        $r = 1;
        foreach ($this->heading($report, $f, $tenant, $filterLabels) as $i => $line) {
            $sheet->setCellValue([1, $r], $line[0]);
            $i === 0 && $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(14);
            $r++;
        }
        $r++;
        foreach ($result->cards as $c) {
            $sheet->setCellValue([1, $r], $c['label']);
            $this->put($sheet, 2, $r, $c['value'], $c['format'], $formats);
            $sheet->setCellValue([3, $r], $c['hint'] ?? '');
            $sheet->getStyle([1, $r])->getFont()->setBold(true);
            $r++;
        }
        if ($result->notes) {
            $r++;
            foreach ($result->notes as $n) {
                $sheet->setCellValue([1, $r++], $n);
            }
        }
        foreach ([1, 2, 3] as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        // One sheet per table.
        $used = ['summary' => true];
        foreach ($result->tables as $t) {
            $name = $base = Str::limit(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $t['title']), 28, '');
            for ($n = 2; isset($used[strtolower($name)]); $n++) {
                $name = Str::limit($base, 25, '') . ' ' . $n;
            }
            $used[strtolower($name)] = true;
            $ws = $book->createSheet()->setTitle($name);
            foreach ($t['columns'] as $ci => $c) {
                $ws->setCellValue([$ci + 1, 1], $c['label'] ?: ' ');
            }
            $last = count($t['columns']);
            $ws->getStyle([1, 1, $last, 1])->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $ws->getStyle([1, 1, $last, 1])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A5F');
            $row = 2;
            foreach ($t['rows'] as $data) {
                foreach ($t['columns'] as $ci => $c) {
                    $this->put($ws, $ci + 1, $row, $data[$c['key']] ?? null, $c['format'], $formats);
                }
                ! empty($data['_strong']) && $ws->getStyle([1, $row, $last, $row])->getFont()->setBold(true);
                $row++;
            }
            if ($t['totals']) {
                foreach ($t['columns'] as $ci => $c) {
                    $this->put($ws, $ci + 1, $row, $t['totals'][$c['key']] ?? null, $c['format'], $formats);
                }
                $ws->getStyle([1, $row, $last, $row])->getFont()->setBold(true);
                $ws->getStyle([1, $row, $last, $row])->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            }
            for ($ci = 1; $ci <= $last; $ci++) {
                $ws->getColumnDimensionByColumn($ci)->setAutoSize(true);
            }
            $ws->freezePane('A2');
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $this->filename($report, $f, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(Report $report, ReportFilters $f, ReportResult $result, Tenant $tenant, array $filterLabels): Response
    {
        $wide = collect($result->tables)->max(fn ($t) => count($t['columns'])) > 6;
        $logo = null;
        if ($tenant->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($tenant->logo_path)) {
            $file = \Illuminate\Support\Facades\Storage::disk('public')->path($tenant->logo_path);
            $mime = mime_content_type($file) ?: 'image/png';
            $logo = in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) ? 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file)) : null;
        }

        return Pdf::loadView('reports.pdf', [
            'report' => $report, 'f' => $f, 'result' => $result, 'tenant' => $tenant, 'filterLabels' => $filterLabels, 'logo' => $logo,
        ])->setOption('isFontSubsettingEnabled', true)->setPaper('a4', $wide ? 'landscape' : 'portrait')->download($this->filename($report, $f, 'pdf'));
    }

    /** Keep spreadsheet apps from running text that looks like a formula. */
    protected function safe($value)
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value) ? "'" . $value : $value;
    }

    protected function put($sheet, int $col, int $row, $value, string $format, array $formats): void
    {
        $raw = ReportFormat::raw($value, $format);
        if ($raw === '' || $raw === null) {
            return;
        }
        if ($format === 'date' && is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            $sheet->setCellValue([$col, $row], \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime(substr($raw, 0, 10))));
        } elseif (is_numeric($raw) && $format !== 'text') {
            $sheet->setCellValue([$col, $row], $raw);
        } else {
            $sheet->setCellValueExplicit([$col, $row], (string) $this->safe($raw), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            return;
        }
        if (isset($formats[$format])) {
            $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode($formats[$format]);
        }
    }
}

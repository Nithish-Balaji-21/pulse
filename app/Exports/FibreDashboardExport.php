<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FibreDashboardExport implements FromCollection, WithColumnFormatting, WithEvents
{
    protected Collection $rows;

    protected string $dateLabel;

    protected int $totalBales;

    protected int $totalWeights;

    protected string $totalWeightFormatted;

    const HEADER_BG = 'FF2E4F7C';

    const HEADER_FG = 'FFFFFFFF';

    const ROW_EVEN = 'FFD6E4F0';

    const ROW_ODD = 'FFFFFFFF';

    const TOTAL_BG = 'FFFDE68A';

    public function __construct(Collection $rows, string $dateLabel, string $type, int $totalBales, int $totalWeights, string $totalWeightFormatted = '')
    {
        $this->rows = $rows;
        $this->dateLabel = $dateLabel;
        $this->totalBales = $totalBales;
        $this->totalWeights = $totalWeights;
        $this->totalWeightFormatted = $totalWeightFormatted;
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $dataRows = $this->rows->count() - 1;

                // Always 3 columns: Count | Bale Time | Weight Time & Weight
                $sheet->insertNewRowBefore(1, 2);

                // ── ROW 1: Title ──────────────────────────────────────────────
                $sheet->mergeCells('A1:D1');
                $sheet->setCellValue('A1', 'Fibre Production Report');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => self::HEADER_FG]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_BG]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(28);

                // ── ROW 2: Date label ─────────────────────────────────────────
                $sheet->mergeCells('A2:D2');
                $sheet->setCellValue('A2', $this->dateLabel);
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::HEADER_BG]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD6E8F8']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(18);

                // ── ROW 3: Column Headers ─────────────────────────────────────
                $sheet->getStyle('A3:D3')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => self::HEADER_FG]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_BG]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(3)->setRowHeight(22);

                // ── DATA ROWS: alternating colors ─────────────────────────────
                $lastDataRow = $dataRows + 3;
                for ($row = 4; $row <= $lastDataRow; $row++) {
                    $bg = ($row % 2 === 0) ? self::ROW_EVEN : self::ROW_ODD;
                    $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                        'font' => ['size' => 10],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    ]);
                    $sheet->getRowDimension($row)->setRowHeight(16);
                }

                // ── SUMMARY ROW ───────────────────────────────────────────────
                $summaryRow = $lastDataRow + 1;
                $sheet->setCellValue("A{$summaryRow}", 'Total');
                $sheet->setCellValue("B{$summaryRow}", $this->totalBales.' produced');
                $sheet->setCellValue("C{$summaryRow}", $this->totalWeights.' weighed');
                $sheet->setCellValue("D{$summaryRow}", $this->totalWeightFormatted);
                $sheet->getStyle("A{$summaryRow}:D{$summaryRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TOTAL_BG]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension($summaryRow)->setRowHeight(18);

                // ── BORDERS ───────────────────────────────────────────────────
                $sheet->getStyle("A1:D{$summaryRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'FF93B8D4'],
                        ],
                    ],
                ]);

                // ── COLUMN WIDTHS ─────────────────────────────────────────────
                $sheet->getColumnDimension('A')->setWidth(10);  // Count
                $sheet->getColumnDimension('B')->setWidth(28);  // Bale Time
                $sheet->getColumnDimension('C')->setWidth(28);  // Weight Time
                $sheet->getColumnDimension('D')->setWidth(16);

                // ── FREEZE header rows ────────────────────────────────────────
                $sheet->freezePane('A4');

                // Set initial cell as selected to avoid all cells selected when opening
                $sheet->setSelectedCell('A1');
            },
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_DATE_DATETIME,
        ];
    }
}

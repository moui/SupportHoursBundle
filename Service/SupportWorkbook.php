<?php

declare(strict_types=1);

/*
 * This file is part of the "SupportHoursBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\SupportHoursBundle\Service;

use KimaiPlugin\SupportHoursBundle\Model\SupportReport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One sheet per customer (date, user email, duration in hours, description,
 * then a total row) followed by a summary sheet sorted by hours. Sheet names
 * and headers are translated into the given locale.
 */
final class SupportWorkbook
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilename(SupportReport $report, string $locale): string
    {
        return 'support_hours_' . $report->getPeriod()->getSlug($locale) . '.xlsx';
    }

    /**
     * Writes the workbook to a temporary file and returns its path; the caller
     * deletes it.
     */
    public function write(SupportReport $report, string $locale): string
    {
        $t = fn (string $key): string => $this->translator->trans($key, [], 'messages', $locale);
        // The summary title is reserved first so no customer sheet can take it.
        $usedTitles = [];
        $summaryTitle = $this->sheetTitle($t('support_hours.workbook.summary'), $t('customer'), $usedTitles);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($report->getCustomers() as $customer) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($customer['name'], $t('customer'), $usedTitles));
            $sheet->fromArray([$t('date'), $t('support_hours.workbook.user_email'), $t('support_hours.workbook.duration_hours'), $t('description')]);

            $row = 2;
            foreach ($customer['entries'] as $entry) {
                $sheet->fromArray([
                    $entry->getDate()->format('Y-m-d'),
                    $entry->getUserEmail() ?? $entry->getUserName(),
                    $entry->getHours(),
                    $entry->getDescription(),
                ], null, 'A' . $row);
                $row++;
            }

            $totalRow = $row;
            $sheet->setCellValue('A' . $totalRow, $t('support_hours.workbook.total'));
            $sheet->setCellValue('C' . $totalRow, $customer['hours']);
            $this->finish($sheet, 'D', [1, $totalRow]);
        }

        $summary = $spreadsheet->createSheet();
        $summary->setTitle($summaryTitle);
        $summary->fromArray([$t('customer'), $t('support_hours.workbook.total_hours')]);
        $row = 2;
        foreach ($report->getCustomersByDuration() as $customer) {
            $summary->fromArray([$customer['name'], $customer['hours']], null, 'A' . $row);
            $row++;
        }
        $totalRow = $row;
        $summary->setCellValue('A' . $totalRow, $t('support_hours.workbook.grand_total'));
        $summary->setCellValue('B' . $totalRow, $report->getHours());
        $this->finish($summary, 'B', [1, $totalRow]);

        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'support_hours_');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @param list<int> $boldRows
     */
    private function finish(Worksheet $sheet, string $lastColumn, array $boldRows): void
    {
        foreach ($boldRows as $boldRow) {
            $sheet->getStyle('A' . $boldRow . ':' . $lastColumn . $boldRow)->getFont()->setBold(true);
        }

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        // Long descriptions would otherwise produce an unreadably wide column.
        if ($lastColumn === 'D') {
            $sheet->getColumnDimension('D')->setAutoSize(false)->setWidth(60);
            $sheet->getStyle('D:D')->getAlignment()->setWrapText(true);
        }
    }

    /**
     * Excel titles: max 31 chars, none of []:*?/\ , unique (case-insensitive).
     *
     * @param array<string, true> $used
     */
    private function sheetTitle(string $name, string $fallback, array &$used): string
    {
        $base = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $name), "' ");
        $base = mb_substr($base !== '' ? $base : $fallback, 0, 31);
        $title = $base;
        $suffix = 2;

        while (isset($used[mb_strtolower($title)]) || isset($used[$title])) {
            $tail = ' (' . $suffix++ . ')';
            $title = mb_substr($base, 0, 31 - mb_strlen($tail)) . $tail;
        }

        $used[mb_strtolower($title)] = true;

        return $title;
    }
}

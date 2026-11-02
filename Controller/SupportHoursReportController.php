<?php

declare(strict_types=1);

/*
 * This file is part of the "SupportHoursBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\SupportHoursBundle\Controller;

use App\Controller\AbstractController;
use KimaiPlugin\SupportHoursBundle\Model\Period;
use KimaiPlugin\SupportHoursBundle\Model\SupportEntry;
use KimaiPlugin\SupportHoursBundle\Model\SupportReport;
use KimaiPlugin\SupportHoursBundle\Service\SupportHoursRepository;
use KimaiPlugin\SupportHoursBundle\Service\SupportHoursSettings;
use KimaiPlugin\SupportHoursBundle\Service\SupportWorkbook;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/reporting/support-hours')]
#[IsGranted('view_reporting')]
#[IsGranted('support_hours_report')]
final class SupportHoursReportController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly SupportHoursRepository $repository,
        private readonly SupportWorkbook $workbook,
        private readonly SupportHoursSettings $settings,
    ) {
    }

    #[Route(path: '', name: 'support_hours_report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        // Without a criterion every query would come back empty; say why instead.
        if (!$this->settings->hasCriterion()) {
            return $this->render('@SupportHours/report.html.twig', [
                'report_title' => 'support_hours.title',
                'configured' => false,
            ]);
        }

        [$period, $error] = $this->resolvePeriod($request);
        $all = $this->repository->getReport($period);
        $selected = $this->selectedCustomers($request);
        $report = $this->filter($all, $selected);

        // Customer filter options come from the unfiltered period, so the list
        // only offers customers that actually have support hours.
        $customerOptions = array_map(static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $all->getCustomers());

        $pages = max(1, (int) ceil($report->getEntryCount() / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('@SupportHours/report.html.twig', [
            'report_title' => 'support_hours.title',
            'configured' => true,
            'report' => $report,
            'period' => $period,
            'period_label' => $period->getLabel($request->getLocale()),
            'error' => $error,
            'customer_options' => $customerOptions,
            'selected_customers' => $selected,
            'query' => $this->exportQuery($period, $selected),
            'page_entries' => \array_slice($report->getEntries(), ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
            'first_index' => ($page - 1) * self::PER_PAGE + 1,
        ]);
    }

    #[Route(path: '/export', name: 'support_hours_report_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        [$period] = $this->resolvePeriod($request);
        $report = $this->filter($this->repository->getReport($period), $this->selectedCustomers($request));

        $response = new BinaryFileResponse($this->workbook->write($report, $request->getLocale()));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->workbook->getFilename($report, $request->getLocale()));
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * `month=YYYY-MM` or `from`/`to`; defaults to the previous month, the
     * period the monthly export covers. The error is a translation key.
     *
     * @return array{0: Period, 1: string|null}
     */
    private function resolvePeriod(Request $request): array
    {
        $timezone = new \DateTimeZone($this->getUser()->getTimezone());
        $fallback = Period::lastMonth(new \DateTimeImmutable('today', $timezone));
        $month = (string) $request->query->get('month', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');

        if ($from !== '' || $to !== '') {
            try {
                $period = Period::fromStrings($from, $to, $timezone);
            } catch (\InvalidArgumentException) {
                return [$fallback, 'support_hours.error.reversed_range'];
            }

            return $period === null ? [$fallback, 'support_hours.error.invalid_range'] : [$period, null];
        }

        if ($month !== '') {
            $period = Period::fromMonthString($month, $timezone);

            return $period === null ? [$fallback, 'support_hours.error.invalid_month'] : [$period, null];
        }

        return [$fallback, null];
    }

    /**
     * @return list<int>
     */
    private function selectedCustomers(Request $request): array
    {
        $values = $request->query->all()['customers'] ?? [];

        return array_values(array_unique(array_map('intval', array_filter((array) $values, static fn (mixed $v): bool => \is_string($v) && ctype_digit($v)))));
    }

    /**
     * @param list<int> $customers
     */
    private function filter(SupportReport $report, array $customers): SupportReport
    {
        if (\count($customers) === 0) {
            return $report;
        }

        return new SupportReport($report->getPeriod(), array_values(array_filter(
            $report->getEntries(),
            static fn (SupportEntry $entry): bool => \in_array($entry->getCustomerId(), $customers, true)
        )));
    }

    /**
     * @param list<int> $customers
     * @return array<string, mixed>
     */
    private function exportQuery(Period $period, array $customers): array
    {
        $query = $period->isWholeMonth()
            ? ['month' => $period->getFrom()->format('Y-m')]
            : ['from' => $period->getFrom()->format('Y-m-d'), 'to' => $period->getTo()->format('Y-m-d')];

        return $query + ['customers' => $customers];
    }
}

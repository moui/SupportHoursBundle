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

use App\Repository\TimesheetRepository;
use KimaiPlugin\SupportHoursBundle\Model\Period;
use KimaiPlugin\SupportHoursBundle\Model\SupportEntry;
use KimaiPlugin\SupportHoursBundle\Model\SupportReport;

/**
 * Reads support entries through Doctrine (DQL), not raw SQL.
 *
 * Support = a project whose name is in the settings, a project picked
 * individually, or any project of a selected customer. Without any of these
 * rules nothing matches. Entries of disabled users and running timesheets are
 * left out, and days are the timesheet's local date (date_tz) rather than the
 * UTC start time, so an entry never moves to another month.
 */
final class SupportHoursRepository
{
    public function __construct(
        private readonly TimesheetRepository $timesheets,
        private readonly SupportHoursSettings $settings,
    ) {
    }

    /**
     * @param list<int> $customerFilter limit to these customers; empty = all
     */
    public function getReport(Period $period, array $customerFilter = []): SupportReport
    {
        $projectNames = $this->settings->getProjectNames();
        $projectIds = $this->settings->getProjectIds();
        $customerIds = $this->settings->getCustomerIds();

        if (\count($projectNames) === 0 && \count($projectIds) === 0 && \count($customerIds) === 0) {
            return new SupportReport($period, []);
        }

        $qb = $this->timesheets->createQueryBuilder('t');
        $support = $qb->expr()->orX();
        if (\count($projectNames) > 0) {
            $support->add($qb->expr()->in('p.name', ':projectNames'));
            $qb->setParameter('projectNames', $projectNames);
        }
        if (\count($projectIds) > 0) {
            $support->add($qb->expr()->in('p.id', ':projectIds'));
            $qb->setParameter('projectIds', $projectIds);
        }
        if (\count($customerIds) > 0) {
            $support->add($qb->expr()->in('c.id', ':customerIds'));
            $qb->setParameter('customerIds', $customerIds);
        }

        $qb
            ->select('t.date AS day', 't.duration AS duration', 't.description AS description')
            ->addSelect('c.id AS customerId', 'c.name AS customerName', 'p.name AS projectName')
            ->addSelect('u.alias AS alias', 'u.username AS username', 'u.email AS email')
            ->join('t.project', 'p')
            ->join('p.customer', 'c')
            ->join('t.user', 'u')
            ->where($support)
            ->andWhere($qb->expr()->eq('u.enabled', ':enabled'))
            ->andWhere($qb->expr()->isNotNull('t.end'))
            ->andWhere($qb->expr()->between('t.date', ':from', ':to'))
            ->setParameter('enabled', true)
            ->setParameter('from', $period->getFrom()->format('Y-m-d'))
            ->setParameter('to', $period->getTo()->format('Y-m-d'))
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('t.begin', 'ASC')
        ;

        if (\count($customerFilter) > 0) {
            $qb->andWhere($qb->expr()->in('c.id', ':customerFilter'))->setParameter('customerFilter', $customerFilter);
        }

        /** @var list<array{day: \DateTimeInterface, duration: int|string|null, description: string|null, customerId: int|string, customerName: string, projectName: string, alias: string|null, username: string, email: string|null}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $entries = [];
        foreach ($rows as $row) {
            $alias = trim((string) ($row['alias'] ?? ''));
            $entries[] = new SupportEntry(
                \DateTimeImmutable::createFromInterface($row['day']),
                (int) $row['customerId'],
                (string) $row['customerName'],
                (string) $row['projectName'],
                $alias !== '' ? $alias : (string) $row['username'],
                $row['email'],
                (int) $row['duration'],
                trim((string) ($row['description'] ?? '')),
            );
        }

        return new SupportReport($period, $entries);
    }
}

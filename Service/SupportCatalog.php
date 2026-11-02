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

use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;

/**
 * Projects and customers for the settings pickers, and the list of projects
 * the current criterion matches (shown as a preview on the settings page).
 */
final class SupportCatalog
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly CustomerRepository $customers,
        private readonly SupportHoursSettings $settings,
    ) {
    }

    /**
     * Every project as "<project> [<customer>]", hidden ones flagged so a
     * project that was archived after being picked stays visible in the list.
     *
     * @return list<array{id: int, label: string, customer: string, visible: bool}>
     */
    public function getProjectOptions(): array
    {
        /** @var list<array{id: int|string, name: string, projectVisible: bool, customer: string, customerVisible: bool}> $rows */
        $rows = $this->projects->createQueryBuilder('p')
            ->select('p.id AS id', 'p.name AS name', 'p.visible AS projectVisible', 'c.name AS customer', 'c.visible AS customerVisible')
            ->join('p.customer', 'c')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => $row['name'] . ' [' . $row['customer'] . ']',
            'customer' => (string) $row['customer'],
            'visible' => (bool) $row['projectVisible'] && (bool) $row['customerVisible'],
        ], $rows);
    }

    /**
     * @return list<array{id: int, label: string, visible: bool}>
     */
    public function getCustomerOptions(): array
    {
        /** @var list<array{id: int|string, name: string, visible: bool}> $rows */
        $rows = $this->customers->createQueryBuilder('c')
            ->select('c.id AS id', 'c.name AS name', 'c.visible AS visible')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['name'],
            'visible' => (bool) $row['visible'],
        ], $rows);
    }

    /**
     * Projects the saved criterion currently counts as support, with the
     * reason(s) each one matches (as translation keys).
     *
     * @return list<array{label: string, reasons: list<string>, visible: bool}>
     */
    public function getMatchingProjects(): array
    {
        $names = array_map('mb_strtolower', $this->settings->getProjectNames());
        $projectIds = $this->settings->getProjectIds();
        $customerIds = $this->settings->getCustomerIds();

        /** @var list<array{id: int|string, name: string, projectVisible: bool, customerId: int|string, customer: string, customerVisible: bool}> $rows */
        $rows = $this->projects->createQueryBuilder('p')
            ->select('p.id AS id', 'p.name AS name', 'p.visible AS projectVisible', 'c.id AS customerId', 'c.name AS customer', 'c.visible AS customerVisible')
            ->join('p.customer', 'c')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $matches = [];
        foreach ($rows as $row) {
            $reasons = [];
            if (\in_array(mb_strtolower((string) $row['name']), $names, true)) {
                $reasons[] = 'support_hours.settings.reason.name';
            }
            if (\in_array((int) $row['id'], $projectIds, true)) {
                $reasons[] = 'support_hours.settings.reason.project';
            }
            if (\in_array((int) $row['customerId'], $customerIds, true)) {
                $reasons[] = 'support_hours.settings.reason.customer';
            }
            if ($reasons !== []) {
                $matches[] = [
                    'label' => $row['name'] . ' [' . $row['customer'] . ']',
                    'reasons' => $reasons,
                    'visible' => (bool) $row['projectVisible'] && (bool) $row['customerVisible'],
                ];
            }
        }

        return $matches;
    }
}

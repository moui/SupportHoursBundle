<?php

declare(strict_types=1);

/*
 * This file is part of the "SupportHoursBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\SupportHoursBundle\Model;

/**
 * Support entries for one period, grouped by customer.
 *
 * Formatting lives in PHP on purpose: Twig macros imported in a block are
 * out of scope inside `{% embed %}`.
 */
final class SupportReport
{
    /** @var array<int, array{id: int, name: string, entries: list<SupportEntry>, duration: int}> */
    private array $customers = [];
    private int $duration = 0;
    /** @var array<string, true> */
    private array $users = [];

    /**
     * @param list<SupportEntry> $entries ordered by customer name, then date
     */
    public function __construct(private readonly Period $period, private readonly array $entries)
    {
        foreach ($entries as $entry) {
            $id = $entry->getCustomerId();
            $this->customers[$id] ??= ['id' => $id, 'name' => $entry->getCustomerName(), 'entries' => [], 'duration' => 0];
            $this->customers[$id]['entries'][] = $entry;
            $this->customers[$id]['duration'] += $entry->getDuration();
            $this->duration += $entry->getDuration();
            $this->users[$entry->getUserName()] = true;
        }
    }

    public function getPeriod(): Period
    {
        return $this->period;
    }

    /**
     * @return list<SupportEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<array{id: int, name: string, entries: list<SupportEntry>, duration: int, durationFormatted: string, hours: float}>
     */
    public function getCustomers(): array
    {
        return array_values(array_map(static fn (array $customer): array => $customer + [
            'durationFormatted' => self::formatDuration($customer['duration']),
            'hours' => self::toHours($customer['duration']),
        ], $this->customers));
    }

    /**
     * Customers by total hours, largest first — the order of the workbook's summary sheet.
     *
     * @return list<array{id: int, name: string, entries: list<SupportEntry>, duration: int, durationFormatted: string, hours: float}>
     */
    public function getCustomersByDuration(): array
    {
        $customers = $this->getCustomers();
        usort($customers, static fn (array $a, array $b): int => $b['duration'] <=> $a['duration']);

        return $customers;
    }

    public function isEmpty(): bool
    {
        return \count($this->entries) === 0;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function getDurationFormatted(): string
    {
        return self::formatDuration($this->duration);
    }

    public function getHours(): float
    {
        return self::toHours($this->duration);
    }

    public function getEntryCount(): int
    {
        return \count($this->entries);
    }

    public function getCustomerCount(): int
    {
        return \count($this->customers);
    }

    public function getUserCount(): int
    {
        return \count($this->users);
    }

    public static function formatDuration(int $seconds): string
    {
        return \sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    public static function toHours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }
}

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
 * An inclusive range of calendar days, matched against the timesheet's local
 * date (date_tz), so an entry never moves to another day because of timezones.
 */
final class Period
{
    private function __construct(
        private readonly \DateTimeImmutable $from,
        private readonly \DateTimeImmutable $to,
        private readonly bool $wholeMonth,
    ) {
    }

    public static function month(\DateTimeImmutable $anyDayInMonth): self
    {
        $first = $anyDayInMonth->modify('first day of this month')->setTime(0, 0);

        return new self($first, $first->modify('last day of this month'), true);
    }

    public static function lastMonth(\DateTimeImmutable $today): self
    {
        return self::month($today->modify('first day of last month'));
    }

    /**
     * Parses YYYY-MM; returns null when malformed.
     */
    public static function fromMonthString(string $month, \DateTimeZone $timezone): ?self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month, $timezone);

        if ($date === false || $date->format('Y-m') !== $month) {
            return null;
        }

        return self::month($date);
    }

    /**
     * Parses two Y-m-d strings; returns null when malformed.
     *
     * @throws \InvalidArgumentException when the range is reversed
     */
    public static function fromStrings(string $from, string $to, \DateTimeZone $timezone): ?self
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $timezone);

        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            return null;
        }

        if ($start > $end) {
            throw new \InvalidArgumentException('The start date must not be after the end date.');
        }

        $isMonth = $start->format('d') === '01' && $end->format('Y-m-d') === $start->modify('last day of this month')->format('Y-m-d');

        return new self($start, $end, $isMonth);
    }

    public function getFrom(): \DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function isWholeMonth(): bool
    {
        return $this->wholeMonth;
    }

    /**
     * "September 2026" / "Septiembre 2026" for a whole month, otherwise the
     * date range, both in the given locale.
     */
    public function getLabel(string $locale): string
    {
        if ($this->wholeMonth) {
            $month = self::format($this->from, $locale, \IntlDateFormatter::NONE, 'LLLL yyyy');

            return mb_strtoupper(mb_substr($month, 0, 1)) . mb_substr($month, 1);
        }

        return self::format($this->from, $locale, \IntlDateFormatter::MEDIUM) . ' – ' . self::format($this->to, $locale, \IntlDateFormatter::MEDIUM);
    }

    /**
     * Filename-safe form: "September_2026" (month name in the given locale) or
     * "2026-09-01_to_2026-09-15".
     */
    public function getSlug(string $locale): string
    {
        if ($this->wholeMonth) {
            return str_replace(' ', '_', $this->getLabel($locale));
        }

        return $this->from->format('Y-m-d') . '_to_' . $this->to->format('Y-m-d');
    }

    private static function format(\DateTimeImmutable $date, string $locale, int $dateType, ?string $pattern = null): string
    {
        $formatter = new \IntlDateFormatter($locale, $dateType, \IntlDateFormatter::NONE, $date->getTimezone(), null, $pattern);
        $formatted = $formatter->format($date);

        return \is_string($formatted) ? $formatted : $date->format('Y-m-d');
    }
}

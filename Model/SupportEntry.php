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

final class SupportEntry
{
    public function __construct(
        private readonly \DateTimeImmutable $date,
        private readonly int $customerId,
        private readonly string $customerName,
        private readonly string $projectName,
        private readonly string $userName,
        private readonly ?string $userEmail,
        private readonly int $duration,
        private readonly string $description,
    ) {
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function getProjectName(): string
    {
        return $this->projectName;
    }

    /**
     * "<project> [<customer>]".
     */
    public function getProjectLabel(): string
    {
        return $this->projectName . ' [' . $this->customerName . ']';
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getUserEmail(): ?string
    {
        return $this->userEmail;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function getHours(): float
    {
        return SupportReport::toHours($this->duration);
    }

    public function getDurationFormatted(): string
    {
        return SupportReport::formatDuration($this->duration);
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}

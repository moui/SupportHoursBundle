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

use App\Configuration\ConfigurationService;
use App\Configuration\SystemConfiguration;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;

/**
 * The plugin's settings, edited on Reporting → Support hours → Settings and
 * stored by Kimai in kimai2_configuration as support_hours.* (each value a
 * comma-separated list).
 *
 * A key without a saved row falls back to the defaults registered in
 * SupportHoursExtension; a saved empty value means "none".
 */
final class SupportHoursSettings
{
    public const RECIPIENTS = 'support_hours.recipients';
    public const PROJECT_NAMES = 'support_hours.project_names';
    public const PROJECT_IDS = 'support_hours.project_ids';
    public const CUSTOMER_IDS = 'support_hours.customer_ids';

    public function __construct(
        private readonly SystemConfiguration $configuration,
        private readonly ConfigurationService $store,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getRecipients(): array
    {
        return array_values(array_filter($this->getList(self::RECIPIENTS), self::isEmail(...)));
    }

    /**
     * Any project with one of these names counts, for every customer — new
     * projects with that name are picked up without touching the settings.
     *
     * @return list<string>
     */
    public function getProjectNames(): array
    {
        return $this->getList(self::PROJECT_NAMES);
    }

    /**
     * Individual projects added from the picker.
     *
     * @return list<int>
     */
    public function getProjectIds(): array
    {
        return self::toIds($this->getList(self::PROJECT_IDS));
    }

    /**
     * Customers whose every project counts as support.
     *
     * @return list<int>
     */
    public function getCustomerIds(): array
    {
        return self::toIds($this->getList(self::CUSTOMER_IDS));
    }

    /**
     * False until at least one rule (project name, project or customer) is
     * set: without a criterion nothing counts as support.
     */
    public function hasCriterion(): bool
    {
        return $this->getProjectNames() !== [] || $this->getProjectIds() !== [] || $this->getCustomerIds() !== [];
    }

    /**
     * @param list<string> $recipients
     * @param list<string> $projectNames
     * @param list<int> $projectIds
     * @param list<int> $customerIds
     */
    public function save(array $recipients, array $projectNames, array $projectIds, array $customerIds): void
    {
        $values = [
            self::RECIPIENTS => $recipients,
            self::PROJECT_NAMES => $projectNames,
            self::PROJECT_IDS => $projectIds,
            self::CUSTOMER_IDS => $customerIds,
        ];

        $model = new SystemConfigurationModel('support_hours');
        foreach ($values as $key => $list) {
            // An empty string, not null: Kimai deletes the row for null, which
            // would silently bring the default back.
            $value = implode(',', array_unique(array_map('strval', $list)));
            $model->addConfiguration((new Configuration($key))->setValue($value));
            $this->configuration->set($key, $value);
        }

        // Through the service, not the repository: it also drops Kimai's
        // one-day "configurations" cache, otherwise the new values would only
        // show up after the cache expires.
        $this->store->saveSystemConfiguration($model);
    }

    public static function isEmail(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Splits on commas, semicolons and line breaks, trims, drops empties.
     *
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        $items = preg_split('/[,;\r\n]+/', $value);
        if ($items === false) {
            $items = [];
        }

        return array_values(array_unique(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private static function toIds(array $values): array
    {
        return array_values(array_unique(array_map('intval', array_filter($values, 'ctype_digit'))));
    }

    /**
     * @return list<string>
     */
    private function getList(string $key): array
    {
        return self::splitList((string) ($this->configuration->find($key) ?? ''));
    }
}

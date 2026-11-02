<?php

declare(strict_types=1);

/*
 * This file is part of the "SupportHoursBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\SupportHoursBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class SupportHoursExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Defaults for the settings edited on the report's "Settings" page
        // (keys support_hours.*). Saved values live in kimai2_configuration and
        // override these. No criterion by default: the report asks for one to
        // be configured instead of guessing what counts as support.
        $this->registerBundleConfiguration($container, [
            'recipients' => '',
            'project_names' => '',
            'project_ids' => '',
            'customer_ids' => '',
        ]);
    }

    /**
     * The report lists every customer's support entries with descriptions, so
     * it starts admin-only. Other roles can be granted `support_hours_report`
     * (view) or `support_hours_settings` (change recipients and criterion)
     * from Kimai's role permission screen.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_ADMIN' => ['support_hours_report', 'support_hours_settings'],
                    'ROLE_SUPER_ADMIN' => ['support_hours_report', 'support_hours_settings'],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'support_hours';
    }
}

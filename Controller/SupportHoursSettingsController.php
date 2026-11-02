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
use KimaiPlugin\SupportHoursBundle\Service\SupportCatalog;
use KimaiPlugin\SupportHoursBundle\Service\SupportHoursSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recipients of the monthly report and what counts as support, saved through
 * Kimai's configuration table.
 */
#[Route(path: '/reporting/support-hours/settings')]
#[IsGranted('view_reporting')]
#[IsGranted('support_hours_settings')]
final class SupportHoursSettingsController extends AbstractController
{
    public function __construct(
        private readonly SupportHoursSettings $settings,
        private readonly SupportCatalog $catalog,
    ) {
    }

    #[Route(path: '', name: 'support_hours_settings', methods: ['GET'])]
    public function edit(): Response
    {
        return $this->renderSettings([
            'recipients' => implode("\n", $this->settings->getRecipients()),
            'project_names' => implode(', ', $this->settings->getProjectNames()),
            'project_ids' => $this->settings->getProjectIds(),
            'customer_ids' => $this->settings->getCustomerIds(),
        ], []);
    }

    #[Route(path: '', name: 'support_hours_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('support_hours_settings', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('support_hours_settings');
        }

        $posted = $request->request->all();
        $recipients = SupportHoursSettings::splitList(self::text($posted['recipients'] ?? ''));
        $projectNames = SupportHoursSettings::splitList(self::text($posted['project_names'] ?? ''));
        $projectIds = $this->ids($posted['project_ids'] ?? []);
        $customerIds = $this->ids($posted['customer_ids'] ?? []);
        $errors = [];

        $invalid = array_filter($recipients, static fn (string $email): bool => !SupportHoursSettings::isEmail($email));
        if ($invalid !== []) {
            $errors[] = ['key' => 'support_hours.settings.error.invalid_emails', 'params' => ['%emails%' => implode(', ', $invalid)]];
        }

        // Re-validate posted ids against what exists, so a crafted id cannot be stored.
        $knownProjects = array_column($this->catalog->getProjectOptions(), 'id');
        $knownCustomers = array_column($this->catalog->getCustomerOptions(), 'id');
        if (array_diff($projectIds, $knownProjects) !== [] || array_diff($customerIds, $knownCustomers) !== []) {
            $errors[] = ['key' => 'support_hours.settings.error.unknown_ids', 'params' => []];
        }

        if ($projectNames === [] && $projectIds === [] && $customerIds === []) {
            $errors[] = ['key' => 'support_hours.settings.error.no_criterion', 'params' => []];
        }

        $values = [
            'recipients' => implode("\n", $recipients),
            'project_names' => implode(', ', $projectNames),
            'project_ids' => $projectIds,
            'customer_ids' => $customerIds,
        ];

        if ($errors !== []) {
            return $this->renderSettings($values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settings->save($recipients, $projectNames, $projectIds, $customerIds);
        $this->flashSuccess('action.update.success');

        return $this->redirectToRoute('support_hours_settings');
    }

    /**
     * @param array{recipients: string, project_names: string, project_ids: list<int>, customer_ids: list<int>} $values
     * @param list<array{key: string, params: array<string, string>}> $errors translation keys and parameters
     */
    private function renderSettings(array $values, array $errors, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('@SupportHours/settings.html.twig', [
            'report_title' => 'support_hours.title',
            'values' => $values,
            'errors' => $errors,
            'project_options' => $this->catalog->getProjectOptions(),
            'customer_options' => $this->catalog->getCustomerOptions(),
            'matching_projects' => $this->catalog->getMatchingProjects(),
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $values): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $values, static fn (mixed $v): bool => \is_string($v) && ctype_digit($v)))));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}

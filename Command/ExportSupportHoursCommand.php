<?php

declare(strict_types=1);

/*
 * This file is part of the "SupportHoursBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\SupportHoursBundle\Command;

use App\Configuration\SystemConfiguration;
use App\Mail\KimaiMailer;
use KimaiPlugin\SupportHoursBundle\Model\Period;
use KimaiPlugin\SupportHoursBundle\Service\SupportHoursRepository;
use KimaiPlugin\SupportHoursBundle\Service\SupportHoursSettings;
use KimaiPlugin\SupportHoursBundle\Service\SupportWorkbook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the per-customer workbook for a period (previous month by default)
 * and optionally emails it. Recipients are arbitrary addresses, so the
 * workbook and the email use Kimai's default language.
 */
#[AsCommand(
    name: 'kimai:support-hours:export',
    description: 'Export support hours to an Excel workbook (one sheet per customer) and optionally email it'
)]
final class ExportSupportHoursCommand extends Command
{
    public function __construct(
        private readonly SupportHoursRepository $repository,
        private readonly SupportWorkbook $workbook,
        private readonly KimaiMailer $mailer,
        private readonly SupportHoursSettings $settings,
        private readonly TranslatorInterface $translator,
        private readonly SystemConfiguration $systemConfiguration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Month to export, YYYY-MM (default: previous month)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day, YYYY-MM-DD (with --to; overrides --month)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day, YYYY-MM-DD')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Directory to write the .xlsx into (omit to skip writing a file)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Recipient (repeatable); defaults to the recipients saved on Reporting → Support hours → Settings')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Actually send the email; without it the email is only previewed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $from = self::stringOption($input, 'from');
        $to = self::stringOption($input, 'to');
        $month = self::stringOption($input, 'month');

        try {
            $period = match (true) {
                $from !== '' || $to !== '' => Period::fromStrings($from, $to, $timezone),
                $month !== '' => Period::fromMonthString($month, $timezone),
                default => Period::lastMonth(new \DateTimeImmutable('today', $timezone)),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($period === null) {
            $io->error('Use --month=YYYY-MM, or both --from and --to as YYYY-MM-DD.');

            return Command::INVALID;
        }

        if (!$this->settings->hasCriterion()) {
            $io->warning('No support criterion is configured, so nothing counts as support. Set one on Reporting → Support hours → Settings.');

            return Command::SUCCESS;
        }

        $locale = $this->systemConfiguration->getUserDefaultLanguage();
        $report = $this->repository->getReport($period);
        $io->title('Support hours — ' . $period->getLabel('en'));
        $io->table(['Customer', 'Entries', 'Hours'], array_map(
            static fn (array $c): array => [$c['name'], \count($c['entries']), number_format($c['hours'], 2, '.', '')],
            $report->getCustomersByDuration()
        ));
        $io->writeln(\sprintf('Grand total: %s hours in %d entries.', number_format($report->getHours(), 2, '.', ''), $report->getEntryCount()));

        if ($report->isEmpty()) {
            $io->warning('No support hours found for this period; no file or email is created.');

            return Command::SUCCESS;
        }

        $temporary = $this->workbook->write($report, $locale);
        $filename = $this->workbook->getFilename($report, $locale);

        try {
            $directory = self::stringOption($input, 'output');
            if ($directory !== '') {
                $target = rtrim($directory, '/') . '/' . $filename;
                if (!is_dir($directory) || !copy($temporary, $target)) {
                    $io->error('Could not write ' . $target);

                    return Command::FAILURE;
                }
                $io->success('Excel file written: ' . $target);
            }

            $recipients = [];
            foreach ((array) $input->getOption('email') as $recipient) {
                if (\is_string($recipient) && trim($recipient) !== '') {
                    $recipients[] = trim($recipient);
                }
            }
            if (\count($recipients) === 0) {
                $recipients = $this->settings->getRecipients();
            }
            if (\count($recipients) === 0) {
                if ($directory === '') {
                    $io->note('Nothing to deliver: pass --output, or --email / save recipients on Reporting → Support hours → Settings.');
                }

                return Command::SUCCESS;
            }

            $label = $period->getLabel($locale);
            $t = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'messages', $locale);
            $email = (new Email())
                ->to(...$recipients)
                ->subject($t('support_hours.email.subject', ['%period%' => $label]))
                ->text(
                    $t('support_hours.email.greeting') . "\n\n"
                    . $t('support_hours.email.intro', ['%period%' => $label, '%slug%' => $period->getSlug($locale)]) . "\n\n"
                    . $t('support_hours.email.customers', ['%count%' => $report->getCustomerCount()]) . "\n\n"
                    . $t('support_hours.email.closing') . "\n"
                    . $t('support_hours.email.signature') . "\n"
                )
                ->attachFromPath($temporary, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            if ($input->getOption('send') !== true) {
                $io->section($email->getSubject() . ' → ' . implode(', ', $recipients));
                $io->writeln((string) $email->getTextBody());
                $io->note('Dry-run: add --send to email the workbook.');

                return Command::SUCCESS;
            }

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $io->error('Failed to send: ' . $e->getMessage());

                return Command::FAILURE;
            }

            $io->success('Email sent to ' . implode(', ', $recipients));

            return Command::SUCCESS;
        } finally {
            @unlink($temporary);
        }
    }

    private static function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }
}

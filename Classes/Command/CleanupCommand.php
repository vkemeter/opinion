<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vkemeter\Opinion\Cleanup\FeedbackCleaner;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;

#[AsCommand(
    name: 'opinion:cleanup',
    description: 'Delete feedback records and their screenshots older than the retention period',
)]
final class CleanupCommand extends Command
{
    public function __construct(
        private readonly FeedbackCleaner $feedbackCleaner,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Delete feedback older than this number of days (default: retentionDays of the extension configuration)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('days');
        $days = $days === null ? $this->configurationProvider->getConfiguration()->retentionDays : filter_var($days, FILTER_VALIDATE_INT);
        if (!is_int($days) || $days < 1) {
            $io->error('The option --days must be a positive integer.');
            return Command::INVALID;
        }

        $dryRun = (bool)$input->getOption('dry-run');
        $createdBefore = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('-%d days', $days));
        $result = $this->feedbackCleaner->cleanup($createdBefore, $dryRun);

        foreach ($result->errors as $error) {
            $io->error($error);
        }

        $io->success(sprintf(
            '%s %d feedback record(s) and %d screenshot(s) created before %s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $result->records,
            $result->screenshots,
            $createdBefore->format('Y-m-d H:i'),
        ));

        return $result->errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}

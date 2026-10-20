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
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Redelivery\Redeliverer;
use Vkemeter\Opinion\Repository\FeedbackRepository;

#[AsCommand(
    name: 'opinion:redeliver',
    description: 'Deliver feedback again whose delivery failed or never finished',
)]
final class RedeliverCommand extends Command
{
    /**
     * Feedback younger than this may still be in the middle of its first delivery.
     */
    private const int DEFAULT_MIN_AGE_MINUTES = 10;

    public function __construct(
        private readonly FeedbackRepository $feedbackRepository,
        private readonly Redeliverer $redeliverer,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-age', 'm', InputOption::VALUE_REQUIRED, 'Only feedback older than this number of minutes', (string)self::DEFAULT_MIN_AGE_MINUTES)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be delivered');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $minAge = filter_var($input->getOption('min-age'), FILTER_VALIDATE_INT);
        if (!is_int($minAge) || $minAge < 0) {
            $io->error('The option --min-age must be zero or a positive integer.');
            return Command::INVALID;
        }

        $configuration = $this->configurationProvider->getConfiguration();
        $createdBefore = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('-%d minutes', $minAge));
        $uids = $this->feedbackRepository->findUndeliveredUids($createdBefore->getTimestamp(), $configuration->redeliverMaxAttempts);

        if ((bool)$input->getOption('dry-run')) {
            $io->success(sprintf('Would deliver %d feedback record(s) again%s.', count($uids), $uids !== [] ? ': ' . implode(', ', $uids) : ''));
            return Command::SUCCESS;
        }

        $failed = [];
        foreach ($uids as $uid) {
            $result = $this->redeliverer->redeliver($uid, $configuration);
            if ($result?->status !== DeliveryStatus::Sent) {
                $failed[] = $uid;
            }
        }

        if ($failed !== []) {
            $io->warning(sprintf('Delivered %d of %d feedback record(s). Still undelivered: %s.', count($uids) - count($failed), count($uids), implode(', ', $failed)));
            return Command::FAILURE;
        }
        $io->success(sprintf('Delivered %d feedback record(s).', count($uids)));

        return Command::SUCCESS;
    }
}

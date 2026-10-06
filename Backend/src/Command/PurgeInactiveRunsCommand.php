<?php

namespace App\Command;

use App\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Housekeeping for the public leaderboard (run manually or from a scheduler):
 *  - forgets Idempotency-Keys older than 24 h (and their encrypted token);
 *  - deletes runs inactive for --days that are not visible on any leaderboard
 *    sort (top 100 of each are always kept). Dry run unless --force.
 */
#[AsCommand(name: 'app:leaderboard:purge-inactive', description: 'Purge inactive, non-visible leaderboard runs and expired idempotency keys')]
final class PurgeInactiveRunsCommand extends Command
{
    public const IDEMPOTENCY_TTL_SECONDS = 86_400;
    private const PROTECTED_TOP = 100;

    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Inactivity threshold in days', '180')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getOption('days'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 30]]);
        if (false === $days) {
            $io->error('--days must be an integer >= 30.');

            return Command::INVALID;
        }

        $now = new \DateTimeImmutable();
        $ids = $this->playerRepository->findPurgeableInactiveIds($now->modify(sprintf('-%d days', $days)), self::PROTECTED_TOP);

        if (!$input->getOption('force')) {
            $io->note(sprintf('Dry run: %d inactive non-visible run(s) would be deleted. Use --force to apply.', count($ids)));

            return Command::SUCCESS;
        }

        $expired = $this->playerRepository->expireIdempotencyKeys($now->modify(sprintf('-%d seconds', self::IDEMPOTENCY_TTL_SECONDS)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->entityManager->createQuery('DELETE FROM App\Entity\Player p WHERE p.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->execute();
        }
        $io->success(sprintf('%d run(s) deleted, %d idempotency key(s) expired.', count($ids), $expired));

        return Command::SUCCESS;
    }
}

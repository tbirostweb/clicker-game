<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs doctrine:migrations:migrate under a MySQL advisory lock so that two
 * containers booting at the same time (rolling deploy, replicas) never apply
 * the same migration twice. Take a database backup BEFORE deploying a release
 * that ships new migrations (README, "Déploiement").
 */
#[AsCommand(name: 'app:migrate-locked', description: 'Apply pending Doctrine migrations under a database lock')]
final class LockedMigrateCommand extends Command
{
    private const LOCK_NAME = 'clicker_game_migrations';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('lock-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for the lock', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrate = $this->getApplication()?->find('doctrine:migrations:migrate');
        if (null === $migrate) {
            $output->writeln('<error>doctrine:migrations:migrate unavailable</error>');

            return Command::FAILURE;
        }
        $migrateInput = new ArrayInput(['--no-interaction' => true, '--allow-no-migration' => true]);
        $migrateInput->setInteractive(false);

        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // Advisory locks below are MySQL-specific; other platforms (tests)
            // run unlocked.
            return $migrate->run($migrateInput, $output);
        }

        $timeout = max(1, (int) $input->getOption('lock-timeout'));
        $acquired = (int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, $timeout]);
        if (1 !== $acquired) {
            $output->writeln('<error>Could not acquire the migration lock within ' . $timeout . 's</error>');

            return Command::FAILURE;
        }

        try {
            return $migrate->run($migrateInput, $output);
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }
}

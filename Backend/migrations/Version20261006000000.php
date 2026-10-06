<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Security audit 2026-10-06 (F1): last accepted update time of each run,
 * used to bound the playtime a PUT may add. NULL for older runs (check skipped
 * until their next accepted update).
 *
 * Additive (nullable columns / secondary indexes): existing rows are kept and
 * the previous application version stays compatible with the new schema.
 * Applied at boot by app:migrate-locked like the previous ones; take a
 * database backup first (README "Déploiement").
 */
final class Version20261006000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add player.updated_at (plausibility of playtime growth between updates)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE player ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE player DROP updated_at');
    }
}

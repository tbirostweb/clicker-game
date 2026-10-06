<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Security audit 2026-10-06 (F5): an Idempotency-Key is honoured for 24 h;
 * a valid replay returns the edit token already issued (stored encrypted with
 * a key derived from the Idempotency-Key, which is never stored).
 *
 * Additive (nullable columns / secondary indexes): existing rows are kept and
 * the previous application version stays compatible with the new schema.
 * Applied at boot by app:migrate-locked like the previous ones; take a
 * database backup first (README "Déploiement").
 */
final class Version20261006000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotency_created_at and idempotency_token_box (24 h Idempotency-Key replay)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE player ADD idempotency_created_at DATETIME DEFAULT NULL, ADD idempotency_token_box VARCHAR(255) DEFAULT NULL');
        // Existing keys were used when their run was created.
        $this->addSql('UPDATE player SET idempotency_created_at = created_at WHERE idempotency_key_hash IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE player DROP idempotency_created_at, DROP idempotency_token_box');
    }
}

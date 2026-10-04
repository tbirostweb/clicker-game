<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Security audit 2026-10-04: run ownership (edit token hash), POST
 * idempotency, optimistic locking, and BIGINT score.
 *
 * Purely additive for existing rows (nullable / defaulted columns, INT ->
 * BIGINT widening): no data is rewritten or dropped. Take a database backup
 * before running it in production (see README "Déploiement").
 */
final class Version20261004000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add edit_token_hash, idempotency_key_hash (unique), version; widen score to BIGINT';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // Single ALTER statement: MySQL DDL is not transactional, but one
        // ALTER TABLE is atomic, so a failure never leaves a half-migrated table.
        $this->addSql('ALTER TABLE player ADD edit_token_hash VARCHAR(64) DEFAULT NULL, ADD idempotency_key_hash VARCHAR(64) DEFAULT NULL, ADD version INT DEFAULT 1 NOT NULL, MODIFY score BIGINT NOT NULL DEFAULT 0, ADD UNIQUE INDEX uniq_player_idempotency_key_hash (idempotency_key_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // Narrowing score back to INT fails if a stored value exceeds INT range:
        // that is intentional (no silent truncation). Restore from backup instead.
        // Single atomic ALTER: on failure the table is left untouched.
        $this->addSql('ALTER TABLE player DROP INDEX uniq_player_idempotency_key_hash, DROP edit_token_hash, DROP idempotency_key_hash, DROP version, MODIFY score INT NOT NULL DEFAULT 0');
    }
}

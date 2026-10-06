<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Security audit 2026-10-06 (F6): GET /api/leaderboard sorts by one of these
 * columns with a LIMIT; the indexes avoid a full scan + filesort per request.
 *
 * Additive (nullable columns / secondary indexes): existing rows are kept and
 * the previous application version stays compatible with the new schema.
 * Applied at boot by app:migrate-locked like the previous ones; take a
 * database backup first (README "Déploiement").
 */
final class Version20261006000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add one index per leaderboard sort column';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        // Single ALTER: atomic on MySQL (DDL is not transactional).
        $this->addSql('ALTER TABLE player ADD INDEX idx_player_active_seconds (active_seconds), ADD INDEX idx_player_rebirth (rebirth), ADD INDEX idx_player_score (score), ADD INDEX idx_player_trophy_count (trophy_count)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL/MariaDB.'
        );

        $this->addSql('ALTER TABLE player DROP INDEX idx_player_active_seconds, DROP INDEX idx_player_rebirth, DROP INDEX idx_player_score, DROP INDEX idx_player_trophy_count');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend player into a leaderboard run: add score, time_seconds, created_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD score INT NOT NULL DEFAULT 0, ADD time_seconds INT NOT NULL DEFAULT 0, ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP score, DROP time_seconds, DROP created_at');
    }
}

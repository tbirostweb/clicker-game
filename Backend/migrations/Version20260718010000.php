<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add active_seconds (window-focused playtime) and trophy_count to player runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD active_seconds INT NOT NULL DEFAULT 0, ADD trophy_count INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP active_seconds, DROP trophy_count');
    }
}

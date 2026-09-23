<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.last_activity_at (heartbeat / statut en ligne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD last_activity_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP last_activity_at');
    }
}

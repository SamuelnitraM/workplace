<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories : option « Création de sujets » (allow_threads, activée par défaut pour les catégories existantes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD allow_threads TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP allow_threads');
    }
}

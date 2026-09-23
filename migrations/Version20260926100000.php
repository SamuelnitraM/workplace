<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todo de groupe : rôle minimum pour voir toute la todo en lecture seule (group.todo_view_role, admin par défaut = comportement précédent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `group` ADD todo_view_role VARCHAR(10) DEFAULT 'admin' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `group` DROP todo_view_role');
    }
}

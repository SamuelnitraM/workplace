<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todo de groupe : rôle minimum pour écrire (group.todo_write_role, admin par défaut = comportement précédent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `group` ADD todo_write_role VARCHAR(10) DEFAULT 'admin' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `group` DROP todo_write_role');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Inactive accounts: date of the warning e-mail sent before their deletion (App\Account\InactiveAccountPurger).
 */
final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prévenance avant suppression des comptes inactifs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD inactivity_warned_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP inactivity_warned_at');
    }
}

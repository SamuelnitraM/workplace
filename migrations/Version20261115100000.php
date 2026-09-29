<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Removes user.profile_bonus_awarded: a column neither read nor written by the application.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression de la colonne inutilisée user.profile_bonus_awarded';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP profile_bonus_awarded');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD profile_bonus_awarded TINYINT(1) DEFAULT 0 NOT NULL');
    }
}

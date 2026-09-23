<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (ne pas régénérer avec doctrine:migrations:diff).
 *
 * Titre de profil : un membre peut afficher UN badge débloqué comme « titre » à côté de son pseudo
 * (user.title_badge_id). Badge supprimé → titre retiré (ON DELETE SET NULL).
 */
final class Version20260928100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification : titre de profil (user.title_badge_id → badge, ON DELETE SET NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD title_badge_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649DE39775D FOREIGN KEY (title_badge_id) REFERENCES badge (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649DE39775D ON `user` (title_badge_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649DE39775D');
        $this->addSql('DROP INDEX IDX_8D93D649DE39775D ON `user`');
        $this->addSql('ALTER TABLE `user` DROP title_badge_id');
    }
}

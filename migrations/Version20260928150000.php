<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (ne pas régénérer avec doctrine:migrations:diff).
 *
 * Classement général (/classement) : index couvrant sur experience_award pour les classements
 * « XP de la semaine » et « XP du mois » (WHERE created_at >= ? GROUP BY user_id, SUM(amount)).
 */
final class Version20260928150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classement : index IDX_EXPERIENCE_AWARD_CREATED (created_at, user_id, amount) sur experience_award';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_EXPERIENCE_AWARD_CREATED ON experience_award (created_at, user_id, amount)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_EXPERIENCE_AWARD_CREATED ON experience_award');
    }
}

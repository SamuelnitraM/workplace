<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (ne pas régénérer avec doctrine:migrations:diff).
 *
 * Présentation guidée (/bienvenue) : user.onboarding_completed_at (NULL = présentation à terminer)
 * et user.onboarding_step (étape à reprendre, 1 à 4). Les comptes existants sont marqués « terminés »
 * pour ne pas leur imposer la présentation (ils peuvent la relancer depuis le pied de page).
 */
final class Version20260929100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Présentation guidée : user.onboarding_completed_at + user.onboarding_step (comptes existants marqués terminés)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD onboarding_completed_at DATETIME DEFAULT NULL, ADD onboarding_step SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE user SET onboarding_completed_at = NOW()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP onboarding_completed_at, DROP onboarding_step');
    }
}

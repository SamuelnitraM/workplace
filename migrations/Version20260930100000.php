<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (ne pas régénérer avec doctrine:migrations:diff).
 *
 * Synchronisation BSData : faction_sync_state.source_files = fichiers du graphe de catalogues d'une faction
 * (catalogue principal, bibliothèques liées, système de jeu). last_commit_sha contient désormais l'empreinte
 * combinée des SHA de blob de ces fichiers (détection de changement sans retélécharger les catalogues).
 */
final class Version20260930100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronisation BSData : faction_sync_state.source_files (fichiers du graphe de catalogues)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE faction_sync_state ADD source_files JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE faction_sync_state DROP source_files');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Phase 3 "Hobby core features":
 *  - faction_enhancement: detachment enhancements synchronised from BSData (run army:sync-bsdata --force);
 *  - army_list.battle_size: official list (points limit 1000, 2000 or 3000) or free list (NULL);
 *  - army_unit.model_count / warlord / enhancement_name / enhancement_points: unit size, Warlord, enhancement;
 *  - gallery_album and gallery_photo.album_id: gallery albums (a photo belongs to one album at most).
 */
final class Version20261003100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3 : améliorations de détachement, listes officielles, albums de la galerie';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE faction_enhancement (id INT AUTO_INCREMENT NOT NULL, bsdata_id VARCHAR(60) NOT NULL, name VARCHAR(155) NOT NULL, faction VARCHAR(100) NOT NULL, detachment VARCHAR(155) NOT NULL, points INT NOT NULL, description LONGTEXT DEFAULT NULL, INDEX idx_faction_enhancement_detachment (faction, detachment), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE army_list ADD battle_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE army_unit ADD model_count SMALLINT DEFAULT NULL, ADD warlord TINYINT DEFAULT 0 NOT NULL, ADD enhancement_name VARCHAR(155) DEFAULT NULL, ADD enhancement_points INT DEFAULT 0 NOT NULL');

        $this->addSql('CREATE TABLE gallery_album (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_DD05BE607E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gallery_album ADD CONSTRAINT FK_DD05BE607E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gallery_photo ADD album_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE gallery_photo ADD CONSTRAINT FK_F02A543B1137ABCF FOREIGN KEY (album_id) REFERENCES gallery_album (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F02A543B1137ABCF ON gallery_photo (album_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gallery_photo DROP FOREIGN KEY FK_F02A543B1137ABCF');
        $this->addSql('DROP INDEX IDX_F02A543B1137ABCF ON gallery_photo');
        $this->addSql('ALTER TABLE gallery_photo DROP album_id');
        $this->addSql('DROP TABLE gallery_album');
        $this->addSql('ALTER TABLE army_unit DROP model_count, DROP warlord, DROP enhancement_name, DROP enhancement_points');
        $this->addSql('ALTER TABLE army_list DROP battle_size');
        $this->addSql('DROP TABLE faction_enhancement');
    }
}

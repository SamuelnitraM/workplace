<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911163313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE announcement_image (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, position INT NOT NULL, announcement_id INT NOT NULL, INDEX IDX_A7CC2888913AEA17 (announcement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE announcement_image ADD CONSTRAINT FK_A7CC2888913AEA17 FOREIGN KEY (announcement_id) REFERENCES announcement (id)');
        $this->addSql('ALTER TABLE announcement ADD slug VARCHAR(170) NOT NULL, ADD description LONGTEXT DEFAULT NULL, ADD price NUMERIC(8, 2) NOT NULL, ADD product_type VARCHAR(20) NOT NULL, ADD `condition` INT NOT NULL, ADD product_status VARCHAR(20) DEFAULT NULL, ADD sale_method VARCHAR(20) NOT NULL, ADD created_at DATETIME NOT NULL, ADD updated_at DATETIME DEFAULT NULL, ADD owner_id INT NOT NULL, ADD game_system_id INT NOT NULL, ADD faction_id INT DEFAULT NULL, CHANGE title title VARCHAR(150) NOT NULL');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C233EEA7 FOREIGN KEY (game_system_id) REFERENCES game_system (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C4448F8DA FOREIGN KEY (faction_id) REFERENCES faction (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4DB9D91C989D9B62 ON announcement (slug)');
        $this->addSql('CREATE INDEX IDX_4DB9D91C7E3C61F9 ON announcement (owner_id)');
        $this->addSql('CREATE INDEX IDX_4DB9D91C233EEA7 ON announcement (game_system_id)');
        $this->addSql('CREATE INDEX IDX_4DB9D91C4448F8DA ON announcement (faction_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE announcement_image DROP FOREIGN KEY FK_A7CC2888913AEA17');
        $this->addSql('DROP TABLE announcement_image');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91C7E3C61F9');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91C233EEA7');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91C4448F8DA');
        $this->addSql('DROP INDEX UNIQ_4DB9D91C989D9B62 ON announcement');
        $this->addSql('DROP INDEX IDX_4DB9D91C7E3C61F9 ON announcement');
        $this->addSql('DROP INDEX IDX_4DB9D91C233EEA7 ON announcement');
        $this->addSql('DROP INDEX IDX_4DB9D91C4448F8DA ON announcement');
        $this->addSql('ALTER TABLE announcement DROP slug, DROP description, DROP price, DROP product_type, DROP `condition`, DROP product_status, DROP sale_method, DROP created_at, DROP updated_at, DROP owner_id, DROP game_system_id, DROP faction_id, CHANGE title title VARCHAR(155) NOT NULL');
    }
}

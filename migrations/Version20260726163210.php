<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726163210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE army_list (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, faction VARCHAR(155) NOT NULL, detachement VARCHAR(155) DEFAULT NULL, total_points INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, is_public TINYINT NOT NULL, owner_id INT NOT NULL, INDEX IDX_5AE719967E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE army_unit (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(155) NOT NULL, quantity INT NOT NULL, points INT NOT NULL, options JSON DEFAULT NULL, armylist_id INT NOT NULL, INDEX IDX_C294EDDDB690880C (armylist_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE army_list ADD CONSTRAINT FK_5AE719967E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE army_unit ADD CONSTRAINT FK_C294EDDDB690880C FOREIGN KEY (armylist_id) REFERENCES army_list (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE army_list DROP FOREIGN KEY FK_5AE719967E3C61F9');
        $this->addSql('ALTER TABLE army_unit DROP FOREIGN KEY FK_C294EDDDB690880C');
        $this->addSql('DROP TABLE army_list');
        $this->addSql('DROP TABLE army_unit');
    }
}

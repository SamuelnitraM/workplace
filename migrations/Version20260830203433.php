<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260830203433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE announcement (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(155) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE faction_unit (id INT AUTO_INCREMENT NOT NULL, bsdata_id VARCHAR(60) NOT NULL, name VARCHAR(155) NOT NULL, faction VARCHAR(100) NOT NULL, category VARCHAR(50) DEFAULT NULL, points INT NOT NULL, source_file VARCHAR(100) NOT NULL, UNIQUE INDEX unique_bsdata_id (bsdata_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE faction_unit');
    }
}

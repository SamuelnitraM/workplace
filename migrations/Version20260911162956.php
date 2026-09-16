<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911162956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE faction (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, game_system_id INT NOT NULL, UNIQUE INDEX UNIQ_83048B90989D9B62 (slug), INDEX IDX_83048B90233EEA7 (game_system_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_system (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, position INT NOT NULL, UNIQUE INDEX UNIQ_B478BC435E237E06 (name), UNIQUE INDEX UNIQ_B478BC43989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE faction ADD CONSTRAINT FK_83048B90233EEA7 FOREIGN KEY (game_system_id) REFERENCES game_system (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE faction DROP FOREIGN KEY FK_83048B90233EEA7');
        $this->addSql('DROP TABLE faction');
        $this->addSql('DROP TABLE game_system');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260507103023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `group` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, slug VARCHAR(100) NOT NULL, is_public TINYINT NOT NULL, is_joinable TINYINT NOT NULL, created_at DATETIME NOT NULL, creator_id INT NOT NULL, INDEX IDX_6DC044C561220EA6 (creator_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE group_member (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(25) NOT NULL, joined_at DATETIME NOT NULL, user_id INT NOT NULL, usergroup_id INT NOT NULL, INDEX IDX_A36222A8A76ED395 (user_id), INDEX IDX_A36222A8D2112630 (usergroup_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE group_message (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, author_id INT NOT NULL, usergroup_id INT NOT NULL, INDEX IDX_30BD6473F675F31B (author_id), INDEX IDX_30BD6473D2112630 (usergroup_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_6DC044C561220EA6 FOREIGN KEY (creator_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE group_member ADD CONSTRAINT FK_A36222A8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE group_member ADD CONSTRAINT FK_A36222A8D2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id)');
        $this->addSql('ALTER TABLE group_message ADD CONSTRAINT FK_30BD6473F675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE group_message ADD CONSTRAINT FK_30BD6473D2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id)');
        $this->addSql('ALTER TABLE todo_node ADD assigned_to_id INT DEFAULT NULL, ADD usergroup_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AD2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id)');
        $this->addSql('CREATE INDEX IDX_DAAE8E5AF4BD7827 ON todo_node (assigned_to_id)');
        $this->addSql('CREATE INDEX IDX_DAAE8E5AD2112630 ON todo_node (usergroup_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `group` DROP FOREIGN KEY FK_6DC044C561220EA6');
        $this->addSql('ALTER TABLE group_member DROP FOREIGN KEY FK_A36222A8A76ED395');
        $this->addSql('ALTER TABLE group_member DROP FOREIGN KEY FK_A36222A8D2112630');
        $this->addSql('ALTER TABLE group_message DROP FOREIGN KEY FK_30BD6473F675F31B');
        $this->addSql('ALTER TABLE group_message DROP FOREIGN KEY FK_30BD6473D2112630');
        $this->addSql('DROP TABLE `group`');
        $this->addSql('DROP TABLE group_member');
        $this->addSql('DROP TABLE group_message');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AF4BD7827');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AD2112630');
        $this->addSql('DROP INDEX IDX_DAAE8E5AF4BD7827 ON todo_node');
        $this->addSql('DROP INDEX IDX_DAAE8E5AD2112630 ON todo_node');
        $this->addSql('ALTER TABLE todo_node DROP assigned_to_id, DROP usergroup_id');
    }
}

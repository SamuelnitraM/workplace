<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260509214852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE group_channel (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, position INT NOT NULL, can_read VARCHAR(20) NOT NULL, can_write VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, usergroup_id INT NOT NULL, INDEX IDX_24F9DA4BD2112630 (usergroup_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE private_conversation (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, participant1_id INT NOT NULL, participant2_id INT NOT NULL, INDEX IDX_DCF38EEBB29A9963 (participant1_id), INDEX IDX_DCF38EEBA02F368D (participant2_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE private_message (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, is_read TINYINT NOT NULL, author_id INT NOT NULL, conversation_id INT NOT NULL, INDEX IDX_4744FC9BF675F31B (author_id), INDEX IDX_4744FC9B9AC0396 (conversation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE group_channel ADD CONSTRAINT FK_24F9DA4BD2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id)');
        $this->addSql('ALTER TABLE private_conversation ADD CONSTRAINT FK_DCF38EEBB29A9963 FOREIGN KEY (participant1_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE private_conversation ADD CONSTRAINT FK_DCF38EEBA02F368D FOREIGN KEY (participant2_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE private_message ADD CONSTRAINT FK_4744FC9BF675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE private_message ADD CONSTRAINT FK_4744FC9B9AC0396 FOREIGN KEY (conversation_id) REFERENCES private_conversation (id)');
        $this->addSql('ALTER TABLE group_message ADD channel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE group_message ADD CONSTRAINT FK_30BD647372F5A1AA FOREIGN KEY (channel_id) REFERENCES group_channel (id)');
        $this->addSql('CREATE INDEX IDX_30BD647372F5A1AA ON group_message (channel_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE group_channel DROP FOREIGN KEY FK_24F9DA4BD2112630');
        $this->addSql('ALTER TABLE private_conversation DROP FOREIGN KEY FK_DCF38EEBB29A9963');
        $this->addSql('ALTER TABLE private_conversation DROP FOREIGN KEY FK_DCF38EEBA02F368D');
        $this->addSql('ALTER TABLE private_message DROP FOREIGN KEY FK_4744FC9BF675F31B');
        $this->addSql('ALTER TABLE private_message DROP FOREIGN KEY FK_4744FC9B9AC0396');
        $this->addSql('DROP TABLE group_channel');
        $this->addSql('DROP TABLE private_conversation');
        $this->addSql('DROP TABLE private_message');
        $this->addSql('ALTER TABLE group_message DROP FOREIGN KEY FK_30BD647372F5A1AA');
        $this->addSql('DROP INDEX IDX_30BD647372F5A1AA ON group_message');
        $this->addSql('ALTER TABLE group_message DROP channel_id');
    }
}

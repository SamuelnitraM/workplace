<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905092000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add positive and helpful votes to forum posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE post_vote (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, user_id INT NOT NULL, type VARCHAR(20) NOT NULL, UNIQUE INDEX UNIQ_POST_VOTE_USER_POST_TYPE (post_id, user_id, type), INDEX IDX_9345E26F4B89032C (post_id), INDEX IDX_9345E26FA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE post_vote ADD CONSTRAINT FK_9345E26F4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_vote ADD CONSTRAINT FK_9345E26FA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE post_vote');
    }
}
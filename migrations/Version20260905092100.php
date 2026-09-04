<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905092100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align post vote foreign key and index names with Doctrine metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_vote DROP FOREIGN KEY `FK_POST_VOTE_POST`');
        $this->addSql('ALTER TABLE post_vote DROP FOREIGN KEY `FK_POST_VOTE_USER`');
        $this->addSql('DROP INDEX idx_post_vote_post ON post_vote');
        $this->addSql('DROP INDEX idx_post_vote_user ON post_vote');
        $this->addSql('CREATE INDEX IDX_9345E26F4B89032C ON post_vote (post_id)');
        $this->addSql('CREATE INDEX IDX_9345E26FA76ED395 ON post_vote (user_id)');
        $this->addSql('ALTER TABLE post_vote ADD CONSTRAINT FK_9345E26F4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_vote ADD CONSTRAINT FK_9345E26FA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_vote DROP FOREIGN KEY `FK_9345E26F4B89032C`');
        $this->addSql('ALTER TABLE post_vote DROP FOREIGN KEY `FK_9345E26FA76ED395`');
        $this->addSql('DROP INDEX IDX_9345E26F4B89032C ON post_vote');
        $this->addSql('DROP INDEX IDX_9345E26FA76ED395 ON post_vote');
        $this->addSql('CREATE INDEX IDX_POST_VOTE_POST ON post_vote (post_id)');
        $this->addSql('CREATE INDEX IDX_POST_VOTE_USER ON post_vote (user_id)');
        $this->addSql('ALTER TABLE post_vote ADD CONSTRAINT FK_POST_VOTE_POST FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_vote ADD CONSTRAINT FK_POST_VOTE_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }
}
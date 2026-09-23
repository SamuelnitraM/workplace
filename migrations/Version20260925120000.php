<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messages épinglés des channels de groupe : group_message.pinned_at / pinned_by_id (SET NULL), index (channel_id, pinned_at), réglage group.pin_role (admin par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_message ADD pinned_at DATETIME DEFAULT NULL, ADD pinned_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE group_message ADD CONSTRAINT FK_30BD647359662AC1 FOREIGN KEY (pinned_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30BD647359662AC1 ON group_message (pinned_by_id)');
        $this->addSql('CREATE INDEX idx_group_message_channel_pinned ON group_message (channel_id, pinned_at)');
        $this->addSql('ALTER TABLE `group` ADD pin_role VARCHAR(20) DEFAULT \'admin\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `group` DROP pin_role');
        $this->addSql('ALTER TABLE group_message DROP FOREIGN KEY FK_30BD647359662AC1');
        $this->addSql('DROP INDEX IDX_30BD647359662AC1 ON group_message');
        $this->addSql('DROP INDEX idx_group_message_channel_pinned ON group_message');
        $this->addSql('ALTER TABLE group_message DROP pinned_at, DROP pinned_by_id');
    }
}

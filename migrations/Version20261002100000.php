<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written (do not regenerate with doctrine:migrations:diff).
 *
 * Phase 1 "Public site foundations":
 *  - reset_password_request: pending password reset links (hashed tokens);
 *  - user_block: blocking between members. Friendships with the "blocked" status (refused requests)
 *    are moved there, the member who refused being the blocker;
 *  - report: moderation reports;
 *  - user.suspended_at / suspended_until / suspension_reason: account suspensions;
 *  - post.moderation_hidden_at, gallery_photo.moderation_hidden_at: content hidden by moderation;
 *  - removal of the unused marketplace tables (announcement, announcement_image, faction, game_system).
 */
final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 : mot de passe oublié, blocage entre membres, signalements, sanctions, suppression des tables orphelines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reset_password_request (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_7CE748AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE user_block (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, blocker_id INT NOT NULL, blocked_id INT NOT NULL, INDEX IDX_61D96C7A548D5975 (blocker_id), INDEX IDX_61D96C7A21FF5136 (blocked_id), UNIQUE INDEX UNIQ_USER_BLOCK_PAIR (blocker_id, blocked_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A548D5975 FOREIGN KEY (blocker_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A21FF5136 FOREIGN KEY (blocked_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql("INSERT IGNORE INTO user_block (blocker_id, blocked_id, created_at) SELECT receiver_id, requester_id, created_at FROM friendship WHERE status = 'blocked'");
        $this->addSql("DELETE FROM friendship WHERE status = 'blocked'");

        $this->addSql('CREATE TABLE report (id INT AUTO_INCREMENT NOT NULL, target_type VARCHAR(20) NOT NULL, target_id INT NOT NULL, reason VARCHAR(20) NOT NULL, details LONGTEXT DEFAULT NULL, excerpt VARCHAR(500) NOT NULL, target_url VARCHAR(500) DEFAULT NULL, status VARCHAR(20) NOT NULL, resolution VARCHAR(20) DEFAULT NULL, moderator_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, handled_at DATETIME DEFAULT NULL, reporter_id INT DEFAULT NULL, target_author_id INT DEFAULT NULL, handled_by_id INT DEFAULT NULL, INDEX IDX_C42F7784E1CFE6F5 (reporter_id), INDEX IDX_C42F7784C2C18137 (target_author_id), INDEX IDX_C42F7784FE65AF40 (handled_by_id), INDEX idx_report_status_created (status, created_at), INDEX idx_report_target (target_type, target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784C2C18137 FOREIGN KEY (target_author_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784FE65AF40 FOREIGN KEY (handled_by_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE user ADD suspended_at DATETIME DEFAULT NULL, ADD suspended_until DATETIME DEFAULT NULL, ADD suspension_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD moderation_hidden_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE gallery_photo ADD moderation_hidden_at DATETIME DEFAULT NULL');

        // Children first: announcement_image → announcement → faction → game_system
        $this->addSql('DROP TABLE IF EXISTS announcement_image, announcement, faction, game_system');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_system (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, position INT NOT NULL, UNIQUE INDEX UNIQ_B478BC435E237E06 (name), UNIQUE INDEX UNIQ_B478BC43989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE faction (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, game_system_id INT NOT NULL, UNIQUE INDEX UNIQ_83048B90989D9B62 (slug), INDEX IDX_83048B90233EEA7 (game_system_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE faction ADD CONSTRAINT FK_83048B90233EEA7 FOREIGN KEY (game_system_id) REFERENCES game_system (id)');
        $this->addSql('CREATE TABLE announcement (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, slug VARCHAR(170) NOT NULL, description LONGTEXT DEFAULT NULL, price NUMERIC(8, 2) NOT NULL, product_type VARCHAR(20) NOT NULL, `condition` INT NOT NULL, product_status VARCHAR(20) DEFAULT NULL, sale_method VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INT NOT NULL, game_system_id INT NOT NULL, faction_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4DB9D91C989D9B62 (slug), INDEX IDX_4DB9D91C7E3C61F9 (owner_id), INDEX IDX_4DB9D91C233EEA7 (game_system_id), INDEX IDX_4DB9D91C4448F8DA (faction_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C233EEA7 FOREIGN KEY (game_system_id) REFERENCES game_system (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C4448F8DA FOREIGN KEY (faction_id) REFERENCES faction (id)');
        $this->addSql('CREATE TABLE announcement_image (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, position INT NOT NULL, announcement_id INT NOT NULL, INDEX IDX_A7CC2888913AEA17 (announcement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE announcement_image ADD CONSTRAINT FK_A7CC2888913AEA17 FOREIGN KEY (announcement_id) REFERENCES announcement (id)');

        $this->addSql('ALTER TABLE gallery_photo DROP moderation_hidden_at');
        $this->addSql('ALTER TABLE post DROP moderation_hidden_at');
        $this->addSql('ALTER TABLE user DROP suspended_at, DROP suspended_until, DROP suspension_reason');
        $this->addSql('DROP TABLE report');
        $this->addSql("INSERT INTO friendship (requester_id, receiver_id, status, created_at) SELECT blocked_id, blocker_id, 'blocked', created_at FROM user_block");
        $this->addSql('DROP TABLE user_block');
        $this->addSql('DROP TABLE reset_password_request');
    }
}

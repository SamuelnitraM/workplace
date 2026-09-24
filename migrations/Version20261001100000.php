<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (ne pas régénérer avec doctrine:migrations:diff).
 *
 * Phase 2 « Rétention et vie sociale » :
 *  - thread_subscription : abonnements aux sujets. Rattrapage : chaque membre ayant écrit dans un sujet
 *    (auteur du sujet compris, via son premier message) y est abonné, comme le fait désormais le code ;
 *  - thread.solution_post_id : réponse désignée comme solution (sujet résolu) ;
 *  - forum_image : images envoyées depuis l'éditeur du forum ;
 *  - user.notification_sound : son joué à la réception d'une notification.
 */
final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum : abonnements aux sujets, solution, images ; son de notification des membres';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE thread_subscription (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, thread_id INT NOT NULL, INDEX IDX_D0F9D303A76ED395 (user_id), INDEX IDX_D0F9D303E2904019 (thread_id), UNIQUE INDEX UNIQ_THREAD_SUBSCRIPTION (user_id, thread_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE thread_subscription ADD CONSTRAINT FK_D0F9D303A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE thread_subscription ADD CONSTRAINT FK_D0F9D303E2904019 FOREIGN KEY (thread_id) REFERENCES thread (id) ON DELETE CASCADE');
        $this->addSql('INSERT IGNORE INTO thread_subscription (user_id, thread_id, created_at) SELECT p.author_id, p.thread_id, MIN(p.created_at) FROM post p GROUP BY p.author_id, p.thread_id');

        $this->addSql('ALTER TABLE thread ADD solution_post_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE thread ADD CONSTRAINT FK_31204C83EF2B3196 FOREIGN KEY (solution_post_id) REFERENCES post (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_31204C83EF2B3196 ON thread (solution_post_id)');

        $this->addSql('CREATE TABLE forum_image (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, uploader_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_DD49A2883C0BE965 (filename), INDEX IDX_DD49A28816678C77 (uploader_id), INDEX idx_forum_image_uploader_created (uploader_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE forum_image ADD CONSTRAINT FK_DD49A28816678C77 FOREIGN KEY (uploader_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql("ALTER TABLE user ADD notification_sound VARCHAR(20) DEFAULT 'auspex' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP notification_sound');
        $this->addSql('DROP TABLE forum_image');
        $this->addSql('ALTER TABLE thread DROP FOREIGN KEY FK_31204C83EF2B3196');
        $this->addSql('DROP INDEX IDX_31204C83EF2B3196 ON thread');
        $this->addSql('ALTER TABLE thread DROP solution_post_id');
        $this->addSql('DROP TABLE thread_subscription');
    }
}

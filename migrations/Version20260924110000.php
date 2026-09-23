<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Galerie : description des photos, likes et commentaires (suppression en cascade avec la photo ou l\'utilisateur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gallery_photo ADD description VARCHAR(500) DEFAULT NULL');

        $this->addSql('CREATE TABLE gallery_photo_like (id INT AUTO_INCREMENT NOT NULL, photo_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_42BE03417E9E4C8C (photo_id), INDEX IDX_42BE0341A76ED395 (user_id), UNIQUE INDEX uniq_gallery_photo_like (photo_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gallery_photo_like ADD CONSTRAINT FK_42BE03417E9E4C8C FOREIGN KEY (photo_id) REFERENCES gallery_photo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gallery_photo_like ADD CONSTRAINT FK_42BE0341A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE gallery_photo_comment (id INT AUTO_INCREMENT NOT NULL, photo_id INT NOT NULL, author_id INT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_10247937E9E4C8C (photo_id), INDEX IDX_1024793F675F31B (author_id), INDEX idx_gallery_photo_comment_photo_created (photo_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gallery_photo_comment ADD CONSTRAINT FK_10247937E9E4C8C FOREIGN KEY (photo_id) REFERENCES gallery_photo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gallery_photo_comment ADD CONSTRAINT FK_1024793F675F31B FOREIGN KEY (author_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gallery_photo_comment');
        $this->addSql('DROP TABLE gallery_photo_like');
        $this->addSql('ALTER TABLE gallery_photo DROP description');
    }
}

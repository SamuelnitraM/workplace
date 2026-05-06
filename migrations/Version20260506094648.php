<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506094648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE todo_node ADD owner_id INT NOT NULL, ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5A727ACA70 FOREIGN KEY (parent_id) REFERENCES todo_node (id)');
        $this->addSql('CREATE INDEX IDX_DAAE8E5A7E3C61F9 ON todo_node (owner_id)');
        $this->addSql('CREATE INDEX IDX_DAAE8E5A727ACA70 ON todo_node (parent_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5A7E3C61F9');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5A727ACA70');
        $this->addSql('DROP INDEX IDX_DAAE8E5A7E3C61F9 ON todo_node');
        $this->addSql('DROP INDEX IDX_DAAE8E5A727ACA70 ON todo_node');
        $this->addSql('ALTER TABLE todo_node DROP owner_id, DROP parent_id');
    }
}

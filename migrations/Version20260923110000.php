<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'todo_node : ON DELETE CASCADE sur parent_id et usergroup_id, ON DELETE SET NULL sur assigned_to_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5A727ACA70');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AF4BD7827');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AD2112630');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5A727ACA70 FOREIGN KEY (parent_id) REFERENCES todo_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AD2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5A727ACA70');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AF4BD7827');
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AD2112630');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5A727ACA70 FOREIGN KEY (parent_id) REFERENCES todo_node (id)');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AD2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id)');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Specific specifications, lot 2 "Groups":
 *  - todo_assignment: several members per task (accepted or pending request); the single todo_node.assigned_to_id
 *    becomes an accepted assignment, and a category assignment becomes an assignment of each task of the category;
 *  - group.invite_role / assignment_role / max_assignees_per_task: invitation rights and task assignment settings;
 *  - group_member.position / muted: custom order of the groups page, muted group;
 *  - user.group_sort_mode: order of the groups page (activity or custom).
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 2 : assignations multiples des tâches, droits d\'invitation et d\'assignation, ordre et mise en sourdine des groupes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE todo_assignment (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, node_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_2D3FC168460D9FD7 (node_id), INDEX IDX_2D3FC168A76ED395 (user_id), UNIQUE INDEX uniq_todo_assignment (node_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE todo_assignment ADD CONSTRAINT FK_2D3FC168460D9FD7 FOREIGN KEY (node_id) REFERENCES todo_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE todo_assignment ADD CONSTRAINT FK_2D3FC168A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO todo_assignment (node_id, user_id, status, created_at) SELECT id, assigned_to_id, 'accepted', NOW() FROM todo_node WHERE assigned_to_id IS NOT NULL AND type = 'item'");
        $this->addSql("INSERT IGNORE INTO todo_assignment (node_id, user_id, status, created_at) SELECT item.id, category.assigned_to_id, 'accepted', NOW() FROM todo_node category INNER JOIN todo_node item ON item.parent_id = category.id AND item.type = 'item' WHERE category.type = 'category' AND category.assigned_to_id IS NOT NULL");
        $this->addSql('ALTER TABLE todo_node DROP FOREIGN KEY FK_DAAE8E5AF4BD7827');
        $this->addSql('DROP INDEX IDX_DAAE8E5AF4BD7827 ON todo_node');
        $this->addSql('ALTER TABLE todo_node DROP assigned_to_id');
        $this->addSql("ALTER TABLE `group` ADD invite_role VARCHAR(10) DEFAULT 'member' NOT NULL, ADD assignment_role VARCHAR(10) DEFAULT 'admin' NOT NULL, ADD max_assignees_per_task SMALLINT DEFAULT 3 NOT NULL");
        $this->addSql('ALTER TABLE group_member ADD position INT DEFAULT NULL, ADD muted TINYINT DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE user ADD group_sort_mode VARCHAR(10) DEFAULT 'activity' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP group_sort_mode');
        $this->addSql('ALTER TABLE group_member DROP position, DROP muted');
        $this->addSql('ALTER TABLE `group` DROP invite_role, DROP assignment_role, DROP max_assignees_per_task');
        $this->addSql('ALTER TABLE todo_node ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql("UPDATE todo_node node SET assigned_to_id = (SELECT MIN(assignment.user_id) FROM todo_assignment assignment WHERE assignment.node_id = node.id AND assignment.status = 'accepted')");
        $this->addSql('ALTER TABLE todo_node ADD CONSTRAINT FK_DAAE8E5AF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DAAE8E5AF4BD7827 ON todo_node (assigned_to_id)');
        $this->addSql('ALTER TABLE todo_assignment DROP FOREIGN KEY FK_2D3FC168460D9FD7');
        $this->addSql('ALTER TABLE todo_assignment DROP FOREIGN KEY FK_2D3FC168A76ED395');
        $this->addSql('DROP TABLE todo_assignment');
    }
}

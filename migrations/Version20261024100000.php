<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Specific specifications, lot 3 "combined report processing":
 *  - report.resolutions: every resolution applied by a combined decision (content, warning, suspension),
 *    most severe first; report.resolution keeps the most severe one. Closed reports get their single resolution.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 3 : décisions multiples sur un signalement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report ADD resolutions JSON DEFAULT NULL');
        $this->addSql('UPDATE report SET resolutions = JSON_ARRAY(resolution) WHERE resolution IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report DROP resolutions');
    }
}

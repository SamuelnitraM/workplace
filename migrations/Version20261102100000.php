<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Badge icons: name of an SVG icon of the site (« badge-<code> », templates/_partials/_icon.html.twig)
 * instead of an emoji; the column is widened and the badges of App\Gamification\BadgeCatalog get their icon.
 */
final class Version20261102100000 extends AbstractMigration
{
    private const BADGE_CODES = [
        'pioneer_1', 'pioneer_2', 'pioneer_3', 'popular_1', 'popular_2', 'popular_3', 'devoted_1', 'devoted_2', 'devoted_3',
        'master_blacksmith', 'curious', 'jurist', 'archaeologist', 'heroic', 'legendary', 'immortal', 'vanguard',
    ];

    public function getDescription(): string
    {
        return 'Icônes SVG des badges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE badge CHANGE icon icon VARCHAR(40) NOT NULL');
        foreach (self::BADGE_CODES as $code) {
            $this->addSql('UPDATE badge SET icon = :icon WHERE code = :code', ['icon' => 'badge-' . str_replace('_', '-', $code), 'code' => $code]);
        }
        $this->addSql("UPDATE badge SET icon = 'medal' WHERE icon NOT LIKE 'badge-%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE badge SET icon = 'medal'");
        $this->addSql('ALTER TABLE badge CHANGE icon icon VARCHAR(20) NOT NULL');
    }
}

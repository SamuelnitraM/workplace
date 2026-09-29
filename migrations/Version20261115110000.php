<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Reference forum categories created by app:forum:seed-categories: descriptions addressed to the member with « tu »,
 * like the rest of the site. A description edited in the administration is left untouched.
 */
final class Version20261115110000 extends AbstractMigration
{
    private const DESCRIPTIONS = [
        'Suivez vos projets en cours et relevez des défis.' => 'Suis tes projets en cours et relève des défis.',
        'Partagez vos listes et discutez stratégie.' => 'Partage tes listes et discute stratégie.',
        'Récits et photos de vos parties.' => 'Récits et photos de tes parties.',
        'Présentez-vous à la communauté.' => 'Présente-toi à la communauté.',
        'Trouvez des adversaires et des clubs près de chez vous.' => 'Trouve des adversaires et des clubs près de chez toi.',
        'Vos idées pour améliorer le site.' => 'Tes idées pour améliorer le site.',
    ];

    public function getDescription(): string
    {
        return 'Descriptions des catégories de référence du forum au tutoiement';
    }

    public function up(Schema $schema): void
    {
        foreach (self::DESCRIPTIONS as $formal => $informal) {
            $this->addSql('UPDATE category SET description = :informal WHERE description = :formal', ['informal' => $informal, 'formal' => $formal]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DESCRIPTIONS as $formal => $informal) {
            $this->addSql('UPDATE category SET description = :formal WHERE description = :informal', ['informal' => $informal, 'formal' => $formal]);
        }
    }
}

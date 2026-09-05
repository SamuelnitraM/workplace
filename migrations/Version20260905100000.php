<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate ambassador and sharer badges and correct badge conditions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE badge SET name = 'Ambassadeur - Parrainer un nouvel utilisateur', description = 'Parrainer un nouvel utilisateur.' WHERE code = 'ambassador'");
        $this->addSql("UPDATE badge SET name = 'Habitué - 30 jours', description = 'Enregistrer 30 jours de connexion consécutifs.' WHERE code = 'pillar'");
        $this->addSql("UPDATE badge SET description = 'Créer 10 sujets avec au moins 10 réponses écrites par d’autres utilisateurs.' WHERE code = 'master_blacksmith'");
        $this->addSql("UPDATE badge SET description = 'Recevoir 5 votes de type Aide parmi toutes ses réponses.' WHERE code = 'mentor'");
        $this->addSql("UPDATE badge SET description = 'Publier 10 sujets.' WHERE code = 'prolific_10'");
        $this->addSql("UPDATE badge SET description = 'Publier 50 sujets.' WHERE code = 'prolific_2'");
        $this->addSql("UPDATE badge SET description = 'Publier 100 sujets.' WHERE code = 'prolific_3'");
        $this->addSql("INSERT INTO badge (code, name, category, description, hidden_description, icon, hidden, xp_reward) VALUES ('sharer', 'Partageur - Partager un contenu du site', 'Communauté', 'Partager un contenu du site.', 'Condition secrète à découvrir', '📤', 0, 100) ON DUPLICATE KEY UPDATE code = code");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM badge WHERE code = 'sharer'");
        $this->addSql("UPDATE badge SET name = 'Ambassadeur', description = 'Partager un contenu du site ou parrainer un nouvel utilisateur.' WHERE code = 'ambassador'");
        $this->addSql("UPDATE badge SET name = 'Pilier de la communauté', description = '30 jours consécutifs' WHERE code = 'pillar'");
    }
}
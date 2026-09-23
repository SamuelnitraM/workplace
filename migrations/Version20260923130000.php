<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unicité des FactionUnit / FactionDetachement sur (bsdata_id, faction) au lieu de bsdata_id seul';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX unique_bsdata_id ON faction_unit');
        $this->addSql('CREATE UNIQUE INDEX unique_bsdata_id_faction ON faction_unit (bsdata_id, faction)');
        $this->addSql('DROP INDEX unique_detachment_bsdata_id ON faction_detachement');
        $this->addSql('CREATE UNIQUE INDEX unique_detachment_bsdata_id_faction ON faction_detachement (bsdata_id, faction)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX unique_bsdata_id_faction ON faction_unit');
        $this->addSql('CREATE UNIQUE INDEX unique_bsdata_id ON faction_unit (bsdata_id)');
        $this->addSql('DROP INDEX unique_detachment_bsdata_id_faction ON faction_detachement');
        $this->addSql('CREATE UNIQUE INDEX unique_detachment_bsdata_id ON faction_detachement (bsdata_id)');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add signature column to utilisateur table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD COLUMN signature LONGTEXT NULL');
    }

    public function down(Schema $schema): void
    {
        // intentionally left empty — do not drop columns per project policy
    }
}

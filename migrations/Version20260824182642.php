<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260824182642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // status comes along so its values can be mapped onto the new marking;
        // the generated diff dropped the column without carrying the data, which
        // would have left every existing item stateless.
        $this->addSql('CREATE TEMPORARY TABLE __temp__item AS SELECT id, client_id, title, description, price, transcript, created_at, status FROM item');
        $this->addSql('DROP TABLE item');
        $this->addSql('CREATE TABLE item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, client_id VARCHAR(64) NOT NULL, title VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, price NUMERIC(8, 2) DEFAULT NULL, transcript CLOB DEFAULT NULL, created_at DATETIME NOT NULL, marking VARCHAR(32) DEFAULT NULL)');
        $this->addSql(<<<'SQL'
            INSERT INTO item (id, client_id, title, description, price, transcript, created_at, marking)
            SELECT id, client_id, title, description, price, transcript, created_at,
                   CASE status
                       WHEN 'captured'  THEN 'new'
                       WHEN 'suggested' THEN 'suggested'
                       WHEN 'priced'    THEN 'priced'
                       ELSE 'new'
                   END
            FROM __temp__item
            SQL);
        $this->addSql('DROP TABLE __temp__item');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1F1B251E19EB6921 ON item (client_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__item AS SELECT id, client_id, title, description, price, transcript, created_at FROM item');
        $this->addSql('DROP TABLE item');
        $this->addSql('CREATE TABLE item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, client_id VARCHAR(64) NOT NULL, title VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, price NUMERIC(8, 2) DEFAULT NULL, transcript CLOB DEFAULT NULL, created_at DATETIME NOT NULL, status VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO item (id, client_id, title, description, price, transcript, created_at) SELECT id, client_id, title, description, price, transcript, created_at FROM __temp__item');
        $this->addSql('DROP TABLE __temp__item');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1F1B251E19EB6921 ON item (client_id)');
    }
}

<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;
use RuntimeException;

/**
 * Etap 1 — model danych zgodny z opisem pracy (docs/MAPA_PROJEKTU.md §2.2, decyzje D1, D5, D7).
 *
 * Każdy krok najpierw sprawdza bieżący kształt schematu, dlatego migracja jest idempotentna:
 * przerwaną można uruchomić ponownie, a na świeżej instalacji z database/schema.sql
 * jedynym efektem jest wstawienie domyślnych szablonów wiadomości.
 */
final class CertificatesModelMigration
{
    /**
     * Słownik typów: certyfikaty i usługi (D7) oraz typy zamrożonego panelu prywatnego (D1).
     *
     * @var list<string>
     */
    public const CERTIFICATE_TYPES = [
        'QUALIFIED_SIGNATURE',
        'QUALIFIED_SEAL',
        'SSL_CERTIFICATE',
        'CODE_SIGNING',
        'DOMAIN',
        'SAAS',
        'CLOUD_SUPPORT',
        'STREAMING',
        'MUSIC',
        'GAMING',
        'FITNESS',
        'CLOUD_STORAGE',
        'OTHER',
    ];

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public static function up(PDO $db): void
    {
        self::renameSubscriptionsToCertificates($db);
        self::extendPayers($db);
        self::createBeneficiaries($db);
        self::extendCertificates($db);
        self::createRenewalTasks($db);
        self::createEmailTemplates($db);
        self::createAttachments($db);
        self::createInvitations($db);
        self::createEvents($db);
        self::insertDefaultTemplates($db);
    }

    private static function renameSubscriptionsToCertificates(PDO $db): void
    {
        if (!SchemaInspector::tableExists($db, 'certificates') && SchemaInspector::tableExists($db, 'subscriptions')) {
            $db->exec('RENAME TABLE subscriptions TO certificates');
        }

        if (!SchemaInspector::tableExists($db, 'certificates')) {
            throw new RuntimeException(
                'Brak tabeli certificates (ani dawnej subscriptions) — zaimportuj database/schema.sql i uruchom migracje ponownie.'
            );
        }
    }

    private static function extendPayers(PDO $db): void
    {
        self::addMissingColumns($db, 'payers', [
            'email'        => 'ADD COLUMN email VARCHAR(255) NULL AFTER tax_id',
            'phone'        => 'ADD COLUMN phone VARCHAR(50) NULL AFTER email',
            'address_line' => 'ADD COLUMN address_line VARCHAR(255) NULL AFTER phone',
            'postal_code'  => 'ADD COLUMN postal_code VARCHAR(16) NULL AFTER address_line',
            'city'         => 'ADD COLUMN city VARCHAR(120) NULL AFTER postal_code',
            'archived_at'  => 'ADD COLUMN archived_at DATETIME NULL AFTER city',
            'updated_at'   => 'ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ]);

        self::addMissingIndexes($db, 'payers', [
            'uq_payers_tax_id'    => 'ADD UNIQUE KEY uq_payers_tax_id (tax_id)',
            'idx_payers_archived' => 'ADD KEY idx_payers_archived (archived_at)',
        ]);
    }

    private static function createBeneficiaries(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS beneficiaries (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                first_name   VARCHAR(100) NOT NULL,
                last_name    VARCHAR(100) NOT NULL,
                email        VARCHAR(255) NULL,
                phone        VARCHAR(50) NULL,
                payer_id     INT UNSIGNED NULL,
                notes        TEXT NULL,
                archived_at  DATETIME NULL,
                created_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_beneficiaries_name (last_name, first_name),
                KEY idx_beneficiaries_email (email),
                KEY idx_beneficiaries_payer (payer_id),
                KEY idx_beneficiaries_archived (archived_at),
                CONSTRAINT fk_beneficiaries_payer
                    FOREIGN KEY (payer_id) REFERENCES payers(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ' . self::TABLE_OPTIONS
        );
    }

    private static function extendCertificates(PDO $db): void
    {
        if (SchemaInspector::columnExists($db, 'certificates', 'subscription_type')) {
            $db->exec(
                'ALTER TABLE certificates CHANGE subscription_type certificate_type '
                . self::typeEnum() . " NOT NULL DEFAULT 'OTHER'"
            );
        }

        self::addMissingColumns($db, 'certificates', [
            'serial_number'           => 'ADD COLUMN serial_number VARCHAR(128) NULL AFTER certificate_type',
            'issuer'                  => 'ADD COLUMN issuer VARCHAR(255) NULL AFTER serial_number',
            'valid_from'              => 'ADD COLUMN valid_from DATE NULL AFTER issuer',
            'renewal_lead_days'       => 'ADD COLUMN renewal_lead_days SMALLINT UNSIGNED NULL AFTER expiry_date',
            'beneficiary_id'          => 'ADD COLUMN beneficiary_id INT UNSIGNED NULL AFTER user_id',
            'previous_certificate_id' => 'ADD COLUMN previous_certificate_id INT UNSIGNED NULL AFTER payer_id',
            'archived_at'             => 'ADD COLUMN archived_at DATETIME NULL AFTER notes',
            'updated_at'              => 'ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ]);

        // Klucze obce i ich indeksy odziedziczyły nazwy po tabeli subscriptions.
        foreach (['user', 'payer'] as $suffix) {
            $legacy = 'fk_subscriptions_' . $suffix;

            if (SchemaInspector::foreignKeyExists($db, 'certificates', $legacy)) {
                $db->exec("ALTER TABLE certificates DROP FOREIGN KEY {$legacy}");
            }

            if (SchemaInspector::indexExists($db, 'certificates', $legacy)) {
                $db->exec("ALTER TABLE certificates RENAME INDEX {$legacy} TO idx_certificates_{$suffix}");
            }
        }

        self::addMissingIndexes($db, 'certificates', [
            'idx_certificates_user'         => 'ADD KEY idx_certificates_user (user_id)',
            'idx_certificates_beneficiary'  => 'ADD KEY idx_certificates_beneficiary (beneficiary_id)',
            'idx_certificates_payer'        => 'ADD KEY idx_certificates_payer (payer_id)',
            'idx_certificates_previous'     => 'ADD KEY idx_certificates_previous (previous_certificate_id)',
            'idx_certificates_type'         => 'ADD KEY idx_certificates_type (certificate_type)',
            'idx_certificates_archived'     => 'ADD KEY idx_certificates_archived (archived_at)',
            'uq_certificates_issuer_serial' => 'ADD UNIQUE KEY uq_certificates_issuer_serial (issuer, serial_number)',
        ]);

        self::addMissingForeignKeys($db, 'certificates', [
            'fk_certificates_user'        => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE',
            'fk_certificates_beneficiary' => 'FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE RESTRICT ON UPDATE CASCADE',
            'fk_certificates_payer'       => 'FOREIGN KEY (payer_id) REFERENCES payers(id) ON DELETE RESTRICT ON UPDATE CASCADE',
            'fk_certificates_previous'    => 'FOREIGN KEY (previous_certificate_id) REFERENCES certificates(id) ON DELETE SET NULL ON UPDATE RESTRICT',
        ]);
    }

    private static function createRenewalTasks(PDO $db): void
    {
        // open_marker = 1 dla zadań otwartych i NULL dla zamkniętych. Unikalny indeks na
        // (certificate_id, open_marker) pozwala więc na co najwyżej jedno otwarte zadanie na certyfikat.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_tasks (
                id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                certificate_id    INT UNSIGNED NOT NULL,
                assigned_user_id  INT UNSIGNED NULL,
                status            ENUM('todo', 'in_progress', 'done', 'abandoned') NOT NULL DEFAULT 'todo',
                priority          ENUM('expired', 'critical', 'warning', 'ok') NOT NULL DEFAULT 'warning',
                due_date          DATE NOT NULL,
                resolution_note   TEXT NULL,
                closed_at         DATETIME NULL,
                created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                open_marker       TINYINT UNSIGNED AS (IF(status IN ('todo', 'in_progress'), 1, NULL)) STORED,
                UNIQUE KEY uq_renewal_tasks_one_open (certificate_id, open_marker),
                KEY idx_renewal_tasks_user (assigned_user_id),
                KEY idx_renewal_tasks_status (status),
                KEY idx_renewal_tasks_due (due_date),
                CONSTRAINT fk_renewal_tasks_certificate
                    FOREIGN KEY (certificate_id) REFERENCES certificates(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_renewal_tasks_user
                    FOREIGN KEY (assigned_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) " . self::TABLE_OPTIONS
        );
    }

    private static function createEmailTemplates(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS email_templates (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code        VARCHAR(64) NOT NULL,
                locale      CHAR(2) NOT NULL DEFAULT 'pl',
                name        VARCHAR(255) NOT NULL,
                subject     VARCHAR(255) NOT NULL,
                body_html   MEDIUMTEXT NOT NULL,
                body_text   MEDIUMTEXT NULL,
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_email_templates_code_locale (code, locale)
            ) " . self::TABLE_OPTIONS
        );
    }

    private static function createAttachments(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS attachments (
                id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                original_name        VARCHAR(255) NOT NULL,
                stored_name          VARCHAR(255) NOT NULL,
                mime_type            VARCHAR(127) NOT NULL,
                size_bytes           INT UNSIGNED NOT NULL,
                sha256               CHAR(64) NOT NULL,
                uploaded_by_user_id  INT UNSIGNED NULL,
                created_at           TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_attachments_stored_name (stored_name),
                KEY idx_attachments_user (uploaded_by_user_id),
                CONSTRAINT fk_attachments_user
                    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ' . self::TABLE_OPTIONS
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS email_template_attachments (
                template_id    INT UNSIGNED NOT NULL,
                attachment_id  INT UNSIGNED NOT NULL,
                PRIMARY KEY (template_id, attachment_id),
                KEY idx_eta_attachment (attachment_id),
                CONSTRAINT fk_eta_template
                    FOREIGN KEY (template_id) REFERENCES email_templates(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_eta_attachment
                    FOREIGN KEY (attachment_id) REFERENCES attachments(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ' . self::TABLE_OPTIONS
        );
    }

    private static function createInvitations(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS invitations (
                id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                certificate_id    INT UNSIGNED NOT NULL,
                renewal_task_id   INT UNSIGNED NULL,
                template_id       INT UNSIGNED NULL,
                recipient_type    ENUM('beneficiary', 'payer') NOT NULL,
                recipient_email   VARCHAR(255) NOT NULL,
                recipient_name    VARCHAR(255) NULL,
                subject           VARCHAR(255) NOT NULL,
                body_html         MEDIUMTEXT NOT NULL,
                status            ENUM('queued', 'sent', 'failed', 'responded', 'closed') NOT NULL DEFAULT 'queued',
                sent_at           DATETIME NULL,
                last_error        TEXT NULL,
                reminder_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_reminder_at  DATETIME NULL,
                next_reminder_at  DATETIME NULL,
                sent_by_user_id   INT UNSIGNED NULL,
                created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_invitations_certificate (certificate_id),
                KEY idx_invitations_task (renewal_task_id),
                KEY idx_invitations_template (template_id),
                KEY idx_invitations_user (sent_by_user_id),
                KEY idx_invitations_status (status),
                KEY idx_invitations_next_reminder (next_reminder_at),
                CONSTRAINT fk_invitations_certificate
                    FOREIGN KEY (certificate_id) REFERENCES certificates(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_invitations_task
                    FOREIGN KEY (renewal_task_id) REFERENCES renewal_tasks(id)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_invitations_template
                    FOREIGN KEY (template_id) REFERENCES email_templates(id)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_invitations_user
                    FOREIGN KEY (sent_by_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) " . self::TABLE_OPTIONS
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS invitation_attachments (
                invitation_id  INT UNSIGNED NOT NULL,
                attachment_id  INT UNSIGNED NOT NULL,
                PRIMARY KEY (invitation_id, attachment_id),
                KEY idx_ia_attachment (attachment_id),
                CONSTRAINT fk_ia_invitation
                    FOREIGN KEY (invitation_id) REFERENCES invitations(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_ia_attachment
                    FOREIGN KEY (attachment_id) REFERENCES attachments(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ' . self::TABLE_OPTIONS
        );
    }

    private static function createEvents(PDO $db): void
    {
        // Dziennik zdarzeń bez kluczy obcych: historia ma przetrwać archiwizację i usunięcie rekordów.
        // Kolumny kontekstu (certificate_id, beneficiary_id, payer_id) pozwalają zbudować oś czasu
        // z perspektywy certyfikatu, użytkownika certyfikatu albo płatnika jednym zapytaniem.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS events (
                id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type     ENUM('certificate', 'beneficiary', 'payer', 'renewal_task', 'invitation', 'email_template', 'attachment', 'user', 'system') NOT NULL,
                entity_id       INT UNSIGNED NULL,
                event_type      VARCHAR(64) NOT NULL,
                user_id         INT UNSIGNED NULL,
                certificate_id  INT UNSIGNED NULL,
                beneficiary_id  INT UNSIGNED NULL,
                payer_id        INT UNSIGNED NULL,
                payload         JSON NULL,
                occurred_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_events_entity (entity_type, entity_id, occurred_at),
                KEY idx_events_certificate (certificate_id, occurred_at),
                KEY idx_events_beneficiary (beneficiary_id, occurred_at),
                KEY idx_events_payer (payer_id, occurred_at),
                KEY idx_events_occurred (occurred_at)
            ) " . self::TABLE_OPTIONS
        );
    }

    /**
     * Wstawia brakujące domyślne szablony (zaproszenie i przypomnienie, PL i EN) — używane także przez testy.
     */
    public static function insertDefaultTemplates(PDO $db): void
    {
        $exists = $db->prepare('SELECT 1 FROM email_templates WHERE code = :code AND locale = :locale LIMIT 1');
        $insert = $db->prepare(
            'INSERT INTO email_templates (code, locale, name, subject, body_html, body_text)
             VALUES (:code, :locale, :name, :subject, :body_html, :body_text)'
        );

        foreach (self::defaultTemplates() as $template) {
            $exists->execute(['code' => $template['code'], 'locale' => $template['locale']]);
            $found = $exists->fetchColumn() !== false;
            $exists->closeCursor();

            if (!$found) {
                $insert->execute($template);
            }
        }
    }

    /**
     * Domyślne szablony zaproszeń i przypomnień. Pola w nawiasach klamrowych wypełnia moduł wysyłki (Etap 3):
     * {imie}, {nazwisko}, {typ_certyfikatu}, {numer_seryjny}, {data_waznosci}, {dni_do_wygasniecia}, {platnik}.
     *
     * @return list<array{code: string, locale: string, name: string, subject: string, body_html: string, body_text: string}>
     */
    private static function defaultTemplates(): array
    {
        return [
            [
                'code'      => 'renewal_invitation',
                'locale'    => 'pl',
                'name'      => 'Zaproszenie do odnowienia certyfikatu',
                'subject'   => 'Zaproszenie do odnowienia: {typ_certyfikatu} nr {numer_seryjny}',
                'body_html' => "<p>Dzień dobry {imie} {nazwisko},</p>\n"
                    . "<p>certyfikat <strong>{typ_certyfikatu}</strong> o numerze seryjnym <strong>{numer_seryjny}</strong> "
                    . "jest ważny do <strong>{data_waznosci}</strong> (pozostało dni: {dni_do_wygasniecia}).</p>\n"
                    . "<p>Zapraszamy do odnowienia. Płatnikiem usługi jest {platnik}. Instrukcja odnowienia jest w załączniku.</p>\n"
                    . '<p>Pozdrawiamy<br>Zespół CertiSub</p>',
                'body_text' => "Dzień dobry {imie} {nazwisko},\n\n"
                    . 'certyfikat {typ_certyfikatu} o numerze seryjnym {numer_seryjny} jest ważny do {data_waznosci} '
                    . "(pozostało dni: {dni_do_wygasniecia}).\n"
                    . "Zapraszamy do odnowienia. Płatnikiem usługi jest {platnik}. Instrukcja odnowienia jest w załączniku.\n\n"
                    . "Pozdrawiamy\nZespół CertiSub",
            ],
            [
                'code'      => 'renewal_reminder',
                'locale'    => 'pl',
                'name'      => 'Przypomnienie o odnowieniu certyfikatu',
                'subject'   => 'Przypomnienie: {typ_certyfikatu} nr {numer_seryjny} wygasa {data_waznosci}',
                'body_html' => "<p>Dzień dobry {imie} {nazwisko},</p>\n"
                    . "<p>przypominamy, że certyfikat <strong>{typ_certyfikatu}</strong> o numerze seryjnym <strong>{numer_seryjny}</strong> "
                    . "wygasa <strong>{data_waznosci}</strong> (pozostało dni: {dni_do_wygasniecia}).</p>\n"
                    . "<p>Jeśli odnowienie jest już w toku, prosimy zignorować tę wiadomość.</p>\n"
                    . '<p>Pozdrawiamy<br>Zespół CertiSub</p>',
                'body_text' => "Dzień dobry {imie} {nazwisko},\n\n"
                    . 'przypominamy, że certyfikat {typ_certyfikatu} o numerze seryjnym {numer_seryjny} wygasa {data_waznosci} '
                    . "(pozostało dni: {dni_do_wygasniecia}).\n"
                    . "Jeśli odnowienie jest już w toku, prosimy zignorować tę wiadomość.\n\n"
                    . "Pozdrawiamy\nZespół CertiSub",
            ],
            [
                'code'      => 'renewal_invitation',
                'locale'    => 'en',
                'name'      => 'Certificate renewal invitation',
                'subject'   => 'Renewal invitation: {typ_certyfikatu} no. {numer_seryjny}',
                'body_html' => "<p>Hello {imie} {nazwisko},</p>\n"
                    . "<p>the <strong>{typ_certyfikatu}</strong> certificate with serial number <strong>{numer_seryjny}</strong> "
                    . "is valid until <strong>{data_waznosci}</strong> ({dni_do_wygasniecia} days left).</p>\n"
                    . "<p>We invite you to renew it. The service is paid by {platnik}. Renewal instructions are attached.</p>\n"
                    . '<p>Kind regards<br>The CertiSub team</p>',
                'body_text' => "Hello {imie} {nazwisko},\n\n"
                    . 'the {typ_certyfikatu} certificate with serial number {numer_seryjny} is valid until {data_waznosci} '
                    . "({dni_do_wygasniecia} days left).\n"
                    . "We invite you to renew it. The service is paid by {platnik}. Renewal instructions are attached.\n\n"
                    . "Kind regards\nThe CertiSub team",
            ],
            [
                'code'      => 'renewal_reminder',
                'locale'    => 'en',
                'name'      => 'Certificate renewal reminder',
                'subject'   => 'Reminder: {typ_certyfikatu} no. {numer_seryjny} expires on {data_waznosci}',
                'body_html' => "<p>Hello {imie} {nazwisko},</p>\n"
                    . "<p>this is a reminder that the <strong>{typ_certyfikatu}</strong> certificate with serial number "
                    . "<strong>{numer_seryjny}</strong> expires on <strong>{data_waznosci}</strong> ({dni_do_wygasniecia} days left).</p>\n"
                    . "<p>If the renewal is already in progress, please ignore this message.</p>\n"
                    . '<p>Kind regards<br>The CertiSub team</p>',
                'body_text' => "Hello {imie} {nazwisko},\n\n"
                    . 'this is a reminder that the {typ_certyfikatu} certificate with serial number {numer_seryjny} '
                    . "expires on {data_waznosci} ({dni_do_wygasniecia} days left).\n"
                    . "If the renewal is already in progress, please ignore this message.\n\n"
                    . "Kind regards\nThe CertiSub team",
            ],
        ];
    }

    /**
     * @param array<string, string> $columns nazwa kolumny => klauzula ALTER TABLE
     */
    private static function addMissingColumns(PDO $db, string $table, array $columns): void
    {
        foreach ($columns as $column => $clause) {
            if (!SchemaInspector::columnExists($db, $table, $column)) {
                $db->exec("ALTER TABLE {$table} {$clause}");
            }
        }
    }

    /**
     * @param array<string, string> $indexes nazwa indeksu => klauzula ALTER TABLE
     */
    private static function addMissingIndexes(PDO $db, string $table, array $indexes): void
    {
        foreach ($indexes as $index => $clause) {
            if (!SchemaInspector::indexExists($db, $table, $index)) {
                $db->exec("ALTER TABLE {$table} {$clause}");
            }
        }
    }

    /**
     * @param array<string, string> $foreignKeys nazwa ograniczenia => definicja klucza obcego
     */
    private static function addMissingForeignKeys(PDO $db, string $table, array $foreignKeys): void
    {
        foreach ($foreignKeys as $name => $definition) {
            if (!SchemaInspector::foreignKeyExists($db, $table, $name)) {
                $db->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
            }
        }
    }

    private static function typeEnum(): string
    {
        return "ENUM('" . implode("', '", self::CERTIFICATE_TYPES) . "')";
    }
}

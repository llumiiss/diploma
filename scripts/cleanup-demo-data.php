<?php

declare(strict_types=1);

/**
 * Removes business data and demo accounts. Keeps real accounts and system data
 * (email templates and their attachments).
 *
 * Deletes: event history, invitations, renewal tasks, ALL certificates, beneficiaries
 * and payers, plus accounts with @example.com addresses.
 *
 *   php scripts/cleanup-demo-data.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;

$db = Database::getInstance()->getConnection();

// Kolejność zgodna z kluczami obcymi: najpierw rekordy zależne.
$steps = [
    'events'                    => 'DELETE FROM events',
    'invitation attachments'    => 'DELETE FROM invitation_attachments',
    'invitations'               => 'DELETE FROM invitations',
    'renewal tasks'             => 'DELETE FROM renewal_tasks',
    'renewal chain links'       => 'UPDATE certificates SET previous_certificate_id = NULL WHERE previous_certificate_id IS NOT NULL',
    'certificates'              => 'DELETE FROM certificates',
    'beneficiaries'             => 'DELETE FROM beneficiaries',
    'payers'                    => 'DELETE FROM payers',
    'demo users (@example.com)' => "DELETE FROM users WHERE email LIKE '%@example.com'",
];

$counts = [];
$db->beginTransaction();

try {
    foreach ($steps as $label => $sql) {
        $counts[$label] = (int) $db->exec($sql);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Cleanup complete.\n";
foreach ($counts as $label => $count) {
    printf("  %-28s %d\n", $label . ':', $count);
}
printf("  %-28s %d\n", 'users remaining:', (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
exit(0);

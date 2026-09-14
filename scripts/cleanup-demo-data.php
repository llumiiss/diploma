<?php

declare(strict_types=1);

/**
 * Removes demo seed data (example.com users, all legacy subscriptions and payers).
 * Keeps real accounts registered through the app.
 *
 *   php scripts/cleanup-demo-data.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;

$db = Database::getInstance()->getConnection();

$db->beginTransaction();

try {
    $deletedSubs = (int) $db->exec('DELETE FROM subscriptions');
    $deletedPayers = (int) $db->exec('DELETE FROM payers');

    $stmt = $db->prepare("DELETE FROM users WHERE email LIKE '%@example.com'");
    $stmt->execute();
    $deletedDemoUsers = $stmt->rowCount();

    $db->commit();

    echo "Cleanup complete.\n";
    echo "  Subscriptions removed: {$deletedSubs}\n";
    echo "  Payers removed: {$deletedPayers}\n";
    echo "  Demo users removed (@example.com): {$deletedDemoUsers}\n";

    $remaining = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "  Users remaining: {$remaining}\n";
    exit(0);
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

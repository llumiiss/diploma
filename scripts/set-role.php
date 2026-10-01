<?php

declare(strict_types=1);

/**
 * Sets the role of an existing account (roles: ADMIN, DIRECTOR, MANAGER, ACCOUNTANT, IT, OPERATOR, EMPLOYEE).
 *
 *   php scripts/set-role.php admin@example.com ADMIN
 *   php scripts/set-role.php --list
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Rbac;
use App\UserManager;

$args = array_values(array_slice($argv ?? [], 1));
$roleList = implode('|', Rbac::roles());

if ($args === [] || in_array('--list', $args, true)) {
    $rows = Database::getInstance()->getConnection()
        ->query('SELECT id, email, role FROM users ORDER BY id')
        ->fetchAll();

    echo "Accounts:\n";
    foreach ($rows as $row) {
        printf("  #%-4d %-40s %s\n", (int) $row['id'], (string) $row['email'], (string) $row['role']);
    }

    if ($args === []) {
        echo "\nUsage: php scripts/set-role.php <email> <{$roleList}>\n";
    }

    exit(0);
}

$email = (string) $args[0];
$role = strtoupper((string) ($args[1] ?? ''));

if ($email === '' || !Rbac::isRole($role)) {
    fwrite(STDERR, "Usage: php scripts/set-role.php <email> <{$roleList}>" . PHP_EOL);
    exit(1);
}

if (!(new UserManager())->setRoleByEmail($email, $role)) {
    fwrite(STDERR, "No account found for {$email}." . PHP_EOL);
    exit(1);
}

echo "Role for {$email} set to {$role}.\n";
exit(0);

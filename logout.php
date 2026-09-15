<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\AuthManager;
use App\Session;
use App\Translator;

$langQ = Translator::init()->querySuffix();

$auth = new AuthManager();
$auth->logout();
Session::destroy();
$logoutSuffix = $langQ !== '' ? $langQ . '&logged_out=1' : '?logged_out=1';
header('Location: login.php' . $logoutSuffix);
exit;

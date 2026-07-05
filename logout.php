<?php
/**
 * logout.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';

secure_session_start();
logout_user();

header('Location: /login.php');
exit;

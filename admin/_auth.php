<?php
/**
 * _auth.php — Include at top of every admin page to enforce login.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config.php';
require_once BOT_DIR . '/Storage.php';
require_once BOT_DIR . '/Lang.php';

if (empty($_SESSION[ADMIN_SESSION_KEY])) {
    header('Location: index.php');
    exit;
}

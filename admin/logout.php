<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/config.php';
unset($_SESSION[ADMIN_SESSION_KEY]);
session_destroy();
header('Location: index.php');
exit;

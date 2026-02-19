<?php
/**
 * SpecLab - Logout
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
session_destroy();
header('Location: ' . BASE_PATH . '/index.php');
exit;

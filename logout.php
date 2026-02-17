<?php
/**
 * SpecLab - Logout
 */
session_start();
session_destroy();
require_once __DIR__ . '/config/database.php';
header('Location: ' . BASE_PATH . '/index.php');
exit;

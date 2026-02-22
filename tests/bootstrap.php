<?php
/**
 * PHPUnit Bootstrap
 * Sets up the test environment without requiring a database or real session.
 */

// Set test environment variables BEFORE any app files load
putenv('APP_ENV=test');
putenv('APP_ENCRYPTION_KEY=test_key_for_unit_tests_only');
putenv('DB_HOST=127.0.0.1');
putenv('DB_NAME=test_db');
putenv('DB_USER=test');
putenv('DB_PASS=test');
putenv('BASE_PATH=/tests');

$_ENV['APP_ENV'] = 'test';

// Start session before auth.php checks it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Autoload (composer)
require_once __DIR__ . '/../vendor/autoload.php';

// Load auth.php (which loads database.php, logger.php, crypto.php)
// Session is already started, so auth.php will skip session_start.
// database.php will use the env vars set above for constants.
// getDB() is defined but never called by sanitize/format functions.
require_once __DIR__ . '/../includes/auth.php';

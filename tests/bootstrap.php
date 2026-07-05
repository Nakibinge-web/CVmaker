<?php
/**
 * PHPUnit bootstrap for CV Maker API tests.
 *
 * Sets up:
 *  - Composer autoloader
 *  - A shared in-memory SQLite PDO instance that mimics the cv_records schema
 *  - A global flag so api.php knows it is running under test (suppresses exit)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Signal to db.php / api.php that we are in test mode BEFORE loading them.
define('PHPUNIT_RUNNING', true);

// Build the in-memory SQLite database once for the whole test run.
// Individual tests reset the data via resetTestDb().
$GLOBALS['_test_pdo'] = (static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Mirror the MySQL schema as closely as SQLite allows.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS cv_records (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            cv_data    TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    SQL);

    return $pdo;
})();

// Load api.php first — it defines ApiResponseException (base class) and all
// route handler functions.  The routing block is guarded by PHPUNIT_RUNNING
// so it will not execute.
require_once __DIR__ . '/../api.php';

// Now load the test exception subclasses (they extend ApiResponseException).
require_once __DIR__ . '/TestExceptions.php';

// Load download.php — its entry point is guarded by PHPUNIT_RUNNING.
require_once __DIR__ . '/../download.php';

/**
 * Reset the cv_records table between tests.
 */
function resetTestDb(): void
{
    $GLOBALS['_test_pdo']->exec('DELETE FROM cv_records');
    // Reset the autoincrement sequence so IDs start from 1 each time.
    $GLOBALS['_test_pdo']->exec("DELETE FROM sqlite_sequence WHERE name='cv_records'");
}

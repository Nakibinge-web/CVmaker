<?php
/**
 * db.php — PDO database connection factory
 *
 * Returns a shared PDO instance configured for the cv_maker database.
 * All queries issued through this connection use prepared statements
 * (Requirement 3.5 / 9.5).
 *
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   $pdo = getDbConnection();
 *   $stmt = $pdo->prepare('SELECT * FROM cv_records WHERE id = :id');
 *   $stmt->execute([':id' => $cvId]);
 */

declare(strict_types=1);

// Load .env file if it exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

/**
 * Returns a singleton PDO connection to the cv_maker database.
 *
 * Configuration is read from environment variables so that credentials
 * are never hard-coded in source files:
 *
 *   DB_HOST   — hostname or IP of the MySQL server  (default: 127.0.0.1)
 *   DB_PORT   — TCP port                            (default: 3306)
 *   DB_NAME   — database name                       (default: cv_maker)
 *   DB_USER   — MySQL username                      (default: root)
 *   DB_PASS   — MySQL password                      (default: empty string)
 *
 * @return PDO
 * @throws RuntimeException if the connection cannot be established.
 */
function getDbConnection(): PDO
{
    // When running under PHPUnit, return the shared in-memory SQLite instance
    // that was created in tests/bootstrap.php.  This avoids any dependency on
    // a real MySQL server during automated testing.
    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        return $GLOBALS['_test_pdo'];
    }

    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host   = getenv('DB_HOST') ?: '127.0.0.1';
    $port   = getenv('DB_PORT') ?: '3306';
    $dbName = getenv('DB_NAME') ?: 'cv_maker';
    $user   = getenv('DB_USER') ?: 'root';
    $pass   = getenv('DB_PASS') ?: '';

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $dbName
    );

    $options = [
        // Throw exceptions on errors instead of returning false/warnings.
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

        // Return rows as associative arrays by default.
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        // Disable emulated prepared statements so the MySQL driver handles
        // parameter binding natively — required by Requirement 3.5 / 9.5.
        PDO::ATTR_EMULATE_PREPARES   => false,

        // Keep the connection alive across requests (persistent connection).
        PDO::ATTR_PERSISTENT         => true,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // Do not expose connection details to the caller.
        throw new RuntimeException(
            'Database connection failed. Check DB_* environment variables.',
            (int) $e->getCode(),
            $e
        );
    }

    return $pdo;
}

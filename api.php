<?php
/**
 * api.php — CV Maker REST API
 *
 * Routes:
 *   POST   /api/cv/save            Create or update a CV record
 *   GET    /api/cv/load?cv_id=X    Retrieve CV data as JSON
 *   GET    /api/cv/preview?cv_id=X Return rendered HTML preview
 *   DELETE /api/cv/delete?cv_id=X  Remove a CV record
 *
 * Requirements: 3.2–3.7, 4.1–4.5, 5.1–5.3, 9.1, 9.4, 9.5, 10.1–10.4
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Base exception for test response interception.
 *
 * In production this class is never instantiated.  Under PHPUnit the test
 * bootstrap loads TestExceptions.php which defines subclasses that extend
 * this class.  The route handlers catch ApiResponseException and re-throw it
 * so that the generic Throwable catch blocks do not swallow test responses.
 */
class ApiResponseException extends \RuntimeException {}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Send a JSON response with the given HTTP status code and exit.
 *
 * During PHPUnit tests the function throws a JsonResponseException instead
 * of calling exit(), allowing tests to inspect the response without
 * terminating the process.
 *
 * @param array<string, mixed> $data
 * @param int                  $status HTTP status code
 * @return never
 */
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $body;

    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        throw new \Tests\JsonResponseException($status, $data, (string) $body);
    }

    exit;
}

/**
 * Validate that $val is a positive integer (cv_id).
 * Sends HTTP 400 and exits if validation fails.
 *
 * Requirements: 9.4
 *
 * @param  mixed $val
 * @return int   Validated positive integer
 */
function validateCvId(mixed $val): int
{
    // Accept only integer strings and native integers; reject floats,
    // empty strings, and anything else.  ctype_digit() returns true for
    // strings composed entirely of decimal digits (no sign, no dot).
    $isIntString = is_string($val) && ctype_digit($val);
    $isNativeInt = is_int($val);

    if (!$isIntString && !$isNativeInt) {
        jsonResponse(['success' => false, 'error' => 'Invalid cv_id: must be a positive integer'], 400);
    }

    $id = (int) $val;

    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid cv_id: must be a positive integer'], 400);
    }

    return $id;
}

/**
 * Strip / encode HTML special characters from a string to prevent XSS.
 *
 * Uses ENT_QUOTES | ENT_SUBSTITUTE so that both single and double quotes
 * are encoded and malformed UTF-8 sequences are replaced rather than
 * causing data loss.
 *
 * Requirements: 9.1
 *
 * @param  string $s Raw user input
 * @return string    Sanitised string safe for storage and HTML output
 */
function sanitiseString(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Recursively sanitise all string values in a decoded JSON structure.
 *
 * Arrays and objects (represented as arrays after json_decode) are
 * traversed depth-first; every leaf string is passed through
 * sanitiseString().
 *
 * Requirements: 9.1
 *
 * @param  mixed $data Decoded JSON value (array, string, int, bool, null …)
 * @return mixed       Same structure with all strings sanitised
 */
function sanitiseData(mixed $data): mixed
{
    if (is_string($data)) {
        return sanitiseString($data);
    }

    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitiseData($value);
        }
        return $data;
    }

    // Integers, floats, booleans, null — pass through unchanged.
    return $data;
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Routing — only execute when not running under PHPUnit
// ---------------------------------------------------------------------------

if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Strip query string from the URI to get the bare path.
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH);

// Normalise trailing slash so /api/cv/save/ also matches.
$path = rtrim((string) $path, '/');

// ---------------------------------------------------------------------------
// POST /api/cv/save
// ---------------------------------------------------------------------------
if ($method === 'POST' && $path === '/api/cv/save') {
    handleSave();
}

// ---------------------------------------------------------------------------
// GET /api/cv/load
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/cv/load') {
    handleLoad();
}

// ---------------------------------------------------------------------------
// GET /api/cv/preview
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/cv/preview') {
    handlePreview();
}

// ---------------------------------------------------------------------------
// DELETE /api/cv/delete
// ---------------------------------------------------------------------------
if ($method === 'DELETE' && $path === '/api/cv/delete') {
    handleDelete();
}

// If no route matched, return 404.
jsonResponse(['success' => false, 'error' => 'Not found'], 404);

} // end if (!PHPUNIT_RUNNING)

// ---------------------------------------------------------------------------
// Route handlers
// ---------------------------------------------------------------------------

/**
 * POST /api/cv/save
 *
 * Accepts a JSON body. If `cv_id` is absent, INSERTs a new record and
 * returns the new id. If `cv_id` is present, validates it and UPDATEs
 * the matching record.
 *
 * All string fields in the payload are recursively sanitised before
 * being persisted.
 *
 * Requirements: 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 9.1, 9.5
 *
 * @return never
 */
function handleSave(): never
{
    // In test mode, allow the test to inject the request body via a global
    // stream handle so we don't depend on php://input being writable in CLI.
    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING && isset($GLOBALS['_test_input_stream'])) {
        $raw = stream_get_contents($GLOBALS['_test_input_stream']);
    } else {
        $raw = file_get_contents('php://input');
    }

    if ($raw === false || $raw === '') {
        jsonResponse(['success' => false, 'error' => 'Empty request body'], 400);
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }

    // Sanitise all string fields recursively before persisting (Req 9.1).
    $sanitised = sanitiseData($payload);

    // Remove cv_id from the stored payload — it is a routing key, not CV
    // content, and storing it inside cv_data would cause confusion on load.
    unset($sanitised['cv_id']);

    // Encode the sanitised data back to JSON for storage.
    $cvDataJson = json_encode($sanitised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($cvDataJson === false) {
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }

    try {
        $pdo = getDbConnection();

        if (isset($payload['cv_id'])) {
            // UPDATE path — validate the supplied cv_id first (Req 9.4).
            $cvId = validateCvId($payload['cv_id']);

            $stmt = $pdo->prepare(
                'UPDATE cv_records SET cv_data = :cv_data WHERE id = :id'
            );
            $stmt->execute([':cv_data' => $cvDataJson, ':id' => $cvId]);

            // If no rows were affected the record does not exist.
            if ($stmt->rowCount() === 0) {
                // Verify the record actually exists (rowCount is 0 also when
                // the data is identical to what is already stored).
                $check = $pdo->prepare('SELECT id FROM cv_records WHERE id = :id');
                $check->execute([':id' => $cvId]);
                if ($check->fetch() === false) {
                    jsonResponse(['success' => false, 'error' => 'Not found'], 404);
                }
            }

            jsonResponse(['success' => true, 'cv_id' => $cvId]);
        } else {
            // INSERT path — create a new record (Req 3.3).
            $stmt = $pdo->prepare(
                'INSERT INTO cv_records (cv_data) VALUES (:cv_data)'
            );
            $stmt->execute([':cv_data' => $cvDataJson]);

            $cvId = (int) $pdo->lastInsertId();

            jsonResponse(['success' => true, 'cv_id' => $cvId]);
        }
    } catch (ApiResponseException $e) {
        // Re-throw test response exceptions so they are not swallowed by the
        // generic error handler below.
        throw $e;
    } catch (Throwable) {
        // Do not expose internal error details to the client (Req 3.7).
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * GET /api/cv/load?cv_id=X
 *
 * Returns the full cv_data JSON for the requested record.
 * Responds with HTTP 404 if the record does not exist.
 *
 * Requirements: 4.1, 4.2, 4.5, 9.4
 *
 * @return never
 */
function handleLoad(): never
{
    $cvId = validateCvId($_GET['cv_id'] ?? null);

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();
    } catch (ApiResponseException $e) {
        throw $e;
    } catch (Throwable) {
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }

    if ($row === false) {
        jsonResponse(['success' => false, 'error' => 'Not found'], 404);
    }

    // cv_data is stored as JSON; decode it so the response is a proper
    // nested object rather than a JSON-encoded string.
    $cvData = json_decode($row['cv_data'], true);

    jsonResponse(['success' => true, 'cv_data' => $cvData]);
}

/**
 * GET /api/cv/preview?cv_id=X
 *
 * Loads the CV record and returns a rendered HTML string.
 * Delegates rendering to renderCVHtml() in template.php.
 * If template.php does not exist, returns a placeholder HTML string so
 * the rest of the API continues to work without the template engine.
 *
 * Requirements: 5.1, 5.2, 5.3
 *
 * @return never
 */
function handlePreview(): never
{
    $cvId = validateCvId($_GET['cv_id'] ?? null);

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();
    } catch (ApiResponseException $e) {
        throw $e;
    } catch (Throwable) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>Internal server error</p>';
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            throw new \Tests\HtmlResponseException(500, '<p>Internal server error</p>');
        }
        exit;
    }

    if ($row === false) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>CV record not found</p>';
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            throw new \Tests\HtmlResponseException(404, '<p>CV record not found</p>');
        }
        exit;
    }

    $cvData = json_decode($row['cv_data'], true) ?? [];

    $templatePath = __DIR__ . '/template.php';

    if (file_exists($templatePath)) {
        require_once $templatePath;
        $html = renderCVHtml($cvData);
    } else {
        // Placeholder until template.php is implemented (Task 4).
        $name = htmlspecialchars((string) ($cvData['contact']['name'] ?? 'CV Preview'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>CV Preview</title></head>
<body>
  <h1>{$name}</h1>
  <p><em>Template engine not yet available. CV data is stored successfully.</em></p>
</body>
</html>
HTML;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        throw new \Tests\HtmlResponseException(200, $html);
    }
    exit;
}

/**
 * DELETE /api/cv/delete?cv_id=X
 *
 * Permanently removes the CV record with the given id.
 * Returns HTTP 404 if no matching record exists.
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 9.4
 *
 * @return never
 */
function handleDelete(): never
{
    $cvId = validateCvId($_GET['cv_id'] ?? null);

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare('DELETE FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Not found'], 404);
        }
    } catch (ApiResponseException $e) {
        throw $e;
    } catch (Throwable) {
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }

    jsonResponse(['success' => true]);
}

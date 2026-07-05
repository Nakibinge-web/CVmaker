<?php
/**
 * download.php — CV Download Handler
 *
 * Streams a CV record as a PDF or DOCX file.
 *
 * Routes:
 *   GET /download?cv_id=X&format=pdf   Stream PDF file
 *   GET /download?cv_id=X&format=docx  Stream DOCX file
 *
 * Requirements: 7.1–7.6, 8.1–8.6, 9.3, 9.4
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/template.php';

// ---------------------------------------------------------------------------
// Helpers (inline re-implementation so download.php is self-contained)
// ---------------------------------------------------------------------------

/**
 * Send a JSON error response with the given HTTP status code and exit.
 *
 * During PHPUnit tests throws a DownloadJsonResponseException instead of
 * calling exit(), allowing tests to inspect the response.
 *
 * @param array<string, mixed> $data
 * @param int                  $status
 * @return never
 */
function downloadJsonError(array $data, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        throw new \Tests\DownloadJsonResponseException($status, $data);
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
function downloadValidateCvId(mixed $val): int
{
    $isIntString = is_string($val) && ctype_digit($val);
    $isNativeInt = is_int($val);

    if (!$isIntString && !$isNativeInt) {
        downloadJsonError(['success' => false, 'error' => 'Invalid cv_id: must be a positive integer'], 400);
    }

    $id = (int) $val;

    if ($id <= 0) {
        downloadJsonError(['success' => false, 'error' => 'Invalid cv_id: must be a positive integer'], 400);
    }

    return $id;
}

// ---------------------------------------------------------------------------
// Main handler
// ---------------------------------------------------------------------------

/**
 * Handle a download request.
 *
 * Validates cv_id and format, loads the CV record from the database, then
 * streams the appropriate binary file to the browser.
 *
 * Requirements: 7.1–7.6, 8.1–8.6, 9.3, 9.4
 *
 * @return never
 */
function handleDownload(): never
{
    // ---- Validate format (Req 9.3) -----------------------------------------
    $format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : '';

    if ($format !== 'pdf' && $format !== 'docx') {
        downloadJsonError(['success' => false, 'error' => 'Invalid format: must be pdf or docx'], 400);
    }

    // ---- Validate cv_id (Req 9.4) ------------------------------------------
    $cvId = downloadValidateCvId($_GET['cv_id'] ?? null);

    // ---- Load CV record from database --------------------------------------
    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();
    } catch (\Throwable) {
        downloadJsonError(['success' => false, 'error' => 'Internal server error'], 500);
    }

    if ($row === false) {
        downloadJsonError(['success' => false, 'error' => 'Not found'], 404);
    }

    $cvData = json_decode($row['cv_data'], true) ?? [];

    // ---- Stream the appropriate format -------------------------------------
    if ($format === 'pdf') {
        streamPdf($cvData);
    } else {
        streamDocx($cvData);
    }
}

/**
 * Render and stream a PDF file.
 *
 * Requirements: 7.2, 7.3, 7.4, 7.5
 *
 * @param  array<string, mixed> $cvData
 * @return never
 */
function streamPdf(array $cvData): never
{
    $dompdf = renderCVPdf($cvData);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cv.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $output = $dompdf->output();

    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        throw new \Tests\DownloadFileResponseException(200, 'application/pdf', 'cv.pdf', (string) $output);
    }

    echo $output;
    exit;
}

/**
 * Render and stream a DOCX file.
 *
 * Requirements: 8.2, 8.3, 8.4, 8.5
 *
 * @param  array<string, mixed> $cvData
 * @return never
 */
function streamDocx(array $cvData): never
{
    $phpWord = renderCVDocx($cvData);

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="cv.docx"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        // Capture the DOCX output to a temp buffer for test inspection.
        $tmpFile = tempnam(sys_get_temp_dir(), 'cv_docx_');
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmpFile);
        $output = (string) file_get_contents($tmpFile);
        unlink($tmpFile);
        throw new \Tests\DownloadFileResponseException(
            200,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'cv.docx',
            $output
        );
    }

    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
    exit;
}

// ---------------------------------------------------------------------------
// Entry point — only execute when not running under PHPUnit
// ---------------------------------------------------------------------------

if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    handleDownload();
}

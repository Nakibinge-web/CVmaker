<?php
/**
 * Test-only exception classes used to intercept API responses during testing.
 *
 * Because api.php calls exit() after sending a response, we replace that
 * behaviour under PHPUnit with these throwable exceptions so tests can
 * inspect the response without terminating the process.
 *
 * Both exceptions extend ApiResponseException (defined in api.php) so that
 * the catch blocks in the route handlers can re-throw them without referencing
 * the Tests\ namespace.
 */

declare(strict_types=1);

namespace Tests;

/**
 * Thrown by jsonResponse() when PHPUNIT_RUNNING is true.
 * Carries the HTTP status code, decoded data array, and raw JSON body.
 */
class JsonResponseException extends \ApiResponseException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int    $statusCode,
        public readonly array  $data,
        public readonly string $body,
    ) {
        parent::__construct("JSON response: {$statusCode} {$body}");
    }
}

/**
 * Thrown by handlePreview() when PHPUNIT_RUNNING is true.
 * Carries the HTTP status code and the HTML body string.
 */
class HtmlResponseException extends \ApiResponseException
{
    public function __construct(
        public readonly int    $statusCode,
        public readonly string $html,
    ) {
        parent::__construct("HTML response: {$statusCode}");
    }
}

/**
 * Thrown by downloadJsonError() in download.php when PHPUNIT_RUNNING is true.
 * Carries the HTTP status code and decoded data array.
 */
class DownloadJsonResponseException extends \ApiResponseException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int   $statusCode,
        public readonly array $data,
    ) {
        parent::__construct("Download JSON response: {$statusCode}");
    }
}

/**
 * Thrown by streamPdf() / streamDocx() in download.php when PHPUNIT_RUNNING is true.
 * Carries the HTTP status code, Content-Type, filename, and binary body.
 */
class DownloadFileResponseException extends \ApiResponseException
{
    public function __construct(
        public readonly int    $statusCode,
        public readonly string $contentType,
        public readonly string $filename,
        public readonly string $body,
    ) {
        parent::__construct("Download file response: {$statusCode} {$contentType} {$filename}");
    }
}

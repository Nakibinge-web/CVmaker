<?php
/**
 * Unit tests for download.php route handler (handleDownload).
 *
 * Tests:
 *   - HTTP 400 for invalid format values
 *   - HTTP 400 for invalid cv_id values
 *   - HTTP 404 for non-existent cv_id
 *   - HTTP 200 with correct Content-Type and filename for PDF
 *   - HTTP 200 with correct Content-Type and filename for DOCX
 *
 * Requirements: 7.1–7.6, 8.1–8.6, 9.3, 9.4
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class DownloadTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Call handleDownload() with the given $_GET parameters.
     * Returns the exception thrown by the handler.
     *
     * @param  array<string, mixed> $params
     */
    private function callDownload(array $params): \ApiResponseException
    {
        $_GET = $params;
        try {
            ob_start();
            handleDownload();
        } catch (\ApiResponseException $e) {
            ob_end_clean();
            return $e;
        }
        ob_end_clean();
        throw new \LogicException('handleDownload() did not throw an ApiResponseException');
    }

    /**
     * Insert a CV record directly into the test DB and return its id.
     *
     * @param  array<string, mixed> $data
     */
    private function insertRecord(array $data): int
    {
        $pdo  = $GLOBALS['_test_pdo'];
        $stmt = $pdo->prepare('INSERT INTO cv_records (cv_data) VALUES (:cv_data)');
        $stmt->execute([':cv_data' => json_encode($data)]);
        return (int) $pdo->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // setUp / tearDown
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        resetTestDb();
        $_GET = [];
    }

    // -----------------------------------------------------------------------
    // Format validation — Requirement 9.3
    // -----------------------------------------------------------------------

    #[Test]
    public function download_returns_400_for_missing_format(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Alice']]);

        $response = $this->callDownload(['cv_id' => (string) $id]);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function download_returns_400_for_invalid_format_value(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Alice']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'txt']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function download_returns_400_for_html_format(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Alice']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'html']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
    }

    #[Test]
    public function download_returns_400_for_empty_format(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Alice']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => '']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // cv_id validation — Requirement 9.4
    // -----------------------------------------------------------------------

    #[Test]
    public function download_returns_400_for_missing_cv_id(): void
    {
        $response = $this->callDownload(['format' => 'pdf']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function download_returns_400_for_non_integer_cv_id(): void
    {
        $response = $this->callDownload(['cv_id' => 'abc', 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
    }

    #[Test]
    public function download_returns_400_for_zero_cv_id(): void
    {
        $response = $this->callDownload(['cv_id' => '0', 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
    }

    #[Test]
    public function download_returns_400_for_negative_cv_id(): void
    {
        $response = $this->callDownload(['cv_id' => '-1', 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(400, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // 404 for non-existent cv_id — Requirements 7.6, 8.6
    // -----------------------------------------------------------------------

    #[Test]
    public function download_pdf_returns_404_for_nonexistent_cv_id(): void
    {
        $response = $this->callDownload(['cv_id' => '9999', 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(404, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function download_docx_returns_404_for_nonexistent_cv_id(): void
    {
        $response = $this->callDownload(['cv_id' => '9999', 'format' => 'docx']);

        $this->assertInstanceOf(DownloadJsonResponseException::class, $response);
        $this->assertSame(404, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    // -----------------------------------------------------------------------
    // PDF streaming — Requirements 7.1–7.5
    // -----------------------------------------------------------------------

    #[Test]
    public function download_pdf_returns_200_with_correct_content_type(): void
    {
        $id = $this->insertRecord([
            'contact' => ['name' => 'Bob', 'email' => 'bob@example.com'],
            'summary' => 'A professional summary.',
        ]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/pdf', $response->contentType);
    }

    #[Test]
    public function download_pdf_sets_attachment_filename(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Carol']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertSame('cv.pdf', $response->filename);
    }

    #[Test]
    public function download_pdf_streams_non_empty_binary_content(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Dave']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'pdf']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertNotEmpty($response->body);
        // PDF files start with the %PDF magic bytes.
        $this->assertStringStartsWith('%PDF', $response->body);
    }

    #[Test]
    public function download_pdf_format_is_case_insensitive(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Eve']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'PDF']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertSame('application/pdf', $response->contentType);
    }

    // -----------------------------------------------------------------------
    // DOCX streaming — Requirements 8.1–8.5
    // -----------------------------------------------------------------------

    #[Test]
    public function download_docx_returns_200_with_correct_content_type(): void
    {
        $id = $this->insertRecord([
            'contact' => ['name' => 'Frank', 'email' => 'frank@example.com'],
            'summary' => 'A professional summary.',
        ]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'docx']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $response->contentType
        );
    }

    #[Test]
    public function download_docx_sets_attachment_filename(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Grace']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'docx']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertSame('cv.docx', $response->filename);
    }

    #[Test]
    public function download_docx_streams_non_empty_binary_content(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Henry']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'docx']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertNotEmpty($response->body);
        // DOCX files are ZIP archives; they start with the PK magic bytes.
        $this->assertStringStartsWith('PK', $response->body);
    }

    #[Test]
    public function download_docx_format_is_case_insensitive(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Iris']]);

        $response = $this->callDownload(['cv_id' => (string) $id, 'format' => 'DOCX']);

        $this->assertInstanceOf(DownloadFileResponseException::class, $response);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $response->contentType
        );
    }
}

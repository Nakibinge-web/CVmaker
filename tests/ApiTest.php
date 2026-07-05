<?php
/**
 * Integration tests for api.php route handlers:
 *   - POST /api/cv/save   (create and update)
 *   - GET  /api/cv/load
 *   - GET  /api/cv/preview
 *   - DELETE /api/cv/delete
 *
 * Requirements: 3.2–3.7, 4.1–4.5, 5.1–5.3, 9.1, 9.4, 9.5, 10.1–10.4
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ApiTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Call handleSave() with the given payload as the request body.
     * Returns the JsonResponseException thrown by jsonResponse().
     *
     * @param  array<string, mixed> $payload
     */
    private function callSave(array $payload): JsonResponseException
    {
        // Simulate php://input by writing to a temp stream.
        $json = json_encode($payload);

        // Override the input stream used by file_get_contents('php://input').
        // We use a stream wrapper trick: write to a temp file and set
        // STDIN to point to it.  The simplest approach for unit tests is to
        // use a custom stream wrapper, but the easiest is to use a temp file.
        $tmpFile = tempnam(sys_get_temp_dir(), 'cv_test_');
        file_put_contents($tmpFile, $json);

        // Redirect php://input to our temp file via a stream wrapper.
        // PHPUnit runs in CLI mode so we can reopen STDIN.
        $GLOBALS['_test_input_stream'] = fopen($tmpFile, 'r');

        try {
            ob_start();
            handleSave();
        } catch (JsonResponseException $e) {
            ob_end_clean();
            fclose($GLOBALS['_test_input_stream']);
            unlink($tmpFile);
            return $e;
        }

        ob_end_clean();
        fclose($GLOBALS['_test_input_stream']);
        unlink($tmpFile);
        throw new \LogicException('handleSave() did not throw JsonResponseException');
    }

    /**
     * Call handleLoad() with the given cv_id in $_GET.
     */
    private function callLoad(mixed $cvId): JsonResponseException
    {
        $_GET['cv_id'] = $cvId;
        try {
            ob_start();
            handleLoad();
        } catch (JsonResponseException $e) {
            ob_end_clean();
            return $e;
        }
        ob_end_clean();
        throw new \LogicException('handleLoad() did not throw JsonResponseException');
    }

    /**
     * Call handlePreview() with the given cv_id in $_GET.
     * Returns an HtmlResponseException.
     */
    private function callPreview(mixed $cvId): HtmlResponseException|JsonResponseException
    {
        $_GET['cv_id'] = $cvId;
        try {
            ob_start();
            handlePreview();
        } catch (HtmlResponseException|JsonResponseException $e) {
            ob_end_clean();
            return $e;
        }
        ob_end_clean();
        throw new \LogicException('handlePreview() did not throw a response exception');
    }

    /**
     * Call handleDelete() with the given cv_id in $_GET.
     */
    private function callDelete(mixed $cvId): JsonResponseException
    {
        $_GET['cv_id'] = $cvId;
        try {
            ob_start();
            handleDelete();
        } catch (JsonResponseException $e) {
            ob_end_clean();
            return $e;
        }
        ob_end_clean();
        throw new \LogicException('handleDelete() did not throw JsonResponseException');
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
        // Clear any leftover $_GET state.
        $_GET = [];
    }

    // -----------------------------------------------------------------------
    // POST /api/cv/save — create (no cv_id)
    // -----------------------------------------------------------------------

    #[Test]
    public function save_creates_new_record_and_returns_cv_id(): void
    {
        $response = $this->callSave(['contact' => ['name' => 'Alice']]);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('cv_id', $response->data);
        $this->assertIsInt($response->data['cv_id']);
        $this->assertGreaterThan(0, $response->data['cv_id']);
    }

    #[Test]
    public function save_persists_data_to_database(): void
    {
        $response = $this->callSave(['contact' => ['name' => 'Bob']]);
        $cvId     = $response->data['cv_id'];

        $pdo  = $GLOBALS['_test_pdo'];
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();

        $this->assertNotFalse($row);
        $stored = json_decode($row['cv_data'], true);
        $this->assertSame('Bob', $stored['contact']['name']);
    }

    #[Test]
    public function save_sanitises_html_in_string_fields(): void
    {
        $response = $this->callSave(['contact' => ['name' => '<script>alert(1)</script>']]);
        $cvId     = $response->data['cv_id'];

        $pdo  = $GLOBALS['_test_pdo'];
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();

        $stored = json_decode($row['cv_data'], true);
        $this->assertStringNotContainsString('<script>', $stored['contact']['name']);
        $this->assertStringContainsString('&lt;script&gt;', $stored['contact']['name']);
    }

    #[Test]
    public function save_does_not_store_cv_id_inside_cv_data(): void
    {
        // First create a record to get a valid cv_id.
        $createResponse = $this->callSave(['contact' => ['name' => 'Carol']]);
        $cvId = $createResponse->data['cv_id'];

        // Now update it — the cv_id in the payload should not end up in cv_data.
        $this->callSave(['cv_id' => $cvId, 'contact' => ['name' => 'Carol Updated']]);

        $pdo  = $GLOBALS['_test_pdo'];
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();

        $stored = json_decode($row['cv_data'], true);
        $this->assertArrayNotHasKey('cv_id', $stored);
    }

    #[Test]
    public function save_returns_400_for_empty_body(): void
    {
        // Simulate empty body by passing an empty JSON object that decodes to
        // an array — actually we need to test the truly empty body path.
        // We do this by temporarily overriding the input stream.
        $tmpFile = tempnam(sys_get_temp_dir(), 'cv_test_');
        file_put_contents($tmpFile, '');
        $GLOBALS['_test_input_stream'] = fopen($tmpFile, 'r');

        try {
            ob_start();
            handleSave();
        } catch (JsonResponseException $e) {
            ob_end_clean();
            fclose($GLOBALS['_test_input_stream']);
            unlink($tmpFile);
            $this->assertSame(400, $e->statusCode);
            $this->assertFalse($e->data['success']);
            return;
        }

        ob_end_clean();
        fclose($GLOBALS['_test_input_stream']);
        unlink($tmpFile);
        $this->fail('Expected JsonResponseException for empty body');
    }

    #[Test]
    public function save_returns_400_for_invalid_json(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cv_test_');
        file_put_contents($tmpFile, 'not-json');
        $GLOBALS['_test_input_stream'] = fopen($tmpFile, 'r');

        try {
            ob_start();
            handleSave();
        } catch (JsonResponseException $e) {
            ob_end_clean();
            fclose($GLOBALS['_test_input_stream']);
            unlink($tmpFile);
            $this->assertSame(400, $e->statusCode);
            return;
        }

        ob_end_clean();
        fclose($GLOBALS['_test_input_stream']);
        unlink($tmpFile);
        $this->fail('Expected JsonResponseException for invalid JSON');
    }

    // -----------------------------------------------------------------------
    // POST /api/cv/save — update (with cv_id)
    // -----------------------------------------------------------------------

    #[Test]
    public function save_updates_existing_record_when_cv_id_provided(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Original']]);

        $response = $this->callSave(['cv_id' => $id, 'contact' => ['name' => 'Updated']]);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->data['success']);
        $this->assertSame($id, $response->data['cv_id']);

        // Verify the DB was actually updated.
        $pdo  = $GLOBALS['_test_pdo'];
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row  = $stmt->fetch();
        $stored = json_decode($row['cv_data'], true);
        $this->assertSame('Updated', $stored['contact']['name']);
    }

    #[Test]
    public function save_returns_404_when_updating_nonexistent_cv_id(): void
    {
        $response = $this->callSave(['cv_id' => 9999, 'contact' => ['name' => 'Ghost']]);

        $this->assertSame(404, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function save_returns_400_for_invalid_cv_id_on_update(): void
    {
        $response = $this->callSave(['cv_id' => 'bad', 'contact' => ['name' => 'X']]);

        $this->assertSame(400, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    // -----------------------------------------------------------------------
    // GET /api/cv/load
    // -----------------------------------------------------------------------

    #[Test]
    public function load_returns_cv_data_for_existing_record(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Dave']]);

        $response = $this->callLoad((string) $id);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('cv_data', $response->data);
        $this->assertSame('Dave', $response->data['cv_data']['contact']['name']);
    }

    #[Test]
    public function load_returns_404_for_nonexistent_cv_id(): void
    {
        $response = $this->callLoad('9999');

        $this->assertSame(404, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function load_returns_400_for_invalid_cv_id(): void
    {
        $response = $this->callLoad('abc');

        $this->assertSame(400, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function load_returns_400_when_cv_id_is_missing(): void
    {
        $response = $this->callLoad(null);

        $this->assertSame(400, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // GET /api/cv/preview
    // -----------------------------------------------------------------------

    #[Test]
    public function preview_returns_html_for_existing_record(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Eve']]);

        $response = $this->callPreview((string) $id);

        $this->assertInstanceOf(HtmlResponseException::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertNotEmpty($response->html);
    }

    #[Test]
    public function preview_returns_404_for_nonexistent_cv_id(): void
    {
        $response = $this->callPreview('9999');

        $this->assertSame(404, $response->statusCode);
    }

    #[Test]
    public function preview_returns_400_for_invalid_cv_id(): void
    {
        $response = $this->callPreview('bad');

        $this->assertSame(400, $response->statusCode);
    }

    #[Test]
    public function preview_html_contains_cv_name_when_no_template(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Frank']]);

        $response = $this->callPreview((string) $id);

        $this->assertInstanceOf(HtmlResponseException::class, $response);
        $this->assertStringContainsString('Frank', $response->html);
    }

    // -----------------------------------------------------------------------
    // DELETE /api/cv/delete
    // -----------------------------------------------------------------------

    #[Test]
    public function delete_removes_existing_record_and_returns_success(): void
    {
        $id = $this->insertRecord(['contact' => ['name' => 'Grace']]);

        $response = $this->callDelete((string) $id);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->data['success']);

        // Verify the record is gone.
        $pdo  = $GLOBALS['_test_pdo'];
        $stmt = $pdo->prepare('SELECT id FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $this->assertFalse($stmt->fetch());
    }

    #[Test]
    public function delete_returns_404_for_nonexistent_cv_id(): void
    {
        $response = $this->callDelete('9999');

        $this->assertSame(404, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function delete_returns_400_for_invalid_cv_id(): void
    {
        $response = $this->callDelete('abc');

        $this->assertSame(400, $response->statusCode);
        $this->assertFalse($response->data['success']);
    }

    #[Test]
    public function delete_returns_400_when_cv_id_is_missing(): void
    {
        $response = $this->callDelete(null);

        $this->assertSame(400, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // End-to-end: save → load → delete
    // -----------------------------------------------------------------------

    #[Test]
    public function full_lifecycle_save_load_delete(): void
    {
        // 1. Create
        $saveResponse = $this->callSave([
            'contact' => ['name' => 'Henry', 'email' => 'henry@example.com'],
            'summary' => 'Experienced developer',
        ]);
        $this->assertSame(200, $saveResponse->statusCode);
        $cvId = $saveResponse->data['cv_id'];

        // 2. Load
        $loadResponse = $this->callLoad((string) $cvId);
        $this->assertSame(200, $loadResponse->statusCode);
        $this->assertSame('Henry', $loadResponse->data['cv_data']['contact']['name']);

        // 3. Update
        $updateResponse = $this->callSave([
            'cv_id'   => $cvId,
            'contact' => ['name' => 'Henry Updated', 'email' => 'henry@example.com'],
        ]);
        $this->assertSame(200, $updateResponse->statusCode);
        $this->assertSame($cvId, $updateResponse->data['cv_id']);

        // 4. Verify update
        $loadAfterUpdate = $this->callLoad((string) $cvId);
        $this->assertSame('Henry Updated', $loadAfterUpdate->data['cv_data']['contact']['name']);

        // 5. Delete
        $deleteResponse = $this->callDelete((string) $cvId);
        $this->assertSame(200, $deleteResponse->statusCode);

        // 6. Verify gone
        $loadAfterDelete = $this->callLoad((string) $cvId);
        $this->assertSame(404, $loadAfterDelete->statusCode);
    }
}

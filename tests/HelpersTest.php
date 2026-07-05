<?php
/**
 * Unit tests for api.php helper functions:
 *   - validateCvId()
 *   - sanitiseString()
 *   - sanitiseData()
 *
 * Requirements: 9.1, 9.4
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class HelpersTest extends TestCase
{
    // -----------------------------------------------------------------------
    // validateCvId
    // -----------------------------------------------------------------------

    #[Test]
    public function validateCvId_accepts_positive_integer_string(): void
    {
        $this->assertSame(1, validateCvId('1'));
        $this->assertSame(42, validateCvId('42'));
        $this->assertSame(999999, validateCvId('999999'));
    }

    #[Test]
    public function validateCvId_accepts_native_positive_integer(): void
    {
        $this->assertSame(5, validateCvId(5));
        $this->assertSame(100, validateCvId(100));
    }

    #[Test]
    public function validateCvId_rejects_zero_string(): void
    {
        $this->expectException(JsonResponseException::class);
        $this->expectExceptionMessage('400');
        validateCvId('0');
    }

    #[Test]
    public function validateCvId_rejects_zero_integer(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId(0);
    }

    #[Test]
    public function validateCvId_rejects_negative_string(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId('-1');
    }

    #[Test]
    public function validateCvId_rejects_negative_integer(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId(-5);
    }

    #[Test]
    public function validateCvId_rejects_float_string(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId('1.5');
    }

    #[Test]
    public function validateCvId_rejects_empty_string(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId('');
    }

    #[Test]
    public function validateCvId_rejects_null(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId(null);
    }

    #[Test]
    public function validateCvId_rejects_alphabetic_string(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId('abc');
    }

    #[Test]
    public function validateCvId_rejects_alphanumeric_string(): void
    {
        $this->expectException(JsonResponseException::class);
        validateCvId('1abc');
    }

    #[Test]
    public function validateCvId_returns_http_400_status(): void
    {
        try {
            validateCvId('bad');
            $this->fail('Expected JsonResponseException');
        } catch (JsonResponseException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertFalse($e->data['success']);
        }
    }

    // -----------------------------------------------------------------------
    // sanitiseString
    // -----------------------------------------------------------------------

    #[Test]
    public function sanitiseString_encodes_html_angle_brackets(): void
    {
        $this->assertSame('&lt;script&gt;', sanitiseString('<script>'));
    }

    #[Test]
    public function sanitiseString_encodes_double_quotes(): void
    {
        $this->assertSame('&quot;hello&quot;', sanitiseString('"hello"'));
    }

    #[Test]
    public function sanitiseString_encodes_single_quotes(): void
    {
        $this->assertSame('&#039;hello&#039;', sanitiseString("'hello'"));
    }

    #[Test]
    public function sanitiseString_encodes_ampersand(): void
    {
        $this->assertSame('Tom &amp; Jerry', sanitiseString('Tom & Jerry'));
    }

    #[Test]
    public function sanitiseString_encodes_xss_payload(): void
    {
        $input    = '<script>alert("xss")</script>';
        $result   = sanitiseString($input);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
    }

    #[Test]
    public function sanitiseString_leaves_plain_text_unchanged(): void
    {
        $this->assertSame('Hello World', sanitiseString('Hello World'));
        $this->assertSame('John Doe', sanitiseString('John Doe'));
    }

    #[Test]
    public function sanitiseString_leaves_empty_string_unchanged(): void
    {
        $this->assertSame('', sanitiseString(''));
    }

    // -----------------------------------------------------------------------
    // sanitiseData
    // -----------------------------------------------------------------------

    #[Test]
    public function sanitiseData_sanitises_flat_string(): void
    {
        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', sanitiseData('<b>bold</b>'));
    }

    #[Test]
    public function sanitiseData_passes_through_integers(): void
    {
        $this->assertSame(42, sanitiseData(42));
    }

    #[Test]
    public function sanitiseData_passes_through_booleans(): void
    {
        $this->assertTrue(sanitiseData(true));
        $this->assertFalse(sanitiseData(false));
    }

    #[Test]
    public function sanitiseData_passes_through_null(): void
    {
        $this->assertNull(sanitiseData(null));
    }

    #[Test]
    public function sanitiseData_recursively_sanitises_nested_array(): void
    {
        $input = [
            'name'    => '<b>Alice</b>',
            'age'     => 30,
            'address' => [
                'street' => '1 & 2 Main St',
                'city'   => 'London',
            ],
        ];

        $result = sanitiseData($input);

        $this->assertSame('&lt;b&gt;Alice&lt;/b&gt;', $result['name']);
        $this->assertSame(30, $result['age']);
        $this->assertSame('1 &amp; 2 Main St', $result['address']['street']);
        $this->assertSame('London', $result['address']['city']);
    }

    #[Test]
    public function sanitiseData_handles_empty_array(): void
    {
        $this->assertSame([], sanitiseData([]));
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Unit\SDK\Common\Export\Http;

use OpenTelemetry\SDK\Common\Export\Http\PsrUtils;
use OpenTelemetry\SDK\Common\Export\Http\ResponseBodySizeLimit;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Tests for response body size limitation (issue #1932).
 *
 * @covers \OpenTelemetry\SDK\Common\Export\Http\PsrUtils
 * @covers \OpenTelemetry\SDK\Common\Export\Http\ResponseBodySizeLimit
 */
final class ResponseBodySizeLimitTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ResponseBodySizeLimit constant
    // -------------------------------------------------------------------------

    public function test_max_bytes_constant_is_four_mib(): void
    {
        $this->assertSame(4 * 1024 * 1024, ResponseBodySizeLimit::MAX_BYTES);
    }

    // -------------------------------------------------------------------------
    // PsrUtils::decode – plain (no compression) with size limit
    // -------------------------------------------------------------------------

    public function test_decode_returns_plain_body_when_no_content_encoding(): void
    {
        $payload = 'protobuf-bytes';
        $response = $this->makeStreamResponse($payload);

        $this->assertSame($payload, PsrUtils::decode($response, []));
    }

    public function test_decode_returns_empty_string_for_empty_body(): void
    {
        $response = $this->makeStreamResponse('');

        $this->assertSame('', PsrUtils::decode($response, []));
    }

    public function test_decode_truncates_plain_body_at_max_bytes(): void
    {
        $oversized = str_repeat('A', ResponseBodySizeLimit::MAX_BYTES + 9999);

        // Simulate a stream that returns data in chunks.
        $stream = $this->createChunkedStream($oversized, 8192);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $result = PsrUtils::decode($response, []);

        $this->assertSame(ResponseBodySizeLimit::MAX_BYTES, strlen($result));
    }

    // -------------------------------------------------------------------------
    // PsrUtils::decode – gzip with decompressed size limit
    // -------------------------------------------------------------------------

    public function test_decode_decompresses_gzip_body(): void
    {
        $original = 'hello opentelemetry';
        $compressed = gzencode($original);

        $response = $this->makeStreamResponse($compressed);

        $this->assertSame($original, PsrUtils::decode($response, ['gzip']));
    }

    public function test_decode_enforces_limit_on_decompressed_size(): void
    {
        // Create a payload that is small when compressed but large decompressed.
        $original = str_repeat('X', ResponseBodySizeLimit::MAX_BYTES + 5000);
        $compressed = gzencode($original);

        $response = $this->makeStreamResponse($compressed);

        $result = PsrUtils::decode($response, ['gzip']);

        $this->assertSame(ResponseBodySizeLimit::MAX_BYTES, strlen($result));
    }

    public function test_decode_throws_on_invalid_gzip_body(): void
    {
        $response = $this->makeStreamResponse('not-valid-gzip');

        $this->expectException(\Throwable::class);

        PsrUtils::decode($response, ['gzip']);
    }

    public function test_decode_throws_on_unsupported_encoding(): void
    {
        $response = $this->makeStreamResponse('foo');

        $this->expectException(\UnexpectedValueException::class);

        PsrUtils::decode($response, ['invalid']);
    }

    public function test_decode_identity_encoding_treated_as_plain(): void
    {
        $payload = 'test-data';
        $response = $this->makeStreamResponse($payload);

        $this->assertSame($payload, PsrUtils::decode($response, ['identity']));
    }

    // -------------------------------------------------------------------------
    // Read loop: stream that returns partial chunks
    // -------------------------------------------------------------------------

    public function test_decode_handles_stream_returning_partial_reads(): void
    {
        $payload = 'abcdefghij'; // 10 bytes

        // Stream returns 3 bytes at a time.
        $stream = $this->createChunkedStream($payload, 3);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $result = PsrUtils::decode($response, []);

        $this->assertSame($payload, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a response whose body stream returns all content from __toString()
     * or via read() in a single call.
     */
    private function makeStreamResponse(string $bodyContent): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($bodyContent);

        // For uncompressed reads (readWithLimit), simulate chunked reading.
        $offset = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$offset, $bodyContent) {
            return $offset >= strlen($bodyContent);
        });
        $stream->method('read')->willReturnCallback(function (int $length) use (&$offset, $bodyContent) {
            $chunk = substr($bodyContent, $offset, $length);
            $offset += strlen($chunk);

            return $chunk;
        });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    /**
     * Create a stream that returns data in fixed-size chunks.
     */
    private function createChunkedStream(string $data, int $chunkSize): StreamInterface
    {
        $offset = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturnCallback(function () use (&$offset, $data) {
            return $offset >= strlen($data);
        });
        $stream->method('read')->willReturnCallback(function (int $length) use (&$offset, $data, $chunkSize) {
            $readSize = min($length, $chunkSize, strlen($data) - $offset);
            $chunk = substr($data, $offset, $readSize);
            $offset += $readSize;

            return $chunk;
        });
        $stream->method('__toString')->willReturn($data);

        return $stream;
    }
}

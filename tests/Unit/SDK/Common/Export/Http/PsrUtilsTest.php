<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace OpenTelemetry\Tests\Unit\SDK\Common\Export\Http;

use function date;
use const DATE_RFC7231;
use function gzdecode;
use function gzencode;
use Nyholm\Psr7\Response;
use OpenTelemetry\SDK\Common\Export\Http\PsrUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use function time;
use UnexpectedValueException;

#[CoversClass(PsrUtils::class)]
final class PsrUtilsTest extends TestCase
{
    public function test_retry_delay_initial(): void
    {
        $delay = PsrUtils::retryDelay(0, 1000);
        $this->assertGreaterThanOrEqual(.5, $delay);
        $this->assertLessThanOrEqual(1, $delay);
    }

    public function test_retry_delay_nth(): void
    {
        $delay = PsrUtils::retryDelay(2, 1000);
        $this->assertGreaterThanOrEqual(2, $delay);
        $this->assertLessThanOrEqual(4, $delay);
    }

    public function test_retry_delay_response_without_retry_after(): void
    {
        $delay = PsrUtils::retryDelay(2, 1000, new Response());
        $this->assertGreaterThanOrEqual(2, $delay);
        $this->assertLessThanOrEqual(4, $delay);
    }

    public function test_retry_delay_response_with_invalid_retry_after(): void
    {
        $delay = PsrUtils::retryDelay(2, 1000, (new Response())->withHeader('Retry-After', 'invalid'));
        $this->assertGreaterThanOrEqual(2, $delay);
        $this->assertLessThanOrEqual(4, $delay);
    }

    public function test_retry_delay_respects_response_retry_after(): void
    {
        $delay = PsrUtils::retryDelay(2, 1000, (new Response())->withHeader('Retry-After', '6'));
        $this->assertGreaterThan(4, $delay);
    }

    public function test_retry_delay_respects_response_retry_after_date(): void
    {
        $delay = PsrUtils::retryDelay(2, 1000, (new Response())->withHeader('Retry-After', date(DATE_RFC7231, time() + 6)));
        $this->assertGreaterThan(4, $delay);
    }

    public function test_retry_delay_uses_exponential_backoff_if_exceeds_retry_after(): void
    {
        $delay = PsrUtils::retryDelay(2, 1000, (new Response())->withHeader('Retry-After', '2'));
        $this->assertGreaterThanOrEqual(2, $delay);
    }

    public function test_encode_stream(): void
    {
        $value = PsrUtils::encode('abc', ['gzip']);
        $this->assertSame('abc', gzdecode($value));
    }

    public function test_decode_stream(): void
    {
        $value = PsrUtils::decode(self::makeStreamResponse(gzencode('abc')), ['gzip']);
        $this->assertSame('abc', $value);
    }

    public function test_encode_stream_unknown_encoding(): void
    {
        PsrUtils::encode('', ['invalid'], $appliedEncodings);
        $this->assertSame([], $appliedEncodings);
    }

    public function test_decode_stream_unknown_encoding(): void
    {
        $this->expectException(UnexpectedValueException::class);

        PsrUtils::decode(self::makeStreamResponse('foo'), ['invalid']);
    }

    public function test_decode_empty_value(): void
    {
        $this->assertSame('', PsrUtils::decode(self::makeStreamResponse(''), ['gzip']));
    }

    #[DataProvider('compressionProvider')]
    public function test_resolve_compression($input, $expected): void
    {
        $this->assertSame($expected, PsrUtils::compression($input));
    }

    public static function compressionProvider(): array
    {
        return [
            ['gzip', ['gzip']],
            ['', []],
            ['gzip,br', ['gzip','br']],
            ['gzip , brotli', ['gzip','brotli']],
            [['gzip'], ['gzip']],
        ];
    }

    private static function makeStreamResponse(string $bodyContent): ResponseInterface
    {
        $stream = new class($bodyContent) implements StreamInterface {
            private int $offset = 0;

            public function __construct(private readonly string $data)
            {
            }

            public function __toString(): string
            {
                return $this->data;
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->data);
            }

            public function tell(): int
            {
                return $this->offset;
            }

            public function eof(): bool
            {
                return $this->offset >= strlen($this->data);
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }

            public function rewind(): void
            {
                $this->offset = 0;
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $chunk = substr($this->data, $this->offset, $length);
                $this->offset += strlen($chunk);

                return $chunk;
            }

            public function getContents(): string
            {
                return substr($this->data, $this->offset);
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };

        return new Response(200, [], $stream);
    }
}

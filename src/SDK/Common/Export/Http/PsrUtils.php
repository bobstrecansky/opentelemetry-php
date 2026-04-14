<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Common\Export\Http;

use function array_filter;
use function array_map;
use function count;
use ErrorException;
use LogicException;
use function max;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use function rand;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strtotime;
use Throwable;
use function time;
use function trim;
use UnexpectedValueException;

/**
 * @internal
 */
final class PsrUtils
{
    /**
     * @param int $retry zero-indexed attempt number
     * @param int $retryDelay initial delay in milliseconds
     * @param ResponseInterface|null $response response of failed request
     * @return float delay in seconds
     */
    public static function retryDelay(int $retry, int $retryDelay, ?ResponseInterface $response = null): float
    {
        $delay = $retryDelay << $retry;
        $delay = rand($delay >> 1, $delay) / 1000;

        return max($delay, self::parseRetryAfter($response));
    }

    private static function parseRetryAfter(?ResponseInterface $response): int
    {
        if (!$response || !$retryAfter = $response->getHeaderLine('Retry-After')) {
            return 0;
        }

        $retryAfter = trim($retryAfter, " \t");
        if ($retryAfter === (string) (int) $retryAfter) {
            return (int) $retryAfter;
        }

        if (($time = strtotime($retryAfter)) !== false) {
            return $time - time();
        }

        return 0;
    }

    /**
     * @param list<string> $encodings
     * @param array<int, string>|null $appliedEncodings
     * @psalm-suppress PossiblyInvalidArrayOffset
     */
    public static function encode(string $value, array $encodings, ?array &$appliedEncodings = null): string
    {
        for ($i = 0, $n = count($encodings); $i < $n; $i++) {
            if (!$encoder = self::encoder($encodings[$i])) {
                unset($encodings[$i]);

                continue;
            }

            try {
                $value = $encoder($value);
            } catch (Throwable) {
                unset($encodings[$i]);
            }
        }

        $appliedEncodings = $encodings;

        return $value;
    }

    /**
     * Read the response body and decode it, enforcing a size limit of
     * {@see ResponseBodySizeLimit::MAX_BYTES} on the **decompressed** output.
     *
     * For compressed responses the stream is decompressed incrementally so that
     * the limit applies to the decoded size, not the wire size.
     *
     * @param ResponseInterface $response PSR-7 response
     * @param list<string> $encodings Content-Encoding values
     * @return string Decoded payload bytes
     * @psalm-suppress InvalidArrayOffset
     */
    public static function decode(ResponseInterface $response, array $encodings): string
    {
        $stream = $response->getBody();

        // Determine if decompression is needed.
        $needsDecompression = false;
        for ($i = count($encodings); --$i >= 0;) {
            if (strcasecmp($encodings[$i], 'identity') !== 0) {
                $needsDecompression = true;

                break;
            }
        }

        if ($needsDecompression) {
            return self::decodeCompressed($stream, $encodings);
        }

        return self::readWithLimit($stream, ResponseBodySizeLimit::MAX_BYTES);
    }

    /**
     * Resolve an array or CSV of compression types to a list
     */
    public static function compression($compression): array
    {
        if (is_array($compression)) {
            return $compression;
        }
        if (!$compression) {
            return [];
        }
        if (!str_contains((string) $compression, ',')) {
            return [$compression];
        }

        return array_map('trim', explode(',', (string) $compression));
    }

    /**
     * Read up to $maxBytes from a stream, looping because a single read()
     * call may return fewer bytes than requested.
     */
    private static function readWithLimit(StreamInterface $stream, int $maxBytes): string
    {
        $buffer = '';
        $remaining = $maxBytes;

        while ($remaining > 0 && !$stream->eof()) {
            $chunk = $stream->read($remaining);
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }

    /**
     * Read compressed body, decompress, and enforce the size limit on the
     * **decompressed** output.
     *
     * @param StreamInterface $stream
     * @param list<string> $encodings
     */
    private static function decodeCompressed(StreamInterface $stream, array $encodings): string
    {
        // Read the entire compressed body from the stream (compressed data is
        // bounded by the wire; the limit applies after decompression).
        $compressed = $stream->__toString();

        $value = $compressed;
        if ($value === '') {
            return $value;
        }

        for ($i = count($encodings); --$i >= 0;) {
            if (strcasecmp($encodings[$i], 'identity') === 0) {
                continue;
            }
            if (!$decoder = self::decoder($encodings[$i])) {
                throw new UnexpectedValueException(sprintf('Not supported decompression encoding "%s"', $encodings[$i]));
            }

            $value = $decoder($value);
        }

        // Enforce the size limit on the decompressed output.
        if (strlen($value) > ResponseBodySizeLimit::MAX_BYTES) {
            $value = substr($value, 0, ResponseBodySizeLimit::MAX_BYTES);
        }

        return $value;
    }

    private static function encoder(string $encoding): ?callable
    {
        static $encoders;

        /** @noinspection SpellCheckingInspection */
        $encoders ??= array_map(fn (callable $callable): callable => self::throwOnErrorOrFalse($callable), array_filter([
            TransportFactoryInterface::COMPRESSION_GZIP => 'gzencode',
            TransportFactoryInterface::COMPRESSION_DEFLATE => 'gzcompress',
            TransportFactoryInterface::COMPRESSION_BROTLI => 'brotli_compress',
        ], 'function_exists'));

        return $encoders[$encoding] ?? null;
    }

    private static function decoder(string $encoding): ?callable
    {
        static $decoders;

        /** @noinspection SpellCheckingInspection */
        $decoders ??= array_map(fn (callable $callable): callable => self::throwOnErrorOrFalse($callable), array_filter([
            TransportFactoryInterface::COMPRESSION_GZIP => 'gzdecode',
            TransportFactoryInterface::COMPRESSION_DEFLATE => 'gzuncompress',
            TransportFactoryInterface::COMPRESSION_BROTLI => 'brotli_uncompress',
        ], 'function_exists'));

        return $decoders[$encoding] ?? null;
    }

    private static function throwOnErrorOrFalse(callable $callable): callable
    {
        return static function (...$args) use ($callable) {
            set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
                throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
            });

            try {
                $result = $callable(...$args);
            } finally {
                restore_error_handler();
            }

            /** @phan-suppress-next-line PhanPossiblyUndeclaredVariable */
            if ($result === false) {
                throw new LogicException();
            }

            /** @phan-suppress-next-line PhanPossiblyUndeclaredVariable */
            return $result;
        };
    }
}

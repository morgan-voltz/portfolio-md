<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure sanitization primitives shared by the meta registrars.
 *
 * Every method takes the raw value handed over by WordPress (anything: string,
 * int, bool, array, null) and returns a value that is safe to store, or the
 * neutral value of the field type ('' / false / fallback int) when the input
 * is invalid. Invalid input is never "repaired" into something plausible: an
 * impossible date is rejected, not rounded to the next valid day. Upstream
 * code (the Markdown pipeline) is expected to validate and report before
 * reaching this layer; this layer is the last line of defence.
 *
 * Only `text()` and `url()` call WordPress functions (`sanitize_text_field`,
 * `esc_url_raw`). Everything else is plain PHP and unit-testable without
 * WordPress.
 */
final class Sanitizer
{
    /** Protocols accepted for stored URLs: anything else (javascript:, ftp:, mailto:) is rejected. */
    public const WEB_PROTOCOLS = ['http', 'https'];

    private function __construct()
    {
    }

    /**
     * Single-line plain text: tags stripped, whitespace collapsed, optionally truncated.
     *
     * Delegates to WordPress `sanitize_text_field()`; `$maxLength` is applied
     * after cleaning so that stripped markup never counts against the limit.
     */
    public static function text(mixed $value, ?int $maxLength = null): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return '';
        }

        $clean = sanitize_text_field((string) $value);

        return $maxLength === null ? $clean : mb_substr($clean, 0, $maxLength);
    }

    /**
     * Multi-line string kept verbatim (Markdown source).
     *
     * No tag stripping and no whitespace normalisation: both would corrupt
     * Markdown (fenced code blocks, hard line breaks) and would break the
     * SHA-256 hash computed on the exact source text.
     */
    public static function rawString(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Boolean from the usual textual forms ("1", "true", "yes", "on" and their
     * negatives) or from the numbers 1 and 0. Anything unrecognised is false:
     * the number 2 and the string "2" are both rejected, so that the same
     * value gives the same answer whatever the type it arrives with.
     */
    public static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value == 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return false;
    }

    /**
     * Integer within [$min, $max]; out-of-range or non-numeric input yields $fallback.
     */
    public static function boundedInt(mixed $value, int $min, int $max, int $fallback = 0): int
    {
        if (is_bool($value) || !is_scalar($value)) {
            return $fallback;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);

        return $int === false ? $fallback : $int;
    }

    /**
     * Closed enumeration: the trimmed value must be one of $allowed, else ''.
     *
     * @param list<string> $allowed
     */
    public static function oneOf(mixed $value, array $allowed): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Absolute web URL restricted to the given protocols; '' when empty or rejected.
     *
     * esc_url_raw() prepends "http://" to a bare host and filters schemes
     * against $protocols, but it lets anything that starts with "/" through
     * untouched (root-relative paths, protocol-relative "//host"), so the
     * scheme of its result is checked again here.
     *
     * @param list<string> $protocols
     */
    public static function url(mixed $value, array $protocols = self::WEB_PROTOCOLS): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $clean = esc_url_raw($value, $protocols);
        $scheme = strtolower((string) parse_url($clean, PHP_URL_SCHEME));

        return in_array($scheme, $protocols, true) ? $clean : '';
    }

    /**
     * Calendar date in ISO 8601 extended format (YYYY-MM-DD).
     *
     * The round-trip through DateTimeImmutable rejects impossible dates such as
     * 2026-02-30, which a regex alone would accept.
     */
    public static function isoDate(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * ISO 8601 date-time with timezone (offset or "Z"), normalised to UTC.
     *
     * Accepts "2026-10-26T09:00:00+02:00", "2026-10-26T07:00:00Z" and an
     * optional fractional second part, which is dropped. The RFC 3339
     * "-00:00" offset (unknown local offset, UTC instant) is treated like
     * "Z". Returns the instant in the canonical form "2026-10-26T07:00:00Z",
     * or '' when the input is malformed or names an impossible date.
     */
    public static function isoDateTimeUtc(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

        if (preg_match($pattern, $value, $matches) !== 1) {
            return '';
        }

        $offset = in_array($matches[2], ['Z', '-00:00'], true) ? '+00:00' : $matches[2];
        $normalised = $matches[1] . $offset;
        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $normalised);

        // createFromFormat() silently rolls an impossible date (Feb 30) over to
        // the next month; comparing the round-trip output catches that.
        if ($dateTime === false || $dateTime->format('Y-m-d\TH:i:sP') !== $normalised) {
            return '';
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Lower-case hexadecimal SHA-256 digest (64 characters), else ''.
     */
    public static function sha256Hex(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }

    /**
     * Semantic version core (MAJOR.MINOR.PATCH), without pre-release or build
     * metadata, else ''.
     */
    public static function semver(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return preg_match('/^\d+\.\d+\.\d+$/', $value) === 1 ? $value : '';
    }
}

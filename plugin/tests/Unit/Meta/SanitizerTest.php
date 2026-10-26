<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Meta\Sanitizer;

/**
 * The sanitizers are the last line of defence before storage: every case here
 * documents what gets through and what is turned into the neutral value.
 */
#[CoversClass(Sanitizer::class)]
final class SanitizerTest extends TestCase
{
    // region text

    #[Test]
    #[DataProvider('textProvider')]
    public function text_strips_markup_collapses_whitespace_and_truncates(mixed $input, ?int $max, string $expected): void
    {
        self::assertSame($expected, Sanitizer::text($input, $max));
    }

    /** @return iterable<string, array{mixed, ?int, string}> */
    public static function textProvider(): iterable
    {
        yield 'plain text' => ['Hello world', null, 'Hello world'];
        yield 'inline tags stripped' => ['<em>Hello</em> <a href="x">world</a>', null, 'Hello world'];
        yield 'script block removed with its content' => ['<b>Hello</b> <script>alert(1)</script>', null, 'Hello'];
        yield 'line breaks collapsed' => ["line one\nline two", null, 'line one line two'];
        yield 'outer whitespace trimmed' => ['  padded  ', null, 'padded'];
        yield 'truncated after cleaning' => ['<i>abcdef</i>', 3, 'abc'];
        yield 'multibyte truncation keeps characters whole' => ['éàçè', 2, 'éà'];
        yield 'integer cast to string' => [42, null, '42'];
        yield 'bool rejected' => [true, null, ''];
        yield 'array rejected' => [['x'], null, ''];
        yield 'null rejected' => [null, null, ''];
    }

    // endregion

    // region rawString

    #[Test]
    public function raw_string_keeps_markdown_verbatim(): void
    {
        $markdown = "---\ntitle: X\n---\n\n# Title\r\n\n<b>kept</b>\n\n    indented code\n";

        self::assertSame($markdown, Sanitizer::rawString($markdown));
    }

    #[Test]
    #[DataProvider('rawStringRejectsProvider')]
    public function raw_string_rejects_non_text(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::rawString($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function rawStringRejectsProvider(): iterable
    {
        yield 'array' => [['a'], ''];
        yield 'null' => [null, ''];
        yield 'bool' => [false, ''];
        yield 'int kept as digits' => [7, '7'];
    }

    // endregion

    // region boolean

    #[Test]
    #[DataProvider('booleanProvider')]
    public function boolean_understands_textual_forms(mixed $input, bool $expected): void
    {
        self::assertSame($expected, Sanitizer::boolean($input));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function booleanProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '"1"' => ['1', true];
        yield '"0"' => ['0', false];
        yield '"yes"' => ['yes', true];
        yield '"no"' => ['no', false];
        yield '"on"' => ['on', true];
        yield '"off"' => ['off', false];
        yield '"true"' => ['true', true];
        yield '"false"' => ['false', false];
        yield '"TRUE" (case-insensitive)' => ['TRUE', true];
        yield 'unrecognised string' => ['maybe', false];
        yield 'empty string' => ['', false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'int 2 rejected' => [2, false];
        yield '"2" rejected like the int' => ['2', false];
        yield 'float 1.0' => [1.0, true];
        yield 'float 0.5 rejected' => [0.5, false];
        yield 'array' => [[], false];
        yield 'null' => [null, false];
    }

    // endregion

    // region boundedInt

    #[Test]
    #[DataProvider('boundedIntProvider')]
    public function bounded_int_accepts_the_range_inclusive_and_falls_back_otherwise(mixed $input, int $expected): void
    {
        self::assertSame($expected, Sanitizer::boundedInt($input, 0, 240, -1));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function boundedIntProvider(): iterable
    {
        yield 'numeric string' => ['12', 12];
        yield 'int' => [12, 12];
        yield 'lower bound' => [0, 0];
        yield 'upper bound' => [240, 240];
        yield 'above range' => [241, -1];
        yield 'below range' => [-5, -1];
        yield 'float string' => ['12.5', -1];
        yield 'trailing garbage' => ['12abc', -1];
        yield 'bool' => [true, -1];
        yield 'array' => [[12], -1];
        yield 'null' => [null, -1];
    }

    // endregion

    // region oneOf

    #[Test]
    #[DataProvider('oneOfProvider')]
    public function one_of_is_strict_and_trims(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::oneOf($input, ['admin', 'git', 'paste']));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function oneOfProvider(): iterable
    {
        yield 'allowed' => ['git', 'git'];
        yield 'allowed with padding' => [' git ', 'git'];
        yield 'wrong case' => ['Git', ''];
        yield 'not allowed' => ['ftp', ''];
        yield 'empty' => ['', ''];
        yield 'non-string' => [1, ''];
    }

    // endregion

    // region url

    #[Test]
    #[DataProvider('urlProvider')]
    public function url_keeps_web_urls_only(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::url($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function urlProvider(): iterable
    {
        yield 'https' => ['https://github.com/morgan-voltz/portfolio-md', 'https://github.com/morgan-voltz/portfolio-md'];
        yield 'http' => ['http://localhost:8000/', 'http://localhost:8000/'];
        yield 'padding trimmed' => ['  https://example.org  ', 'https://example.org'];
        yield 'bare host gets http' => ['github.com/morgan-voltz', 'http://github.com/morgan-voltz'];
        yield 'protocol-relative rejected' => ['//evil.example.com/', ''];
        yield 'root-relative path rejected' => ['/wp-admin/', ''];
        yield 'bare host with port rejected like WordPress' => ['example.com:8080/demo', ''];
        yield 'javascript rejected' => ['javascript:alert(1)', ''];
        yield 'ftp rejected' => ['ftp://example.org/file', ''];
        yield 'mailto rejected' => ['mailto:a@b.c', ''];
        yield 'empty' => ['', ''];
        yield 'non-string' => [['https://x'], ''];
    }

    // endregion

    // region isoDate

    #[Test]
    #[DataProvider('isoDateProvider')]
    public function iso_date_accepts_real_calendar_dates_only(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::isoDate($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function isoDateProvider(): iterable
    {
        yield 'valid' => ['2026-02-28', '2026-02-28'];
        yield 'leap day on leap year' => ['2024-02-29', '2024-02-29'];
        yield 'leap day on common year' => ['2026-02-29', ''];
        yield 'impossible day' => ['2026-02-30', ''];
        yield 'impossible month' => ['2026-13-01', ''];
        yield 'two-digit year' => ['26-02-28', ''];
        yield 'date-time instead of date' => ['2026-02-28T10:00:00Z', ''];
        yield 'padding trimmed' => [' 2026-02-28 ', '2026-02-28'];
        yield 'empty' => ['', ''];
        yield 'non-string' => [20260228, ''];
    }

    // endregion

    // region isoDateTimeUtc

    #[Test]
    #[DataProvider('isoDateTimeUtcProvider')]
    public function iso_date_time_utc_normalises_to_utc_or_rejects(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::isoDateTimeUtc($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function isoDateTimeUtcProvider(): iterable
    {
        yield 'already UTC' => ['2026-10-26T07:00:00Z', '2026-10-26T07:00:00Z'];
        yield 'negative zero offset treated as UTC' => ['2026-10-26T07:00:00-00:00', '2026-10-26T07:00:00Z'];
        yield 'positive offset converted' => ['2026-10-26T09:00:00+02:00', '2026-10-26T07:00:00Z'];
        yield 'negative offset converted' => ['2026-10-25T22:30:00-05:00', '2026-10-26T03:30:00Z'];
        yield 'fractional seconds dropped' => ['2026-10-26T07:00:00.123456Z', '2026-10-26T07:00:00Z'];
        yield 'day rolls over through offset' => ['2026-10-26T00:30:00+02:00', '2026-10-25T22:30:00Z'];
        yield 'missing timezone' => ['2026-10-26T07:00:00', ''];
        yield 'space instead of T' => ['2026-10-26 07:00:00Z', ''];
        yield 'impossible date' => ['2026-02-30T07:00:00Z', ''];
        yield 'impossible hour' => ['2026-10-26T25:00:00Z', ''];
        yield 'date only' => ['2026-10-26', ''];
        yield 'empty' => ['', ''];
        yield 'non-string' => [1761462000, ''];
    }

    // endregion

    // region sha256Hex

    #[Test]
    #[DataProvider('sha256HexProvider')]
    public function sha256_hex_wants_exactly_64_hex_characters(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::sha256Hex($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function sha256HexProvider(): iterable
    {
        $digest = hash('sha256', 'portfolio');

        yield 'lower-case digest' => [$digest, $digest];
        yield 'upper-case digest lowered' => [strtoupper($digest), $digest];
        yield 'padding trimmed' => [" {$digest} ", $digest];
        yield 'too short' => [substr($digest, 0, 63), ''];
        yield 'too long' => [$digest . 'a', ''];
        yield 'non-hex character' => ['g' . substr($digest, 1), ''];
        yield 'empty' => ['', ''];
        yield 'non-string' => [123, ''];
    }

    // endregion

    // region semver

    #[Test]
    #[DataProvider('semverProvider')]
    public function semver_accepts_the_version_core_only(mixed $input, string $expected): void
    {
        self::assertSame($expected, Sanitizer::semver($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function semverProvider(): iterable
    {
        yield 'valid' => ['1.0.0', '1.0.0'];
        yield 'multi-digit' => ['12.34.56', '12.34.56'];
        yield 'padding trimmed' => [' 1.0.0 ', '1.0.0'];
        yield 'missing patch' => ['1.0', ''];
        yield 'pre-release suffix' => ['1.0.0-beta', ''];
        yield 'leading v' => ['v1.0.0', ''];
        yield 'empty' => ['', ''];
        yield 'non-string' => [1.0, ''];
    }

    // endregion
}

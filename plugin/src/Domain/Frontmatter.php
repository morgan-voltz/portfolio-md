<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Typed, validated metadata of a Markdown document (content/README.md).
 *
 * Built from the associative array produced by the YAML parser. Validation
 * is strict on purpose: an unknown key, a wrong type or an out-of-range value
 * raises InvalidFrontmatterException with the key name, so that mistakes are
 * caught at import time rather than silently sanitized away at storage time
 * (the meta sanitizers of ADR-013 remain the last line of defence).
 *
 * Keys are grouped by content type. A page only carries the common keys; an
 * article may add reading_time; a project adds its eight specific keys and
 * must declare a status.
 */
final readonly class Frontmatter
{
    private const COMMON_KEYS = ['title', 'slug', 'type', 'date', 'subtitle', 'seo_description', 'featured', 'tags', 'skills'];
    private const ARTICLE_KEYS = ['reading_time'];
    private const PROJECT_KEYS = ['status', 'role', 'period_start', 'period_end', 'repo_url', 'demo_url', 'client', 'is_fil_rouge'];
    private const PAGE_KEYS = ['title', 'slug', 'type', 'date', 'seo_description'];

    /** Google truncates meta descriptions around this length; mirrored by CommonMetaRegistrar. */
    public const SEO_DESCRIPTION_MAX_LENGTH = 160;

    /** Upper bound of a plausible reading time, in minutes; mirrored by CommonMetaRegistrar. */
    public const READING_TIME_MAX_MINUTES = 240;

    /**
     * @param list<string> $tags   tech_tag slugs
     * @param list<string> $skills competency codes such as "4.0.2/E07"
     */
    public function __construct(
        public string $title,
        public string $slug,
        public ContentType $type,
        public ?DateTimeImmutable $date,
        public string $subtitle,
        public string $seoDescription,
        public bool $featured,
        public ?int $readingTime,
        public array $tags,
        public array $skills,
        public ?ProjectStatus $status,
        public string $role,
        public ?DateTimeImmutable $periodStart,
        public ?DateTimeImmutable $periodEnd,
        public string $repoUrl,
        public string $demoUrl,
        public string $client,
        public bool $isFilRouge,
    ) {
    }

    /**
     * @param array<string, mixed> $data decoded YAML mapping
     *
     * @throws InvalidFrontmatterException
     */
    public static function fromArray(array $data): self
    {
        foreach (['title', 'slug', 'type'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw InvalidFrontmatterException::missingKey($key);
            }
        }

        $type = ContentType::tryFrom(is_string($data['type']) ? $data['type'] : '');
        if ($type === null) {
            throw InvalidFrontmatterException::invalidValue('type', 'expected one of ' . implode(', ', ContentType::values()));
        }

        $allowed = match ($type) {
            ContentType::Page => self::PAGE_KEYS,
            ContentType::Article => [...self::COMMON_KEYS, ...self::ARTICLE_KEYS],
            ContentType::Project => [...self::COMMON_KEYS, ...self::PROJECT_KEYS],
        };
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw InvalidFrontmatterException::unknownKeys(array_map('strval', $unknown));
        }

        if ($type === ContentType::Project && !array_key_exists('status', $data)) {
            throw InvalidFrontmatterException::missingKey('status');
        }

        $status = null;
        if ($type === ContentType::Project) {
            $status = ProjectStatus::tryFrom(is_string($data['status']) ? $data['status'] : '');
            if ($status === null) {
                throw InvalidFrontmatterException::invalidValue('status', 'expected one of ' . implode(', ', ProjectStatus::values()));
            }
        }

        $seoDescription = self::text($data, 'seo_description');
        if (mb_strlen($seoDescription) > self::SEO_DESCRIPTION_MAX_LENGTH) {
            throw InvalidFrontmatterException::invalidValue('seo_description', 'longer than ' . self::SEO_DESCRIPTION_MAX_LENGTH . ' characters');
        }

        return new self(
            title: self::requiredText($data, 'title'),
            slug: self::slug($data, 'slug'),
            type: $type,
            date: self::date($data, 'date'),
            subtitle: self::text($data, 'subtitle'),
            seoDescription: $seoDescription,
            featured: self::bool($data, 'featured'),
            readingTime: self::readingTime($data),
            tags: self::slugList($data, 'tags'),
            skills: self::skillList($data),
            status: $status,
            role: self::text($data, 'role'),
            periodStart: self::date($data, 'period_start'),
            periodEnd: self::date($data, 'period_end'),
            repoUrl: self::url($data, 'repo_url'),
            demoUrl: self::url($data, 'demo_url'),
            client: self::text($data, 'client'),
            isFilRouge: self::bool($data, 'is_fil_rouge'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requiredText(array $data, string $key): string
    {
        $value = self::text($data, $key);
        if ($value === '') {
            throw InvalidFrontmatterException::invalidValue($key, 'must not be empty');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw InvalidFrontmatterException::invalidValue($key, 'expected a string');
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function slug(array $data, string $key): string
    {
        $value = self::requiredText($data, $key);
        if (preg_match(Patterns::SLUG, $value) !== 1) {
            throw InvalidFrontmatterException::invalidValue($key, 'expected lowercase letters, digits and single hyphens');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? false;
        if (!is_bool($value)) {
            throw InvalidFrontmatterException::invalidValue($key, 'expected a YAML boolean (true or false)');
        }

        return $value;
    }

    /**
     * Accepts a YAML date (a DateTimeInterface when parsed with PARSE_DATETIME),
     * a "YYYY-MM-DD" string, an empty string or null, and returns a calendar
     * date at midnight UTC whatever the form.
     *
     * A DateTimeInterface is reduced to the calendar date it displays, so a
     * time or an offset never shifts the day. Impossible dates written bare
     * (2026-02-30) are already rolled over by the YAML parser before reaching
     * this method: FrontmatterParser rejects them upstream.
     *
     * @param array<string, mixed> $data
     */
    private static function date(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }

        throw InvalidFrontmatterException::invalidValue($key, 'expected a calendar date YYYY-MM-DD');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readingTime(array $data): ?int
    {
        if (!array_key_exists('reading_time', $data) || $data['reading_time'] === null || $data['reading_time'] === '') {
            return null;
        }
        $value = $data['reading_time'];
        if (!is_int($value) || $value < 0 || $value > self::READING_TIME_MAX_MINUTES) {
            throw InvalidFrontmatterException::invalidValue('reading_time', 'expected an integer number of minutes between 0 and ' . self::READING_TIME_MAX_MINUTES);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function url(array $data, string $key): string
    {
        $value = self::text($data, $key);
        if ($value !== '' && preg_match('#^https?://\S+$#', $value) !== 1) {
            throw InvalidFrontmatterException::invalidValue($key, 'expected an http(s) URL or an empty string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function slugList(array $data, string $key): array
    {
        $items = self::stringList($data, $key);
        foreach ($items as $item) {
            if (preg_match(Patterns::SLUG, $item) !== 1) {
                throw InvalidFrontmatterException::invalidValue($key, "\"{$item}\" is not a slug");
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function skillList(array $data): array
    {
        $items = self::stringList($data, 'skills');
        foreach ($items as $item) {
            if (preg_match(Patterns::SKILL_CODE, $item) !== 1) {
                throw InvalidFrontmatterException::invalidValue('skills', "\"{$item}\" is not a competency code such as 4.0.2/E07");
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || array_is_list($value) === false) {
            throw InvalidFrontmatterException::invalidValue($key, 'expected a YAML list');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw InvalidFrontmatterException::invalidValue($key, 'expected non-empty strings');
            }
            $items[] = trim($item);
        }
        if (count($items) !== count(array_unique($items))) {
            throw InvalidFrontmatterException::invalidValue($key, 'contains duplicates');
        }

        return $items;
    }
}

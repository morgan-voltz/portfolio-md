<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

/**
 * Records every call the stubbed WordPress functions receive.
 *
 * The stubs in wp-stubs.php write here; tests read here. Call reset() in
 * setUp() so that each test starts from a blank WordPress.
 */
final class WordPressSpy
{
    /** @var array<string, array<string, array<string, mixed>>> post type => meta key => args */
    public static array $postMetas = [];

    /** @var array<string, array<string, array<string, mixed>>> taxonomy => meta key => args */
    public static array $termMetas = [];

    /** @var array<string, array{objectTypes: list<string>, args: array<string, mixed>}> */
    public static array $taxonomies = [];

    /** @var array<string, array<string, mixed>> post type => args */
    public static array $postTypes = [];

    /** @var array<string, list<string>> taxonomy => slugs of the terms that "exist" */
    public static array $existingTerms = [];

    /** @var list<array{term: string, taxonomy: string, args: array<string, mixed>}> */
    public static array $insertedTerms = [];

    /** @var list<array<int, mixed>> arguments of each user_can() call */
    public static array $userCanCalls = [];

    /** Value returned by the user_can() stub. */
    public static bool $userCanResult = false;

    private function __construct()
    {
    }

    public static function reset(): void
    {
        self::$postMetas = [];
        self::$termMetas = [];
        self::$taxonomies = [];
        self::$postTypes = [];
        self::$existingTerms = [];
        self::$insertedTerms = [];
        self::$userCanCalls = [];
        self::$userCanResult = false;
    }

    /**
     * Arguments a post meta was registered with, or null when it was not.
     *
     * @return array<string, mixed>|null
     */
    public static function postMeta(string $postType, string $metaKey): ?array
    {
        return self::$postMetas[$postType][$metaKey] ?? null;
    }

    /**
     * Meta keys registered on a post type, in registration order.
     *
     * @return list<string>
     */
    public static function postMetaKeys(string $postType): array
    {
        return array_keys(self::$postMetas[$postType] ?? []);
    }
}

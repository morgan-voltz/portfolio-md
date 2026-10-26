<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

/**
 * Records every call the stubbed WordPress functions receive, and holds the
 * answers some of them give back.
 *
 * The stubs in wp-stubs.php write here; tests read (and prime) here. Call
 * reset() in setUp() so that each test starts from a blank WordPress.
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

    /** Value returned by the user_can() and current_user_can() stubs. */
    public static bool $userCanResult = false;

    /** @var list<array<int, mixed>> arguments of each current_user_can() call */
    public static array $currentUserCanCalls = [];

    /** @var array<string, bool> "capability" or "capability:first argument" => answer of current_user_can() */
    public static array $capabilityResults = [];

    /** The site's timezone for wp_date(); UTC when null. */
    public static ?\DateTimeZone $timezone = null;

    /** The instant wp_date() takes for "now" when a test fixed it (Unix timestamp). */
    public static ?int $now = null;

    /** @var list<array{postId: int, key: string, value: mixed}> values handed to update_post_meta() */
    public static array $updatedPostMetas = [];

    /** @var array<int, array<string, mixed>> post id => meta key => value as WordPress would store it (unslashed) */
    public static array $postMetaValues = [];

    /** @var list<array{type: string, hook: string, callback: callable, priority: int, args: int}> */
    public static array $hooks = [];

    /** @var list<array{postType: string, feature: string}> */
    public static array $removedSupports = [];

    /** @var list<array{id: string, screen: mixed, context: string}> */
    public static array $removedMetaBoxes = [];

    /** @var list<array{id: string, title: string, screen: mixed, context: string, priority: string}> */
    public static array $metaBoxes = [];

    /** What get_current_screen() returns; an object with a `post_type` property, or null. */
    public static ?object $currentScreen = null;

    /** Whether wp_verify_nonce() and check_admin_referer() accept the nonce they are given. */
    public static bool $nonceValid = false;

    /** @var list<array{nonce: string, action: string|int}> */
    public static array $verifiedNonces = [];

    /** @var list<string> hidden fields emitted by wp_nonce_field() */
    public static array $nonceFields = [];

    /** @var array<string, mixed> transient name => value */
    public static array $transients = [];

    /** @var list<array<string, mixed>> arrays handed to wp_update_post() */
    public static array $updatedPosts = [];

    public static int $currentUserId = 1;

    /** @var list<array<string, mixed>> */
    public static array $menuPages = [];

    /** @var list<array<string, mixed>> */
    public static array $submenuPages = [];

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
        self::$currentUserCanCalls = [];
        self::$capabilityResults = [];
        self::$timezone = null;
        self::$now = null;
        self::$updatedPostMetas = [];
        self::$postMetaValues = [];
        self::$hooks = [];
        self::$removedSupports = [];
        self::$removedMetaBoxes = [];
        self::$metaBoxes = [];
        self::$currentScreen = null;
        self::$nonceValid = false;
        self::$verifiedNonces = [];
        self::$nonceFields = [];
        self::$transients = [];
        self::$updatedPosts = [];
        self::$currentUserId = 1;
        self::$menuPages = [];
        self::$submenuPages = [];
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

    /**
     * The hooks of one kind ('action' or 'filter') registered on one name,
     * in registration order.
     *
     * @return list<array{type: string, hook: string, callback: callable, priority: int, args: int}>
     */
    public static function hooksOn(string $type, string $hook): array
    {
        return array_values(array_filter(
            self::$hooks,
            static fn (array $entry): bool => $entry['type'] === $type && $entry['hook'] === $hook,
        ));
    }
}

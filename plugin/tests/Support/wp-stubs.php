<?php

/**
 * Minimal stand-ins for the WordPress functions the plugin calls at
 * registration time and inside its sanitizers.
 *
 * Registration functions only record their arguments in WordPressSpy.
 * Sanitization helpers approximate WordPress behaviour closely enough for
 * the cases covered by the unit suite; the exact WordPress behaviour is
 * exercised by the WP-CLI smoke test (tests/Integration/wp-cli/).
 *
 * Every definition is guarded so that this file is harmless if it is ever
 * loaded next to a real WordPress.
 */

declare(strict_types=1);

use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

if (!function_exists('register_post_meta')) {
    function register_post_meta(string $post_type, string $meta_key, array $args): bool
    {
        WordPressSpy::$postMetas[$post_type][$meta_key] = $args;

        return true;
    }
}

if (!function_exists('register_term_meta')) {
    function register_term_meta(string $taxonomy, string $meta_key, array $args): bool
    {
        WordPressSpy::$termMetas[$taxonomy][$meta_key] = $args;

        return true;
    }
}

if (!function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, array|string $object_type, array $args = []): void
    {
        WordPressSpy::$taxonomies[$taxonomy] = [
            'objectTypes' => (array) $object_type,
            'args' => $args,
        ];
    }
}

if (!function_exists('register_post_type')) {
    function register_post_type(string $post_type, array $args = []): void
    {
        WordPressSpy::$postTypes[$post_type] = $args;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('__return_false')) {
    function __return_false(): bool
    {
        return false;
    }
}

if (!function_exists('__return_true')) {
    function __return_true(): bool
    {
        return true;
    }
}

if (!function_exists('term_exists')) {
    /**
     * @return array{term_id: int, term_taxonomy_id: int}|null
     */
    function term_exists(string|int $term, string $taxonomy = ''): ?array
    {
        $slugs = WordPressSpy::$existingTerms[$taxonomy] ?? [];

        return in_array((string) $term, $slugs, true) ? ['term_id' => 1, 'term_taxonomy_id' => 1] : null;
    }
}

if (!function_exists('wp_insert_term')) {
    /**
     * @return array{term_id: int, term_taxonomy_id: int}
     */
    function wp_insert_term(string $term, string $taxonomy, array $args = []): array
    {
        WordPressSpy::$insertedTerms[] = ['term' => $term, 'taxonomy' => $taxonomy, 'args' => $args];
        WordPressSpy::$existingTerms[$taxonomy][] = (string) ($args['slug'] ?? $term);
        $id = count(WordPressSpy::$insertedTerms);

        return ['term_id' => $id, 'term_taxonomy_id' => $id];
    }
}

if (!function_exists('user_can')) {
    function user_can(mixed $user, string $capability, mixed ...$args): bool
    {
        WordPressSpy::$userCanCalls[] = [$user, $capability, ...$args];

        return WordPressSpy::$userCanResult;
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Approximation of WordPress: strip tags, collapse whitespace (including
     * line breaks) to single spaces, trim.
     */
    function sanitize_text_field(string $str): string
    {
        $filtered = strip_tags($str);
        $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered) ?? '';

        return trim($filtered);
    }
}

if (!function_exists('esc_url_raw')) {
    /**
     * Approximation of WordPress: prepend http:// to scheme-less URLs, then
     * reject any scheme outside the allowed list.
     *
     * @param list<string>|null $protocols
     */
    function esc_url_raw(string $url, ?array $protocols = null): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme)) {
            if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
                return $url;
            }

            $url = 'http://' . $url;
            $scheme = 'http';
        }

        $allowed = $protocols ?? ['http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'feed', 'tel'];

        return in_array(strtolower($scheme), $allowed, true) ? $url : '';
    }
}

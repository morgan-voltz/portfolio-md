<?php

/**
 * Minimal stand-ins for the WordPress functions the plugin calls at
 * registration time, when writing metas, and inside its sanitizers.
 *
 * Registration and write functions only record their arguments in
 * WordPressSpy. Sanitization helpers mirror the WordPress behaviour that
 * matters for the covered cases (checked against WordPress 7.1 core); the
 * exact behaviour is exercised by the WP-CLI smoke test
 * (tests/Integration/wp-cli/).
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

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): int|bool
    {
        WordPressSpy::$updatedPostMetas[] = ['postId' => $post_id, 'key' => $meta_key, 'value' => $meta_value];

        return true;
    }
}

if (!function_exists('wp_slash')) {
    /**
     * Same contract as WordPress: strings get addslashes(), arrays are
     * walked, every other type is returned untouched.
     */
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }

        return is_string($value) ? addslashes($value) : $value;
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

if (!function_exists('get_term_by')) {
    /**
     * Only the ('slug', $slug, $taxonomy) form used by the plugin is
     * modelled: a term "exists" when its slug is listed in
     * WordPressSpy::$existingTerms for that taxonomy.
     */
    function get_term_by(string $field, string|int $value, string $taxonomy = ''): object|false
    {
        if ($field !== 'slug') {
            return false;
        }

        $slugs = WordPressSpy::$existingTerms[$taxonomy] ?? [];

        return in_array((string) $value, $slugs, true)
            ? (object) ['slug' => (string) $value, 'taxonomy' => $taxonomy]
            : false;
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
     * Mirrors _sanitize_text_fields(): when the string contains markup,
     * <script> and <style> blocks disappear with their content before the
     * remaining tags are stripped (wp_strip_all_tags); then line breaks,
     * tabs and runs of spaces collapse to one space and the result is
     * trimmed.
     */
    function sanitize_text_field(string $str): string
    {
        $filtered = $str;

        if (str_contains($filtered, '<')) {
            $filtered = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $filtered) ?? '';
            $filtered = strip_tags($filtered);
        }

        $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered) ?? '';

        return trim($filtered);
    }
}

if (!function_exists('esc_url_raw')) {
    /**
     * Mirrors the parts of esc_url() that matter here: "http://" is
     * prepended only when the string holds no colon at all and is not a
     * path, fragment or query; anything starting with "/" is returned as is
     * without consulting the protocol list; otherwise whatever precedes the
     * first colon is the protocol candidate (wp_kses_bad_protocol) and the
     * URL is rejected when it is not allowed.
     *
     * @param list<string>|null $protocols
     */
    function esc_url_raw(string $url, ?array $protocols = null): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (!str_contains($url, ':') && !str_starts_with($url, '/') && !str_starts_with($url, '#') && !str_starts_with($url, '?')) {
            $url = 'http://' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $allowed = $protocols ?? ['http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'feed', 'tel'];
        $colon = strpos($url, ':');
        $protocol = strtolower(substr($url, 0, $colon === false ? 0 : $colon));

        return in_array($protocol, $allowed, true) ? $url : '';
    }
}

<?php

/**
 * Minimal stand-ins for the WordPress functions the plugin calls at
 * registration time, when writing metas, inside its sanitizers and on its
 * admin screens.
 *
 * Registration, hook and write functions only record their arguments in
 * WordPressSpy (and answer with what it was primed with). Sanitization and
 * escaping helpers mirror the WordPress behaviour that matters for the
 * covered cases (checked against WordPress 7.1 core); the exact behaviour
 * is exercised by the WP-CLI smoke tests (tests/Integration/wp-cli/).
 *
 * Every definition is guarded so that this file is harmless if it is ever
 * loaded next to a real WordPress.
 */

declare(strict_types=1);

use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

if (!class_exists('WP_Post')) {
    /**
     * The handful of fields the plugin reads from a post, settable from an
     * array in the tests.
     */
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_name = '';

        /**
         * @param array<string, mixed> $fields
         */
        public function __construct(array $fields = [])
        {
            foreach ($fields as $name => $value) {
                $this->{$name} = $value;
            }
        }
    }
}

// --- Registration ----------------------------------------------------------

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

if (!function_exists('remove_post_type_support')) {
    function remove_post_type_support(string $post_type, string $feature): void
    {
        WordPressSpy::$removedSupports[] = ['postType' => $post_type, 'feature' => $feature];
    }
}

// --- Hooks -----------------------------------------------------------------

if (!function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        WordPressSpy::$hooks[] = ['type' => 'action', 'hook' => $hook_name, 'callback' => $callback, 'priority' => $priority, 'args' => $accepted_args];

        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        WordPressSpy::$hooks[] = ['type' => 'filter', 'hook' => $hook_name, 'callback' => $callback, 'priority' => $priority, 'args' => $accepted_args];

        return true;
    }
}

// --- Admin screens ---------------------------------------------------------

if (!function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, callable $callback, mixed $screen = null, string $context = 'advanced', string $priority = 'default', ?array $callback_args = null): void
    {
        WordPressSpy::$metaBoxes[] = ['id' => $id, 'title' => $title, 'screen' => $screen, 'context' => $context, 'priority' => $priority];
    }
}

if (!function_exists('remove_meta_box')) {
    function remove_meta_box(string $id, mixed $screen, string $context): void
    {
        WordPressSpy::$removedMetaBoxes[] = ['id' => $id, 'screen' => $screen, 'context' => $context];
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object
    {
        return WordPressSpy::$currentScreen;
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable|string $callback = '', string $icon_url = '', int|float|null $position = null): string
    {
        WordPressSpy::$menuPages[] = ['pageTitle' => $page_title, 'menuTitle' => $menu_title, 'capability' => $capability, 'slug' => $menu_slug, 'callback' => $callback, 'icon' => $icon_url, 'position' => $position];

        return 'toplevel_page_' . $menu_slug;
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable|string $callback = '', int|float|null $position = null): string
    {
        WordPressSpy::$submenuPages[] = ['parent' => $parent_slug, 'pageTitle' => $page_title, 'menuTitle' => $menu_title, 'capability' => $capability, 'slug' => $menu_slug, 'callback' => $callback];

        return $parent_slug . '_page_' . $menu_slug;
    }
}

if (!function_exists('wp_die')) {
    /**
     * WordPress stops the request; the tests see an exception instead.
     */
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
    {
        throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : ''));
    }
}

// --- Nonces and capabilities -----------------------------------------------

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
        WordPressSpy::$nonceFields[] = $field;
        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        WordPressSpy::$verifiedNonces[] = ['nonce' => $nonce, 'action' => $action];

        return WordPressSpy::$nonceValid ? 1 : false;
    }
}

if (!function_exists('check_admin_referer')) {
    /**
     * WordPress dies on a bad nonce; the tests see an exception instead.
     */
    function check_admin_referer(string|int $action = -1, string $query_arg = '_wpnonce'): int
    {
        WordPressSpy::$verifiedNonces[] = ['nonce' => (string) ($_REQUEST[$query_arg] ?? ''), 'action' => $action];
        if (!WordPressSpy::$nonceValid) {
            throw new RuntimeException("wp_die: the nonce for {$action} was refused");
        }

        return 1;
    }
}

if (!function_exists('user_can')) {
    function user_can(mixed $user, string $capability, mixed ...$args): bool
    {
        WordPressSpy::$userCanCalls[] = [$user, $capability, ...$args];

        return WordPressSpy::$userCanResult;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        WordPressSpy::$currentUserCanCalls[] = [$capability, ...$args];

        return WordPressSpy::$userCanResult;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return WordPressSpy::$currentUserId;
    }
}

// --- Posts, metas, transients ----------------------------------------------

if (!function_exists('update_post_meta')) {
    /**
     * Records the value as given (slashed by the caller) and stores it as
     * WordPress would, unslashed.
     */
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): int|bool
    {
        WordPressSpy::$updatedPostMetas[] = ['postId' => $post_id, 'key' => $meta_key, 'value' => $meta_value];
        WordPressSpy::$postMetaValues[$post_id][$meta_key] = wp_unslash($meta_value);

        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        $value = WordPressSpy::$postMetaValues[$post_id][$key] ?? null;
        if ($single) {
            return $value ?? '';
        }

        return $value === null ? [] : [$value];
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array|object $postarr = [], bool $wp_error = false, bool $fire_after_hooks = true): int
    {
        $postarr = (array) $postarr;
        WordPressSpy::$updatedPosts[] = $postarr;

        return (int) ($postarr['ID'] ?? 0);
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        WordPressSpy::$transients[$transient] = $value;

        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return WordPressSpy::$transients[$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        $existed = array_key_exists($transient, WordPressSpy::$transients);
        unset(WordPressSpy::$transients[$transient]);

        return $existed;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $id = 0, string $context = 'display'): ?string
    {
        return "http://example.test/wp-admin/post.php?post={$id}&action=edit";
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(mixed $post = 0, bool $leavename = false): string|false
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;

        return "http://example.test/?p={$id}";
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string|false
    {
        return date($format, $timestamp ?? time());
    }
}

// --- Slashing, escaping, i18n ----------------------------------------------

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

if (!function_exists('wp_unslash')) {
    /**
     * Same contract as WordPress (stripslashes_deep): strings get
     * stripslashes(), arrays are walked, every other type is returned untouched.
     */
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('esc_html')) {
    /** Like WordPress: quotes are encoded, existing entities are not encoded twice. */
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_textarea')) {
    /** Like WordPress: a plain htmlspecialchars(), entities included. */
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url, ?array $protocols = null, string $_context = 'display'): string
    {
        return str_replace('&', '&#038;', esc_url_raw($url, $protocols));
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

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $result = (string) $checked === (string) $current ? " checked='checked'" : '';
        if ($display) {
            echo $result;
        }

        return $result;
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

// --- Terms -----------------------------------------------------------------

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

// --- Sanitization ----------------------------------------------------------

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

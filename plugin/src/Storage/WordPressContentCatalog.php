<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Meta\Sanitizer;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\CatalogPage;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\ContentCatalog;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\GroupMember;
use Voltz\PortfolioMd\Service\GroupResolver;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Service\SitemapEntry;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * ContentCatalog on top of WP_Query, the taxonomies and the post metas: the
 * read side of 01-plugin-php.md §7, the mirror of WordPressContentRepository
 * (§4.4 and §4.5), which writes what this class reads.
 *
 * Four rules keep it safe to expose and cheap to call:
 *   - only published posts without password are read (`post_status`
 *     publish, `has_password` false): drafts, pending, private, scheduled
 *     and trashed posts never leave the store, whoever asks;
 *   - only posts that hold a document are read (`_portfolio_md_hash` set,
 *     ADR-005): a post made by hand in the admin has no Markdown to serve,
 *     and the native page type is full of such posts;
 *   - only posts that store a language and a translation key are read
 *     (ADR-021): a post imported before the plugin stored them is invisible
 *     until it is imported again, which is why a deployment of the plugin
 *     is followed by a full synchronization;
 *   - a page of posts is one query that also primes the term and meta
 *     caches of its posts (WP_Query does so by default); the translation
 *     groups of the page are read in one more, the terms of the members
 *     served in place of a French document in one more, and the metas of
 *     their skill terms (code, wordings, official flag) in a last one: the
 *     per-post reads below hit the caches, not the base, whatever the page
 *     size.
 *
 * A list is anchored on the French documents and every read ends in
 * serve(), which asks Service\GroupResolver which member of each group the
 * reader gets (ADR-021).
 *
 * This is the only class of the read side that talks to WordPress; it is
 * exercised by the WP-CLI smoke test rest-smoke.php rather than by the
 * unit suite, like the other two stores.
 */
final class WordPressContentCatalog implements ContentCatalog
{
    public function list(ContentType $type, CatalogQuery $query): CatalogPage
    {
        // Anchored on the French documents (ADR-021): one entry per translation group, paginated,
        // ordered and filtered on its French member, then served in the language of the query.
        $args = self::published([$type], Language::default()) + [
            'posts_per_page' => $query->perPage,
            'paged' => $query->page,
            // Newest first; at equal dates (several sheets imported with the same day), the
            // newest post first: a tie broken by a unique column keeps the pages disjoint.
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ];

        $taxonomies = [];
        if ($query->tag !== null) {
            $taxonomies[] = ['taxonomy' => TaxonomyRegistrar::TECH_TAG, 'field' => 'slug', 'terms' => $query->tag];
        }
        if ($query->status !== null) {
            $taxonomies[] = ['taxonomy' => TaxonomyRegistrar::PROJECT_STATUS, 'field' => 'slug', 'terms' => $query->status->value];
        }
        if ($query->skill !== null) {
            // The taxonomy is hierarchical and WP_Query includes the children of a term by
            // default: the slug of a referential lists the projects of all its competencies.
            $taxonomies[] = ['taxonomy' => TaxonomyRegistrar::SKILL, 'field' => 'slug', 'terms' => $query->skill];
        }
        if ($taxonomies !== []) {
            $args['tax_query'] = ['relation' => 'AND', ...$taxonomies];
        }

        $found = new WP_Query($args);
        $anchors = self::posts($found);

        $total = (int) $found->found_posts;
        if ($anchors === [] && $query->page > 1) {
            // WP_Query does not count the rows when a page comes back empty: the count is run
            // again without the page, the way the native collections do, so that a page beyond
            // the last can be told from an empty collection.
            $total = self::count($args);
        }

        return new CatalogPage($this->serve($type, $anchors, $query->language), $total, $query->page, $query->perPage);
    }

    public function countOwn(ContentType $type, Language $language): int
    {
        return self::count(self::published([$type], $language) + ['posts_per_page' => 1]);
    }

    /**
     * How many posts the arguments match, whatever page they asked for.
     *
     * @param array<string, mixed> $args
     */
    private static function count(array $args): int
    {
        unset($args['paged']);
        $count = new WP_Query($args + ['fields' => 'ids', 'update_post_meta_cache' => false, 'update_post_term_cache' => false]);

        return (int) $count->found_posts;
    }

    public function find(ContentType $type, string $slug, Language $language): ?PublishedDocument
    {
        // Any language: the slug names a document, whose group then decides what is served.
        $found = new WP_Query(self::published([$type]) + ['name' => $slug, 'posts_per_page' => 1, 'no_found_rows' => true]);
        $posts = self::posts($found);
        if ($posts === []) {
            return null;
        }

        return $this->serve($type, [$posts[0]], $language)[0];
    }

    /**
     * The documents a reader of $requested gets for these posts (ADR-021):
     * each post names a translation group, by its kind and its key, and the
     * group decides which member is served (Service\GroupResolver). The
     * groups of a whole page are read in one query; the terms of the members
     * actually served, and the codes of their skills, are primed before the
     * documents are built.
     *
     * @param list<WP_Post> $own the French anchors of a page, or the post under the slug of a detail route
     *
     * @return list<PublishedDocument> one per post of $own, in the same order
     */
    private function serve(ContentType $type, array $own, Language $requested): array
    {
        if ($own === []) {
            return [];
        }

        $keys = array_values(array_unique(array_map(static fn (WP_Post $post): string => self::translationKeyOf((int) $post->ID), $own)));
        $groups = $this->groups($type, $keys);

        $resolved = [];
        foreach ($own as $post) {
            $members = $groups[self::translationKeyOf((int) $post->ID)] ?? [];
            $byId = [];
            foreach ($members as $member) {
                $byId[(int) $member->ID] = $member;
            }

            $choice = GroupResolver::resolve($requested, self::member($post), array_map(self::member(...), $members));
            $resolved[] = ['post' => $byId[$choice['served']->id] ?? $post, 'translations' => $choice['translations'], 'isFallback' => $choice['isFallback']];
        }

        $served = array_column($resolved, 'post');
        self::primeTerms($served);
        self::primeSkillMetas($served);

        return array_map(fn (array $row): PublishedDocument => $this->document($row['post'], $row['translations'], $row['isFallback'], $requested), $resolved);
    }

    /**
     * Every published member of the translation groups of these keys, by
     * key, oldest post first: one query for a whole page, whatever its
     * size. The posts come whole (content included) because WP_Query reads
     * them so, with their metas; their terms are left for primeTerms(),
     * which only reads those of the members served. Accepted at the scale
     * of a portfolio; the thing to revisit if the lists ever grow long.
     *
     * @param list<string> $keys
     *
     * @return array<string, list<WP_Post>> translation key => members
     */
    private function groups(ContentType $type, array $keys): array
    {
        $found = new WP_Query(self::published([$type], null, $keys) + [
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'update_post_term_cache' => false,
        ]);

        $groups = [];
        foreach (self::posts($found) as $post) {
            $groups[self::translationKeyOf((int) $post->ID)][] = $post;
        }

        return $groups;
    }

    private static function member(WP_Post $post): GroupMember
    {
        $id = (int) $post->ID;

        return new GroupMember($id, self::languageOf($id), $post->post_name);
    }

    public function sitemap(): array
    {
        $found = new WP_Query(self::published(ContentType::cases()) + [
            'posts_per_page' => -1,
            'no_found_rows' => true,
            // The metas of every document in one query: each line reads its language and its key. This
            // loads every meta of every document, the Markdown source included, so the route costs about
            // twice what it would without; acceptable at the scale of a portfolio, and the only consumer
            // is the C# gateway polling it. A targeted read of the two metas is the lever if it grows.
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'orderby' => ['type' => 'ASC', 'name' => 'ASC'],
        ]);

        $entries = [];
        foreach (self::posts($found) as $post) {
            $type = PostTypeRegistrar::contentTypeOf($post->post_type);
            if ($type === null) {
                continue;
            }
            $id = (int) $post->ID;
            $entries[] = new SitemapEntry($type, $post->post_name, self::instant($post, 'modified'), self::languageOf($id), self::translationKeyOf($id));
        }

        return $entries;
    }

    /**
     * The query arguments shared by every read: the posts of these kinds
     * that the site shows to anyone, that hold a document and that store
     * their language and their translation key (ADR-021). The language or
     * the keys may be narrowed; the three meta clauses are built here, in
     * one place, so that no read can forget one.
     *
     * @param list<ContentType>  $types
     * @param ?Language          $only  the only language wanted, every language when null
     * @param ?list<string>      $keys  the only translation keys wanted, every key when null
     *
     * @return array<string, mixed>
     */
    private static function published(array $types, ?Language $only = null, ?array $keys = null): array
    {
        $postTypes = array_map(PostTypeRegistrar::postTypeOf(...), $types);

        return [
            'post_type' => count($postTypes) === 1 ? $postTypes[0] : $postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'ignore_sticky_posts' => true,
            // The language is the plugin's business (the meta clause below), never Polylang's: 'lang' => ''
            // tells it the query is already filtered, so a request made in one language of the site
            // still reaches every document.
            'lang' => '',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => InternalMetaRegistrar::MD_HASH, 'value' => '', 'compare' => '!='],
                // A stored language outside the three of the site (a row edited by hand) is invisible like a
                // missing one, rather than fatal for the whole page: languageOf() can then never throw.
                $only === null
                    ? ['key' => InternalMetaRegistrar::LANG, 'value' => Language::values(), 'compare' => 'IN']
                    : ['key' => InternalMetaRegistrar::LANG, 'value' => $only->value],
                $keys === null
                    ? ['key' => InternalMetaRegistrar::TRANSLATION_KEY, 'value' => '', 'compare' => '!=']
                    : ['key' => InternalMetaRegistrar::TRANSLATION_KEY, 'value' => $keys, 'compare' => 'IN'],
            ],
        ];
    }

    /**
     * @return list<WP_Post>
     */
    private static function posts(WP_Query $query): array
    {
        return array_values(array_filter($query->posts, static fn (mixed $post): bool => $post instanceof WP_Post));
    }

    /**
     * Reads the terms of these posts in one query per post type, skipping
     * the posts whose terms are cached already (WordPress does): the
     * anchors of a page come with theirs, the translations served in their
     * place do not.
     *
     * @param list<WP_Post> $posts
     */
    private static function primeTerms(array $posts): void
    {
        $byType = [];
        foreach ($posts as $post) {
            $byType[$post->post_type][] = (int) $post->ID;
        }
        foreach ($byType as $postType => $ids) {
            update_object_term_cache($ids, $postType);
        }
    }

    /**
     * Reads the metas of every skill term of these posts in one query (the
     * code, the wordings of the other languages, the official flag), so
     * that the per-term reads of document() come from the cache.
     *
     * @param list<WP_Post> $posts
     */
    private static function primeSkillMetas(array $posts): void
    {
        $termIds = [];
        foreach ($posts as $post) {
            // The tags of every kind of document carry their names in metas too (ADR-024).
            foreach (self::terms($post, TaxonomyRegistrar::TECH_TAG) as $term) {
                $termIds[] = (int) $term->term_id;
            }
            if ($post->post_type !== PostTypeRegistrar::PROJECT) {
                continue;
            }
            foreach (self::terms($post, TaxonomyRegistrar::SKILL) as $term) {
                $termIds[] = (int) $term->term_id;
            }
        }

        if ($termIds !== []) {
            update_termmeta_cache(array_values(array_unique($termIds)));
        }
    }

    /**
     * @param array<string, string> $translations language value => slug of every published member of the
     *                                            group of the post, the post's own included (ADR-021)
     */
    private function document(WP_Post $post, array $translations, bool $isFallback, Language $requested): PublishedDocument
    {
        $id = (int) $post->ID;
        $type = PostTypeRegistrar::contentTypeOf($post->post_type)
            ?? throw new LogicException("Post {$id} has the type \"{$post->post_type}\", which the catalog never queries.");

        $fields = [
            'id' => $id,
            'type' => $type,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'link' => (string) get_permalink($post),
            'date' => self::instant($post, 'date'),
            'updated' => self::instant($post, 'modified'),
            'markdown' => self::text($id, InternalMetaRegistrar::MD_SOURCE),
            'lang' => self::languageOf($id),
            'translationKey' => self::translationKeyOf($id),
            'seoDescription' => self::text($id, CommonMetaRegistrar::SEO_DESCRIPTION),
            'translations' => $translations,
            'isFallback' => $isFallback,
            'requestedLanguage' => $requested,
        ];

        if ($type !== ContentType::Page) {
            $fields += [
                'tags' => array_map(
                    static fn (WP_Term $term): DocumentTag => new DocumentTag($term->slug, $term->name, self::translations((int) $term->term_id, TaxonomyRegistrar::tagNameMeta(...))),
                    self::terms($post, TaxonomyRegistrar::TECH_TAG),
                ),
                'subtitle' => self::text($id, CommonMetaRegistrar::SUBTITLE),
                'readingTime' => Sanitizer::boundedInt(get_post_meta($id, CommonMetaRegistrar::READING_TIME, true), 0, CommonMetaRegistrar::READING_TIME_MAX_MINUTES),
                'featured' => Sanitizer::boolean(get_post_meta($id, CommonMetaRegistrar::FEATURED, true)),
            ];
        }

        if ($type === ContentType::Project) {
            $fields += [
                'status' => self::status($post),
                'skills' => self::skills($post),
                'isFilRouge' => Sanitizer::boolean(get_post_meta($id, ProjectMetaRegistrar::IS_FIL_ROUGE, true)),
                'role' => self::text($id, ProjectMetaRegistrar::PROJECT_ROLE),
                'periodStart' => self::day($id, ProjectMetaRegistrar::PROJECT_PERIOD_START),
                'periodEnd' => self::day($id, ProjectMetaRegistrar::PROJECT_PERIOD_END),
                'repoUrl' => self::text($id, ProjectMetaRegistrar::PROJECT_REPO_URL),
                'demoUrl' => self::text($id, ProjectMetaRegistrar::PROJECT_DEMO_URL),
                'client' => self::text($id, ProjectMetaRegistrar::PROJECT_CLIENT),
            ];
        }

        return new PublishedDocument(...$fields);
    }

    /**
     * The status from its taxonomy term, the source of truth (01-plugin-php.md
     * §4.4) and what the `status` filter of list() reads; the flat meta the
     * import mirrors it into is the fallback for a project that lost its term.
     */
    private static function status(WP_Post $post): ?ProjectStatus
    {
        foreach (self::terms($post, TaxonomyRegistrar::PROJECT_STATUS) as $term) {
            $status = ProjectStatus::tryFrom($term->slug);
            if ($status !== null) {
                return $status;
            }
        }

        return ProjectStatus::tryFrom(self::text((int) $post->ID, ProjectMetaRegistrar::PROJECT_STATUS));
    }

    /**
     * The competencies of a project, in the natural order of their codes
     * (WordPress keeps no order of its own for the terms of a post). A term
     * without a code is a referential, not a competency, and is left out.
     * Each carries every wording the term holds (its French name, the metas
     * of the other languages) and the official flag of the French one; the
     * presenter picks the wording of the language asked (lot 4 of ADR-020).
     *
     * @return list<DocumentSkill>
     */
    private static function skills(WP_Post $post): array
    {
        $skills = [];
        foreach (self::terms($post, TaxonomyRegistrar::SKILL) as $term) {
            $termId = (int) $term->term_id;
            $code = get_term_meta($termId, TaxonomyRegistrar::SKILL_CODE_META, true);
            if (!is_string($code) || $code === '') {
                continue;
            }

            $names = [Language::default()->value => $term->name] + self::translations($termId, TaxonomyRegistrar::skillNameMeta(...));

            $skills[] = new DocumentSkill(
                $term->slug,
                $code,
                $names,
                Sanitizer::boolean(get_term_meta($termId, TaxonomyRegistrar::SKILL_OFFICIAL_META, true)),
            );
        }

        usort($skills, static fn (DocumentSkill $a, DocumentSkill $b): int => strnatcmp($a->code, $b->code));

        return $skills;
    }

    /**
     * The wordings a term holds in the languages other than French, read
     * from the metas named by $metaKey (skillNameMeta or tagNameMeta).
     *
     * @param callable(Language): string $metaKey
     *
     * @return array<string, string> language value => wording
     */
    private static function translations(int $termId, callable $metaKey): array
    {
        $translations = [];
        foreach (Language::cases() as $language) {
            if ($language->isDefault()) {
                continue;
            }
            $wording = get_term_meta($termId, $metaKey($language), true);
            if (is_string($wording) && $wording !== '') {
                $translations[$language->value] = $wording;
            }
        }

        return $translations;
    }

    /**
     * @return list<WP_Term>
     */
    private static function terms(WP_Post $post, string $taxonomy): array
    {
        $terms = get_the_terms($post, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_filter($terms, static fn (mixed $term): bool => $term instanceof WP_Term));
    }

    /**
     * The publication or modification instant of a post, in UTC, from the
     * GMT column; the local column converted when the GMT one is unset,
     * which WordPress does for a draft and never for a published post.
     */
    private static function instant(WP_Post $post, string $field): DateTimeImmutable
    {
        $value = get_post_datetime($post, $field, 'gmt');
        if ($value === false) {
            $value = get_post_datetime($post, $field, 'local');
        }
        if ($value === false) {
            // No date at all, which WordPress does not allow for a published post.
            return new DateTimeImmutable('@0');
        }

        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    private static function text(int $postId, string $key): string
    {
        $value = get_post_meta($postId, $key, true);

        return is_string($value) ? $value : '';
    }

    /**
     * The stored language of a post the query clause already guaranteed to
     * have among the three of the site: an empty or unknown value here is a
     * programming error, not a document to serve.
     */
    private static function languageOf(int $postId): Language
    {
        $stored = self::text($postId, InternalMetaRegistrar::LANG);

        return Language::tryFrom($stored)
            ?? throw new LogicException("Post {$postId} stores the language \"{$stored}\", which the catalog never queries.");
    }

    /** The stored translation key of a post the query clause already guaranteed to have one. */
    private static function translationKeyOf(int $postId): string
    {
        $key = self::text($postId, InternalMetaRegistrar::TRANSLATION_KEY);
        if ($key === '') {
            throw new LogicException("Post {$postId} stores no translation key, which the catalog never queries.");
        }

        return $key;
    }

    /** A calendar day stored as "YYYY-MM-DD", or null when the meta is empty or malformed. */
    private static function day(int $postId, string $key): ?DateTimeImmutable
    {
        $value = Sanitizer::isoDate(get_post_meta($postId, $key, true));
        if ($value === '') {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $day === false ? null : $day;
    }
}

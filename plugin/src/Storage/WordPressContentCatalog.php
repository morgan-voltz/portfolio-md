<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Voltz\PortfolioMd\Domain\ContentType;
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
 * Three rules keep it safe to expose and cheap to call:
 *   - only published posts without password are read (`post_status`
 *     publish, `has_password` false): drafts, pending, private, scheduled
 *     and trashed posts never leave the store, whoever asks;
 *   - only posts that hold a document are read (`_portfolio_md_hash` set,
 *     ADR-005): a post made by hand in the admin has no Markdown to serve,
 *     and the native page type is full of such posts;
 *   - a page of posts is one query that also primes the term and meta
 *     caches of its posts (WP_Query does so by default), and the codes of
 *     their skill terms are primed in one more: the per-post reads below hit
 *     the caches, not the base, whatever the page size.
 *
 * This is the only class of the read side that talks to WordPress; it is
 * exercised by the WP-CLI smoke test rest-smoke.php rather than by the
 * unit suite, like the other two stores.
 */
final class WordPressContentCatalog implements ContentCatalog
{
    public function list(ContentType $type, CatalogQuery $query): CatalogPage
    {
        $args = self::published([$type]) + [
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
        $posts = self::posts($found);
        self::primeSkillCodes($posts);

        $total = (int) $found->found_posts;
        if ($posts === [] && $query->page > 1) {
            // WP_Query does not count the rows when a page comes back empty: the count is run
            // again without the page, the way the native collections do, so that a page beyond
            // the last can be told from an empty collection.
            $total = self::count($args);
        }

        return new CatalogPage(array_map($this->document(...), $posts), $total, $query->page, $query->perPage);
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

    public function find(ContentType $type, string $slug): ?PublishedDocument
    {
        $found = new WP_Query(self::published([$type]) + ['name' => $slug, 'posts_per_page' => 1, 'no_found_rows' => true]);
        $posts = self::posts($found);
        if ($posts === []) {
            return null;
        }

        self::primeSkillCodes($posts);

        return $this->document($posts[0]);
    }

    public function sitemap(): array
    {
        $found = new WP_Query(self::published(ContentType::cases()) + [
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'orderby' => ['type' => 'ASC', 'name' => 'ASC'],
        ]);

        $entries = [];
        foreach (self::posts($found) as $post) {
            $type = PostTypeRegistrar::contentTypeOf($post->post_type);
            if ($type === null) {
                continue;
            }
            $entries[] = new SitemapEntry($type, $post->post_name, self::instant($post, 'modified'));
        }

        return $entries;
    }

    /**
     * The query arguments shared by every read: the posts of these kinds
     * that the site shows to anyone and that hold a document.
     *
     * @param list<ContentType> $types
     *
     * @return array<string, mixed>
     */
    private static function published(array $types): array
    {
        $postTypes = array_map(PostTypeRegistrar::postTypeOf(...), $types);

        return [
            'post_type' => count($postTypes) === 1 ? $postTypes[0] : $postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'ignore_sticky_posts' => true,
            'meta_query' => [[
                'key' => InternalMetaRegistrar::MD_HASH,
                'value' => '',
                'compare' => '!=',
            ]],
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
     * Reads the codes of every skill term of these posts in one query, so
     * that the per-term reads of document() come from the cache.
     *
     * @param list<WP_Post> $posts
     */
    private static function primeSkillCodes(array $posts): void
    {
        $termIds = [];
        foreach ($posts as $post) {
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

    private function document(WP_Post $post): PublishedDocument
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
            'seoDescription' => self::text($id, CommonMetaRegistrar::SEO_DESCRIPTION),
        ];

        if ($type !== ContentType::Page) {
            $fields += [
                'tags' => array_map(
                    static fn (WP_Term $term): DocumentTag => new DocumentTag($term->slug, $term->name),
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
     *
     * @return list<DocumentSkill>
     */
    private static function skills(WP_Post $post): array
    {
        $skills = [];
        foreach (self::terms($post, TaxonomyRegistrar::SKILL) as $term) {
            $code = get_term_meta((int) $term->term_id, TaxonomyRegistrar::SKILL_CODE_META, true);
            if (!is_string($code) || $code === '') {
                continue;
            }
            $skills[] = new DocumentSkill($term->slug, $code, $term->name);
        }

        usort($skills, static fn (DocumentSkill $a, DocumentSkill $b): int => strnatcmp($a->code, $b->code));

        return $skills;
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

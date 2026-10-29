<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use RuntimeException;
use Throwable;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\MetaWriter;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\ContentRecord;
use Voltz\PortfolioMd\Service\ContentRepository;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * ContentRepository on top of wp_posts, the taxonomies and the post metas:
 * phases four and five of the pipeline (01-plugin-php.md §4.4 and §4.5).
 * Articles and projects go to the plugin's post types; pages go to the
 * native page type, with no term and only the metas a page carries.
 *
 * This is the only class of the import that talks to WordPress, and it is
 * exercised by the WP-CLI smoke test rather than by the unit suite. Five
 * WordPress rules shape it:
 *   - wp_insert_post() and wp_update_post() unslash their input like
 *     update_post_meta() does, so every string goes through wp_slash();
 *   - the stored HTML passes through kses (01-plugin-php.md §11, the pass
 *     of HtmlSanitizer, shared with the preview route);
 *   - WordPress may silently change a slug (wp_unique_post_slug), which
 *     would break the link with the Markdown file: a changed slug is an error;
 *   - a trashed post is renamed to free its slug and left out of the usual
 *     queries: it is looked up on purpose, so that it is never doubled;
 *   - a published post dated more than a minute ahead is silently turned
 *     into a scheduled one: such a date is refused before anything is written.
 *
 * A post is written in two steps, the row first, then its terms and metas.
 * Whatever can be refused (terms, date) is resolved before the first step;
 * a failure in the second step removes a post that was just created, and
 * names the half-updated post otherwise.
 */
final class WordPressContentRepository implements ContentRepository
{
    private const LAST_SYNC_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** Where WordPress keeps the slug of a trashed post while the post is named `<slug>__trashed`. */
    private const DESIRED_SLUG_META = '_wp_desired_post_slug';

    /**
     * The parser reads the slug written in a stored document (findById):
     * the post's own URL slug may have been changed behind the document's
     * back, and the document is what the slug rules are about.
     */
    public function __construct(private readonly FrontmatterParser $frontmatterParser = new FrontmatterParser())
    {
    }

    public function findBySlug(ContentType $type, string $slug): ?StoredContent
    {
        $postType = self::postType($type);
        $post = self::firstPost(['post_type' => $postType, 'name' => $slug, 'post_status' => 'any']);
        $inTrash = false;

        if ($post === null) {
            // 'any' leaves the trash out, and a trashed post no longer carries its slug.
            $post = self::firstPost(['post_type' => $postType, 'post_status' => 'trash', 'meta_key' => self::DESIRED_SLUG_META, 'meta_value' => $slug])
                ?? self::firstPost(['post_type' => $postType, 'post_status' => 'trash', 'name' => $slug]);
            $inTrash = $post !== null;
        }

        if ($post === null) {
            return null;
        }

        return self::stored($post, $inTrash);
    }

    public function findById(ContentType $type, int $postId): ?StoredContent
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post || $post->post_type !== self::postType($type)) {
            return null;
        }

        return self::stored($post, $post->post_status === 'trash', $this->documentSlug((int) $post->ID));
    }

    /**
     * The slug written in the document the post holds, or null when it
     * holds none (or one the parser can no longer read).
     */
    private function documentSlug(int $postId): ?string
    {
        $source = self::meta($postId, InternalMetaRegistrar::MD_SOURCE);
        if ($source === '') {
            return null;
        }

        try {
            return $this->frontmatterParser->parse($source)->frontmatter->slug;
        } catch (MalformedDocumentException | InvalidFrontmatterException) {
            return null;
        }
    }

    public function save(ContentRecord $record): int
    {
        $frontmatter = $record->frontmatter;
        $created = $record->id === null;
        $status = $created ? $record->postStatus : (string) get_post_status($record->id);

        // Everything that can be refused is resolved before the post row is touched.
        $terms = $this->resolveTerms($record);
        $postarr = [
            'post_type' => self::postType($frontmatter->type),
            'post_title' => $frontmatter->title,
            'post_name' => $frontmatter->slug,
            'post_content' => HtmlSanitizer::sanitize($record->html),
        ];

        if ($frontmatter->type === ContentType::Page) {
            // A documented page lives at the root of the site: the links rewritten from the
            // documents point there, and its attributes box is not on its screen.
            $postarr['post_parent'] = 0;
        }

        if ($frontmatter->date !== null) {
            // The frontmatter date is a calendar day; it becomes midnight in the site's timezone.
            $local = $frontmatter->date->format('Y-m-d') . ' 00:00:00';
            $postarr['post_date'] = $local;
            $postarr['post_date_gmt'] = get_gmt_from_date($local);
            self::assertNotScheduled($frontmatter, $postarr['post_date_gmt'], $status);
        }

        if ($created) {
            $postarr['post_status'] = $record->postStatus;
            $postarr['post_author'] = get_current_user_id();
            $result = wp_insert_post(wp_slash($postarr), true);
        } else {
            $postarr['ID'] = $record->id;
            $result = wp_update_post(wp_slash($postarr), true);
        }

        if ($result instanceof WP_Error) {
            throw new RuntimeException("Cannot save \"{$frontmatter->slug}\": " . $result->get_error_message());
        }
        $postId = (int) $result;
        if ($postId === 0) {
            throw new RuntimeException("Cannot save \"{$frontmatter->slug}\": WordPress returned no post id.");
        }

        try {
            self::assertSlug($postId, $frontmatter->slug);
            foreach ($terms as $taxonomy => $termIds) {
                self::setObjectTerms($postId, $termIds, $taxonomy);
            }
            self::writeMetas($postId, $record);
        } catch (Throwable $failure) {
            if ($created) {
                // Nothing of a new post may survive a failure: the next import starts from scratch.
                wp_delete_post($postId, true);
                throw $failure;
            }

            throw new RuntimeException(
                "Post {$postId} (\"{$frontmatter->slug}\") is only half updated: its title and content were written, its terms and metas were not"
                . " ({$failure->getMessage()}). Import it again once the cause is fixed.",
                0,
                $failure,
            );
        }

        return $postId;
    }

    public function trash(int $postId): void
    {
        $result = wp_trash_post($postId);
        if (!$result instanceof WP_Post) {
            throw new RuntimeException(
                $result === null
                    ? "Cannot move post {$postId} to the trash: there is no such post."
                    : "Cannot move post {$postId} to the trash: WordPress refused (already there?).",
            );
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function firstPost(array $query): ?WP_Post
    {
        $posts = get_posts($query + ['numberposts' => 1]);
        $post = $posts[0] ?? null;

        return $post instanceof WP_Post ? $post : null;
    }

    /**
     * wp_insert_post() turns a published post dated more than a minute ahead
     * into a scheduled one, silently: the post would vanish from the site
     * while the import reports success. The date is refused instead.
     */
    private static function assertNotScheduled(Frontmatter $frontmatter, string $dateGmt, string $status): void
    {
        if ($status !== 'publish' || strtotime($dateGmt) - time() < MINUTE_IN_SECONDS) {
            return;
        }

        $day = $frontmatter->date?->format('Y-m-d') ?? '';
        throw new RuntimeException("Cannot save \"{$frontmatter->slug}\": its date {$day} is in the future and WordPress would schedule the post instead of publishing it. Fix the date, or import the document as a draft (--draft) if it is meant to appear later.");
    }

    /**
     * wp_unique_post_slug() suffixes a slug already used by another post of
     * the same type; the Markdown file and WordPress would then disagree
     * forever.
     */
    private static function assertSlug(int $postId, string $slug): void
    {
        $stored = (string) get_post_field('post_name', $postId);
        if ($stored !== $slug) {
            throw new RuntimeException("Slug collision: WordPress stored \"{$stored}\" for post {$postId} instead of \"{$slug}\" (already used by another post of this type).");
        }
    }

    /**
     * @return array<string, list<int>> taxonomy => term ids
     */
    private function resolveTerms(ContentRecord $record): array
    {
        $frontmatter = $record->frontmatter;
        if ($frontmatter->type === ContentType::Page) {
            // None of the plugin's taxonomies is attached to the native page type.
            return [];
        }

        $terms = [TaxonomyRegistrar::TECH_TAG => $this->ensureTechTags($frontmatter->tags)];

        if ($frontmatter->type === ContentType::Project) {
            $terms[TaxonomyRegistrar::PROJECT_STATUS] = [self::projectStatusTermId($frontmatter)];
            $terms[TaxonomyRegistrar::SKILL] = $record->skillTermIds;
        }

        return $terms;
    }

    /**
     * Tech tags are free slugs (content/README.md): a tag that does not exist
     * yet is created with the slug as its name, to be renamed in the admin.
     *
     * @param list<string> $slugs
     *
     * @return list<int>
     */
    private function ensureTechTags(array $slugs): array
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, TaxonomyRegistrar::TECH_TAG);
            if ($term instanceof WP_Term) {
                $ids[] = (int) $term->term_id;
                continue;
            }

            $inserted = wp_insert_term($slug, TaxonomyRegistrar::TECH_TAG, ['slug' => $slug]);
            if ($inserted instanceof WP_Error) {
                throw new RuntimeException("Cannot create tech tag \"{$slug}\": " . $inserted->get_error_message());
            }
            $ids[] = (int) $inserted['term_id'];
        }

        return $ids;
    }

    private static function projectStatusTermId(Frontmatter $frontmatter): int
    {
        $slug = $frontmatter->status?->value ?? '';
        $term = get_term_by('slug', $slug, TaxonomyRegistrar::PROJECT_STATUS);
        if (!$term instanceof WP_Term) {
            throw new RuntimeException("Project status term \"{$slug}\" is missing: deactivate and reactivate the plugin to seed it.");
        }

        return (int) $term->term_id;
    }

    /**
     * @param list<int> $termIds
     */
    private static function setObjectTerms(int $postId, array $termIds, string $taxonomy): void
    {
        $result = wp_set_object_terms($postId, $termIds, $taxonomy, false);
        if ($result instanceof WP_Error) {
            throw new RuntimeException("Cannot set {$taxonomy} terms on post {$postId}: " . $result->get_error_message());
        }
    }

    private static function writeMetas(int $postId, ContentRecord $record): void
    {
        $frontmatter = $record->frontmatter;

        MetaWriter::update($postId, CommonMetaRegistrar::SEO_DESCRIPTION, $frontmatter->seoDescription);
        if ($frontmatter->type !== ContentType::Page) {
            // The other public metas are registered on the plugin's types only.
            MetaWriter::update($postId, CommonMetaRegistrar::FEATURED, $frontmatter->featured);
            MetaWriter::update($postId, CommonMetaRegistrar::READING_TIME, $frontmatter->readingTime ?? 0);
            MetaWriter::update($postId, CommonMetaRegistrar::SUBTITLE, $frontmatter->subtitle);
        }

        if ($frontmatter->type === ContentType::Project) {
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_STATUS, $frontmatter->status?->value ?? '');
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_ROLE, $frontmatter->role);
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_PERIOD_START, $frontmatter->periodStart?->format('Y-m-d') ?? '');
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_PERIOD_END, $frontmatter->periodEnd?->format('Y-m-d') ?? '');
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_REPO_URL, $frontmatter->repoUrl);
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_DEMO_URL, $frontmatter->demoUrl);
            MetaWriter::update($postId, ProjectMetaRegistrar::PROJECT_CLIENT, $frontmatter->client);
            MetaWriter::update($postId, ProjectMetaRegistrar::IS_FIL_ROUGE, $frontmatter->isFilRouge);
        }

        MetaWriter::update($postId, InternalMetaRegistrar::MD_SOURCE, $record->markdown);
        MetaWriter::update($postId, InternalMetaRegistrar::MD_HASH, $record->hash);
        MetaWriter::update($postId, InternalMetaRegistrar::SOURCE_TYPE, $record->source->value);
        MetaWriter::update($postId, InternalMetaRegistrar::LAST_SYNC_AT, $record->syncedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::LAST_SYNC_FORMAT));
        MetaWriter::update($postId, InternalMetaRegistrar::RENDER_VERSION, $record->renderVersion);
    }

    private static function stored(WP_Post $post, bool $inTrash, ?string $documentSlug = null): StoredContent
    {
        $id = (int) $post->ID;

        return new StoredContent(
            $id,
            self::meta($id, InternalMetaRegistrar::MD_HASH),
            ContentSource::tryFrom(self::meta($id, InternalMetaRegistrar::SOURCE_TYPE)),
            self::meta($id, InternalMetaRegistrar::RENDER_VERSION),
            $inTrash,
            $documentSlug,
        );
    }

    private static function meta(int $postId, string $key): string
    {
        $value = get_post_meta($postId, $key, true);

        return is_string($value) ? $value : '';
    }

    private static function postType(ContentType $type): string
    {
        return PostTypeRegistrar::postTypeOf($type);
    }
}

<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use InvalidArgumentException;
use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\MetaWriter;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
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
 *
 * This is the only class of the import that talks to WordPress, and it is
 * exercised by the WP-CLI smoke test rather than by the unit suite. Three
 * WordPress rules shape it:
 *   - wp_insert_post() and wp_update_post() unslash their input like
 *     update_post_meta() does, so every string goes through wp_slash();
 *   - the stored HTML passes through kses (01-plugin-php.md §11), with the
 *     checkbox of a GFM task list added to the allowed tags;
 *   - WordPress may silently change a slug (wp_unique_post_slug), which
 *     would break the link with the Markdown file: a changed slug is an error.
 */
final class WordPressContentRepository implements ContentRepository
{
    private const LAST_SYNC_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function findBySlug(ContentType $type, string $slug): ?StoredContent
    {
        $posts = get_posts([
            'post_type' => self::postType($type),
            'name' => $slug,
            'post_status' => 'any',
            'numberposts' => 1,
        ]);

        if ($posts === [] || !$posts[0] instanceof WP_Post) {
            return null;
        }

        $id = (int) $posts[0]->ID;
        $source = ContentSource::tryFrom(self::meta($id, InternalMetaRegistrar::SOURCE_TYPE));

        return new StoredContent(
            $id,
            self::meta($id, InternalMetaRegistrar::MD_HASH),
            $source,
            self::meta($id, InternalMetaRegistrar::RENDER_VERSION),
        );
    }

    public function save(ContentRecord $record): int
    {
        $frontmatter = $record->frontmatter;
        $postarr = [
            'post_type' => self::postType($frontmatter->type),
            'post_title' => $frontmatter->title,
            'post_name' => $frontmatter->slug,
            'post_content' => self::sanitizeHtml($record->html),
        ];

        if ($frontmatter->date !== null) {
            // The frontmatter date is a calendar day; it becomes midnight in the site's timezone.
            $local = $frontmatter->date->format('Y-m-d') . ' 00:00:00';
            $postarr['post_date'] = $local;
            $postarr['post_date_gmt'] = get_gmt_from_date($local);
        }

        if ($record->id === null) {
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

        $this->assertSlug($postId, $frontmatter->slug, $record->id === null);
        $this->setTerms($postId, $record);
        $this->writeMetas($postId, $record);

        return $postId;
    }

    /**
     * wp_unique_post_slug() suffixes a slug already used by another post of
     * the same type; the Markdown file and WordPress would then disagree
     * forever. A post created with the wrong slug is removed again.
     */
    private function assertSlug(int $postId, string $slug, bool $created): void
    {
        $stored = (string) get_post_field('post_name', $postId);
        if ($stored === $slug) {
            return;
        }

        if ($created) {
            wp_delete_post($postId, true);
        }

        throw new RuntimeException("Slug collision: WordPress stored \"{$stored}\" for post {$postId} instead of \"{$slug}\" (already used by another post of this type).");
    }

    private function setTerms(int $postId, ContentRecord $record): void
    {
        $frontmatter = $record->frontmatter;
        self::setObjectTerms($postId, $this->ensureTechTags($frontmatter->tags), TaxonomyRegistrar::TECH_TAG);

        if ($frontmatter->type !== ContentType::Project) {
            return;
        }

        self::setObjectTerms($postId, [self::projectStatusTermId($frontmatter)], TaxonomyRegistrar::PROJECT_STATUS);
        self::setObjectTerms($postId, $record->skillTermIds, TaxonomyRegistrar::SKILL);
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

    private function writeMetas(int $postId, ContentRecord $record): void
    {
        $frontmatter = $record->frontmatter;

        MetaWriter::update($postId, CommonMetaRegistrar::SEO_DESCRIPTION, $frontmatter->seoDescription);
        MetaWriter::update($postId, CommonMetaRegistrar::FEATURED, $frontmatter->featured);
        MetaWriter::update($postId, CommonMetaRegistrar::READING_TIME, $frontmatter->readingTime ?? 0);
        MetaWriter::update($postId, CommonMetaRegistrar::SUBTITLE, $frontmatter->subtitle);

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

    /**
     * Last line of defence on the derived HTML (01-plugin-php.md §11). The
     * Markdown parser already escapes raw HTML, so on real content this is a
     * no-op; the smoke test checks it. GFM task lists render as checkboxes,
     * which the `post` context of kses does not know.
     */
    private static function sanitizeHtml(string $html): string
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['input'] = ['type' => true, 'checked' => true, 'disabled' => true];

        return wp_kses($html, $allowed);
    }

    private static function meta(int $postId, string $key): string
    {
        $value = get_post_meta($postId, $key, true);

        return is_string($value) ? $value : '';
    }

    private static function postType(ContentType $type): string
    {
        return match ($type) {
            ContentType::Article => PostTypeRegistrar::ARTICLE,
            ContentType::Project => PostTypeRegistrar::PROJECT,
            ContentType::Page => throw new InvalidArgumentException('Pages are not stored by this repository.'),
        };
    }
}

<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use RuntimeException;
use Voltz\PortfolioMd\Service\SkillTermGateway;
use Voltz\PortfolioMd\Service\SkillTermRecord;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_Error;
use WP_Term;

/**
 * SkillTermGateway on top of the WordPress term functions.
 *
 * This is the only place of the skills synchronization that talks to
 * WordPress; it is exercised by the WP-CLI smoke test, not by the unit suite.
 */
final class WordPressSkillTermGateway implements SkillTermGateway
{
    public function __construct(
        private readonly string $taxonomy = TaxonomyRegistrar::SKILL,
        private readonly string $codeMetaKey = TaxonomyRegistrar::SKILL_CODE_META,
    ) {
    }

    public function findByCode(string $code): ?SkillTermRecord
    {
        $terms = get_terms([
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
            'meta_key' => $this->codeMetaKey,
            'meta_value' => $code,
            'number' => 1,
        ]);

        if ($terms instanceof WP_Error || $terms === []) {
            return null;
        }

        return $this->toRecord($terms[0]);
    }

    public function findBySlug(string $slug, int $parentId): ?SkillTermRecord
    {
        $term = get_term_by('slug', $slug, $this->taxonomy);

        if (!$term instanceof WP_Term || (int) $term->parent !== $parentId) {
            return null;
        }

        return $this->toRecord($term);
    }

    public function insert(string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
    {
        // wp_insert_term() unslashes name and description before storing them.
        $result = wp_insert_term(wp_slash($name), $this->taxonomy, [
            'slug' => $slug,
            'description' => wp_slash($description),
            'parent' => $parentId,
        ]);

        if ($result instanceof WP_Error) {
            throw new RuntimeException("Cannot create term \"{$name}\": " . $result->get_error_message());
        }

        $this->writeCode((int) $result['term_id'], $code);

        return $this->assertSlug($this->load((int) $result['term_id']), $slug);
    }

    public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
    {
        $result = wp_update_term($termId, $this->taxonomy, [
            'name' => wp_slash($name),
            'slug' => $slug,
            'description' => wp_slash($description),
            'parent' => $parentId,
        ]);

        if ($result instanceof WP_Error) {
            throw new RuntimeException("Cannot update term {$termId} \"{$name}\": " . $result->get_error_message());
        }

        $this->writeCode($termId, $code);

        return $this->assertSlug($this->load($termId), $slug);
    }

    /**
     * WordPress may store a slug different from the requested one: suffixed by
     * wp_unique_term_slug() when it already exists elsewhere in the taxonomy,
     * or sanitized/truncated by sanitize_title(). Either way the grid would
     * disagree with WordPress forever, so fail loudly with both values.
     */
    private function assertSlug(SkillTermRecord $record, string $requestedSlug): SkillTermRecord
    {
        if ($record->slug !== $requestedSlug) {
            throw new RuntimeException("Slug collision: WordPress stored \"{$record->slug}\" for term {$record->id} instead of \"{$requestedSlug}\" (already taken elsewhere in the taxonomy, or altered by sanitization).");
        }

        return $record;
    }

    public function all(): array
    {
        $terms = get_terms(['taxonomy' => $this->taxonomy, 'hide_empty' => false]);

        if ($terms instanceof WP_Error) {
            return [];
        }

        return array_map(fn (WP_Term $term): SkillTermRecord => $this->toRecord($term), array_values($terms));
    }

    private function writeCode(int $termId, ?string $code): void
    {
        if ($code === null) {
            delete_term_meta($termId, $this->codeMetaKey);

            return;
        }

        update_term_meta($termId, $this->codeMetaKey, $code);
    }

    private function load(int $termId): SkillTermRecord
    {
        $term = get_term($termId, $this->taxonomy);

        if (!$term instanceof WP_Term) {
            throw new RuntimeException("Term {$termId} vanished after being written.");
        }

        return $this->toRecord($term);
    }

    private function toRecord(WP_Term $term): SkillTermRecord
    {
        $code = get_term_meta($term->term_id, $this->codeMetaKey, true);

        return new SkillTermRecord(
            (int) $term->term_id,
            $term->name,
            $term->slug,
            $term->description,
            (int) $term->parent,
            is_string($code) && $code !== '' ? $code : null,
        );
    }
}

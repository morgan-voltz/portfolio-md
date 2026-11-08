<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use RuntimeException;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\Sanitizer;
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
 *
 * Besides the term itself, three kinds of term meta: the code
 * (`_portfolio_skill_code`), one wording per language other than French
 * (`_portfolio_skill_name_de`, `_portfolio_skill_name_en`) and the
 * `_portfolio_skill_official` flag of the French wording (lot 4 of
 * ADR-020). A meta that would be empty or false is deleted rather than
 * stored, so that a referential, or a term made by hand, carries none.
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

    public function insert(string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord
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

        $this->writeMetas((int) $result['term_id'], $code, $translations, $official);

        return $this->assertSlug($this->load((int) $result['term_id']), $slug);
    }

    public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord
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

        $this->writeMetas($termId, $code, $translations, $official);

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

    /**
     * @param array<string, string> $translations
     */
    private function writeMetas(int $termId, ?string $code, array $translations, bool $official): void
    {
        $this->writeOrDelete($termId, $this->codeMetaKey, $code ?? '');

        foreach (self::otherLanguages() as $language) {
            $this->writeOrDelete($termId, TaxonomyRegistrar::skillNameMeta($language), $translations[$language->value] ?? '');
        }

        $this->writeOrDelete($termId, TaxonomyRegistrar::SKILL_OFFICIAL_META, $official ? '1' : '');
    }

    /**
     * An empty value means "no meta": absent and empty read the same, and
     * the store keeps no row for a referential or a hand-made term. The
     * value is slashed because update_metadata() unslashes it before storing.
     */
    private function writeOrDelete(int $termId, string $key, string $value): void
    {
        if ($value === '') {
            delete_term_meta($termId, $key);

            return;
        }

        update_term_meta($termId, $key, wp_slash($value));
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
        $termId = (int) $term->term_id;
        $code = get_term_meta($termId, $this->codeMetaKey, true);

        $translations = [];
        foreach (self::otherLanguages() as $language) {
            $wording = get_term_meta($termId, TaxonomyRegistrar::skillNameMeta($language), true);
            if (is_string($wording) && $wording !== '') {
                $translations[$language->value] = $wording;
            }
        }

        return new SkillTermRecord(
            $termId,
            $term->name,
            $term->slug,
            $term->description,
            (int) $term->parent,
            is_string($code) && $code !== '' ? $code : null,
            $translations,
            Sanitizer::boolean(get_term_meta($termId, TaxonomyRegistrar::SKILL_OFFICIAL_META, true)),
        );
    }

    /**
     * @return list<Language>
     */
    private static function otherLanguages(): array
    {
        return array_values(array_filter(Language::cases(), static fn (Language $language): bool => !$language->isDefault()));
    }
}

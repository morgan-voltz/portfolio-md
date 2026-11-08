<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use RuntimeException;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\TagTermGateway;
use Voltz\PortfolioMd\Service\TagTermRecord;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_Error;
use WP_Term;

/**
 * The tech_tag terms over WordPress (ADR-024): the French name is the term
 * name, the other wordings live in the term metas of TaxonomyRegistrar
 * (`_portfolio_tag_name_<lang>`), written when present and deleted when
 * empty, as the skills gateway does.
 */
final class WordPressTagTermGateway implements TagTermGateway
{
    public function __construct(private readonly string $taxonomy = TaxonomyRegistrar::TECH_TAG)
    {
    }

    public function findBySlug(string $slug): ?TagTermRecord
    {
        $term = get_term_by('slug', $slug, $this->taxonomy);

        return $term instanceof WP_Term ? $this->toRecord($term) : null;
    }

    public function insert(string $slug, string $name, array $translations = []): TagTermRecord
    {
        // wp_insert_term() unslashes the name before storing it.
        $result = wp_insert_term(wp_slash($name), $this->taxonomy, ['slug' => $slug]);
        if ($result instanceof WP_Error) {
            throw new RuntimeException("Cannot create tag \"{$slug}\": " . $result->get_error_message());
        }
        $termId = (int) $result['term_id'];
        $this->writeMetas($termId, $translations);

        return $this->assertSlug($this->load($termId), $slug);
    }

    public function update(int $termId, string $name, array $translations = []): TagTermRecord
    {
        $result = wp_update_term($termId, $this->taxonomy, ['name' => wp_slash($name)]);
        if ($result instanceof WP_Error) {
            throw new RuntimeException("Cannot rename tag {$termId} to \"{$name}\": " . $result->get_error_message());
        }
        $this->writeMetas($termId, $translations);

        return $this->load($termId);
    }

    public function all(): array
    {
        $terms = get_terms(['taxonomy' => $this->taxonomy, 'hide_empty' => false]);
        if ($terms instanceof WP_Error) {
            return [];
        }

        return array_map(fn (WP_Term $term): TagTermRecord => $this->toRecord($term), array_values($terms));
    }

    /**
     * @param array<string, string> $translations
     */
    private function writeMetas(int $termId, array $translations): void
    {
        foreach (self::otherLanguages() as $language) {
            $key = TaxonomyRegistrar::tagNameMeta($language);
            $value = $translations[$language->value] ?? '';
            if ($value === '') {
                delete_term_meta($termId, $key);
                continue;
            }
            update_term_meta($termId, $key, wp_slash($value));
        }
    }

    private function assertSlug(TagTermRecord $record, string $requestedSlug): TagTermRecord
    {
        if ($record->slug !== $requestedSlug) {
            throw new RuntimeException("Slug collision: WordPress stored \"{$record->slug}\" for term {$record->id} instead of \"{$requestedSlug}\".");
        }

        return $record;
    }

    private function load(int $termId): TagTermRecord
    {
        $term = get_term($termId, $this->taxonomy);
        if (!$term instanceof WP_Term) {
            throw new RuntimeException("Term {$termId} vanished after being written.");
        }

        return $this->toRecord($term);
    }

    private function toRecord(WP_Term $term): TagTermRecord
    {
        $termId = (int) $term->term_id;
        $translations = [];
        foreach (self::otherLanguages() as $language) {
            $wording = get_term_meta($termId, TaxonomyRegistrar::tagNameMeta($language), true);
            if (is_string($wording) && $wording !== '') {
                $translations[$language->value] = $wording;
            }
        }

        return new TagTermRecord($termId, $term->slug, $term->name, $translations);
    }

    /**
     * @return list<Language>
     */
    private static function otherLanguages(): array
    {
        return array_values(array_filter(Language::cases(), static fn (Language $language): bool => !$language->isDefault()));
    }
}

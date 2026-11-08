<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Service\SitemapEntry;

/**
 * The JSON of the public endpoints (01-plugin-php.md §7.3), as arrays the
 * REST server encodes. Pure: no WordPress call, so the unit suite pins the
 * exact shape a consumer binds to.
 *
 * Four conventions, the same for every kind:
 *   - the keys are those of the frontmatter, in snake_case, in the order
 *     of §7.3, the Markdown last; `link` (the permalink) is added after the
 *     title, and `type` tells the kind; the five keys of ADR-021 (`lang`,
 *     `translation_key`, `translations`, `is_fallback`, `requested_language`)
 *     come right after `slug`, and `translations` always encodes as a JSON
 *     object, because it always holds the document's own slug;
 *   - an optional text that is empty is `null`, never `""`, and a reading
 *     time of zero minutes is `null` too: the consumer tests one thing;
 *   - instants are ISO 8601 in UTC with a `Z` ("2026-10-26T22:00:00Z"),
 *     calendar days are "YYYY-MM-DD";
 *   - a term is an object: a tag `{slug, name}`, a skill `{slug, code,
 *     label, official}`, a status `{slug, label}`;
 *   - the labels of a status and of a skill are in the language the reader
 *     asked (`requested_language`), not in the language of the document
 *     (lot 4 of ADR-020): they are the vocabulary of the page around the
 *     document, which a French fallback served to a German reader does not
 *     change; a skill falls back to its French wording when the term has no
 *     translation, and `official` says whether the wording shown is the
 *     referential's own, which a translation never is. Tag names stay those
 *     of the terms.
 */
final class DocumentPresenter
{
    public const INSTANT_FORMAT = 'Y-m-d\TH:i:s\Z';
    public const DAY_FORMAT = 'Y-m-d';

    /**
     * @return array<string, mixed>
     */
    public function present(PublishedDocument $document): array
    {
        $data = [
            'id' => $document->id,
            'type' => $document->type->value,
            'slug' => $document->slug,
            'lang' => $document->lang->value,
            'translation_key' => $document->translationKey,
            'translations' => self::translations($document),
            'is_fallback' => $document->isFallback,
            'requested_language' => $document->requestedLanguage->value,
            'title' => $document->title,
            'link' => $document->link,
            'date' => self::instant($document->date),
            'updated' => self::instant($document->updated),
        ];

        if ($document->type !== ContentType::Page) {
            $data['tags'] = array_map(
                static fn (DocumentTag $tag): array => ['slug' => $tag->slug, 'name' => $tag->name],
                $document->tags,
            );
            $data['subtitle'] = self::textOrNull($document->subtitle);
        }

        $data['seo_description'] = self::textOrNull($document->seoDescription);

        if ($document->type !== ContentType::Page) {
            $data['reading_time'] = $document->readingTime > 0 ? $document->readingTime : null;
            $data['featured'] = $document->featured;
        }

        if ($document->type === ContentType::Project) {
            $language = $document->requestedLanguage;
            $data += [
                'status' => $document->status === null ? null : ['slug' => $document->status->value, 'label' => $document->status->label($language)],
                'skills' => array_map(
                    static fn (DocumentSkill $skill): array => [
                        'slug' => $skill->slug,
                        'code' => $skill->code,
                        'label' => $skill->label($language),
                        'official' => $skill->isOfficial($language),
                    ],
                    $document->skills,
                ),
                'is_fil_rouge' => $document->isFilRouge,
                'role' => self::textOrNull($document->role),
                'period_start' => $document->periodStart?->format(self::DAY_FORMAT),
                'period_end' => $document->periodEnd?->format(self::DAY_FORMAT),
                'repo_url' => self::textOrNull($document->repoUrl),
                'demo_url' => self::textOrNull($document->demoUrl),
                'client' => self::textOrNull($document->client),
            ];
        }

        $data['markdown'] = $document->markdown;

        return $data;
    }

    /**
     * The sitemap: one list per collection, every collection present even
     * when empty, each line `{slug, updated, lang, translation_key}`. A
     * slug is unique within its collection only, which is why the lists
     * are kept apart; the language and the key let a consumer tell the
     * versions of one document apart and drop them together (ADR-021).
     *
     * @param list<SitemapEntry> $entries
     *
     * @return array<string, list<array{slug: string, updated: string, lang: string, translation_key: string}>>
     */
    public function presentSitemap(array $entries): array
    {
        $data = [];
        foreach (ContentType::cases() as $type) {
            $data[$type->collection()] = [];
        }

        foreach ($entries as $entry) {
            $data[$entry->type->collection()][] = [
                'slug' => $entry->slug,
                'updated' => self::instant($entry->updated),
                'lang' => $entry->lang->value,
                'translation_key' => $entry->translationKey,
            ];
        }

        return $data;
    }

    /**
     * The slugs of the group in the order of the languages of the site, the
     * document's own always among them: a non-empty map, hence a JSON object
     * (`{"fr": "momocad"}`), never an empty list.
     *
     * @return non-empty-array<string, string>
     */
    private static function translations(PublishedDocument $document): array
    {
        $slugs = $document->translations + [$document->lang->value => $document->slug];

        $ordered = [];
        foreach (Language::cases() as $language) {
            if (isset($slugs[$language->value])) {
                $ordered[$language->value] = $slugs[$language->value];
            }
        }

        return $ordered;
    }

    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT_FORMAT);
    }

    private static function textOrNull(string $text): ?string
    {
        return $text === '' ? null : $text;
    }
}

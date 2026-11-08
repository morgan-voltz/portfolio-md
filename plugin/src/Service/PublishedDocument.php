<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use DateTimeImmutable;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\ProjectStatus;

/**
 * A published document as the catalog reads it back from the store: the
 * verbatim Markdown with the metadata WordPress distributed from its
 * frontmatter (01-plugin-php.md §4.4), plus what only the store knows (post
 * id, permalink, dates). The REST presenter turns it into the JSON of §7.3.
 *
 * Distinct from Domain\Frontmatter, which is what the author wrote and is
 * built from YAML, and from StoredContent, which is what the import needs to
 * apply the conflict rule. This object looks outward.
 *
 * Only the common fields are set for a page; `tags`, `subtitle`,
 * `readingTime` and `featured` are set for articles and projects; the nine
 * from `status` to `client` are set for projects. The dates are instants in
 * UTC: the publication date is the frontmatter's calendar day at midnight in
 * the site's timezone, as the import wrote it, and the modification date is
 * WordPress's own.
 *
 * Every document says its language and the key of its translation group,
 * the two metas the plugin stores (ADR-020): a post without them is not in
 * the catalog. The last three fields are what the reader asked and got
 * (ADR-021): the slugs of every published member of the group, whether the
 * document is served in place of a missing version in the requested
 * language, and that language. The catalog sets them with withTranslation().
 */
final readonly class PublishedDocument
{
    /**
     * @param string                $translationKey the slug of the French document of the group (ADR-020)
     * @param list<DocumentTag>     $tags
     * @param list<DocumentSkill>   $skills         competencies the project demonstrates, in code order
     * @param array<string, string> $translations   language value => slug of every published member of
     *                                              the group, this document included, in the order fr, de, en
     */
    public function __construct(
        public int $id,
        public ContentType $type,
        public string $slug,
        public string $title,
        public string $link,
        public DateTimeImmutable $date,
        public DateTimeImmutable $updated,
        public string $markdown,
        public Language $lang,
        public string $translationKey,
        public string $seoDescription = '',
        public array $tags = [],
        public string $subtitle = '',
        public int $readingTime = 0,
        public bool $featured = false,
        public ?ProjectStatus $status = null,
        public array $skills = [],
        public bool $isFilRouge = false,
        public string $role = '',
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public string $repoUrl = '',
        public string $demoUrl = '',
        public string $client = '',
        public array $translations = [],
        public bool $isFallback = false,
        public Language $requestedLanguage = Language::Fr,
    ) {
    }

    /**
     * The same document as a reader of $requested gets it (ADR-021): with
     * the slugs of every published member of its group, and flagged when it
     * is served in place of a version missing in that language.
     *
     * @param array<string, string> $translations language value => slug, this document's own included
     */
    public function withTranslation(array $translations, bool $isFallback, Language $requested): self
    {
        return new self(...[
            ...get_object_vars($this),
            'translations' => $translations,
            'isFallback' => $isFallback,
            'requestedLanguage' => $requested,
        ]);
    }
}

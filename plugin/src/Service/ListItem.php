<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * One card of a list the theme renders (ADR-022, point 6): what the served
 * document shows on a list, without HTML and without the Markdown. Read-only;
 * the theme escapes what it prints.
 *
 * The permalink is the one of the document actually served, so a French card
 * under /de/projets/ links to /fr/projets/<slug>/, and the two languages say
 * why: `lang` is the language of that document, `requestedLanguage` the one
 * the reader asked, and `isFallback` that they differ because the group has
 * no member in the requested language (the badge of ADR-022, point 8).
 */
final readonly class ListItem
{
    public function __construct(
        public int $postId,
        public string $title,
        public string $excerpt,
        public string $permalink,
        public Language $lang,
        public Language $requestedLanguage,
        public bool $isFallback,
    ) {
    }

    /**
     * The excerpt is the subtitle of the document, the one-line deck its
     * author wrote for the cards, else its SEO description, else nothing.
     */
    public static function fromDocument(PublishedDocument $document): self
    {
        return new self(
            $document->id,
            $document->title,
            $document->subtitle !== '' ? $document->subtitle : $document->seoDescription,
            $document->link,
            $document->lang,
            $document->requestedLanguage,
            $document->isFallback,
        );
    }
}

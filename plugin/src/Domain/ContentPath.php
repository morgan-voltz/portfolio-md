<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Where a document lives in the content repository once it is laid out by
 * language (ADR-020): `<lang>/<folder>/<slug>.md`, relative to content/,
 * for instance `fr/projects/momocad.md` or `de/pages/lebenslauf.md`.
 *
 * The language folder says the language, the type folder says the kind
 * (ContentType::fromFolder()), the file name says the slug. Anything else,
 * the former two-level layout included, is not a document path.
 *
 * Lot 1a of ADR-020: the content validator reads it to recognise a document
 * of the new layout. Lot 1b plugs it into the Git door
 * (Service\ContentSyncService) and the WP-CLI import (Cli\ImportCommand),
 * which still expect the two-level layout.
 */
final readonly class ContentPath
{
    public function __construct(
        public Language $lang,
        public ContentType $type,
        public string $slug,
    ) {
    }

    /**
     * @param string $relative path relative to content/, with "/" separators
     */
    public static function fromRelative(string $relative): ?self
    {
        $segments = explode('/', $relative);
        if (count($segments) !== 3) {
            return null;
        }
        [$languageFolder, $typeFolder, $file] = $segments;

        $lang = Language::tryFrom($languageFolder);
        $type = ContentType::fromFolder($typeFolder);
        if ($lang === null || $type === null || !str_ends_with($file, '.md')) {
            return null;
        }

        $slug = substr($file, 0, -3);
        if (preg_match(Patterns::SLUG, $slug) !== 1 || strlen($slug) > Patterns::SLUG_MAX_LENGTH) {
            return null;
        }

        return new self($lang, $type, $slug);
    }

    /** `fr/projects/momocad.md` */
    public function toRelative(): string
    {
        return "{$this->lang->value}/{$this->type->folder()}/{$this->slug}.md";
    }
}

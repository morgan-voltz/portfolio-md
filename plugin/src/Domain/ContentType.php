<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Kind of content a Markdown document describes, from its `type` frontmatter key.
 *
 * `article` and `project` map to the plugin's custom post types, `page` to
 * the native WordPress page (About, CV, Contact). Each kind has a folder in
 * the content repository (content/README.md) and a place in the site's
 * URL tree (04-structure-wordpress.md §2), which is how a link from one
 * document to another is rewritten into a link of the site. Pure domain code.
 */
enum ContentType: string
{
    case Article = 'article';
    case Project = 'project';
    case Page = 'page';

    /** The folder of the content repository that holds documents of this kind. */
    public function folder(): string
    {
        return match ($this) {
            self::Article => 'articles',
            self::Project => 'projects',
            self::Page => 'pages',
        };
    }

    /** The kind whose folder this is, or null. */
    public static function fromFolder(string $folder): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->folder() === $folder) {
                return $type;
            }
        }

        return null;
    }

    /** The first segment of the site's URLs for this kind; empty for pages, which sit at the root. */
    public function urlBase(): string
    {
        return match ($this) {
            self::Article => 'articles',
            self::Project => 'projets',
            self::Page => '',
        };
    }

    /** The path of a document of this kind on the site: /projets/<slug>/, /articles/<slug>/, /<slug>/. */
    public function path(string $slug): string
    {
        $base = $this->urlBase();

        return '/' . ($base === '' ? '' : $base . '/') . $slug . '/';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

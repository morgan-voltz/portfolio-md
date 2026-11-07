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
 * document to another is rewritten into a link of the site, and a collection
 * in the plugin's REST API (01-plugin-php.md §7). Pure domain code.
 */
enum ContentType: string
{
    case Article = 'article';
    case Project = 'project';
    case Page = 'page';

    /**
     * The folder of the content repository that holds documents of this kind,
     * under a language folder once the content is laid out by language
     * (content/<lang>/<folder>/, ADR-020; see ContentPath).
     */
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

    /**
     * The collection of this kind in the plugin's REST API (01-plugin-php.md
     * §7): /articles, /projects, /pages under the namespace. English, like
     * the folders of the content repository, where the site's URL tree is
     * French.
     */
    public function collection(): string
    {
        return match ($this) {
            self::Article => 'articles',
            self::Project => 'projects',
            self::Page => 'pages',
        };
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

    /**
     * The path of a document of this kind on the site. With a language, the
     * site's URL tree of ADR-020: /fr/projets/<slug>/, /de/articles/<slug>/,
     * /en/<slug>/. Without, the shape the site had before languages:
     * /projets/<slug>/, /articles/<slug>/, /<slug>/; the link rewriter still
     * calls it that way until lot 1b gives it the language of the document.
     */
    public function path(string $slug, ?Language $lang = null): string
    {
        $base = $this->urlBase();
        $prefix = $lang === null ? '/' : '/' . $lang->value . '/';

        return $prefix . ($base === '' ? '' : $base . '/') . $slug . '/';
    }

    /**
     * Whether documents of this kind say their language in their slug
     * (ADR-020): an article or a project in German ends with "-de", in
     * English with "-en", and a French one with neither, because WordPress
     * wants one slug per post type. A page has a natural slug in each
     * language (cv, lebenslauf, resume) instead.
     */
    public function suffixesSlugs(): bool
    {
        return $this !== self::Page;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

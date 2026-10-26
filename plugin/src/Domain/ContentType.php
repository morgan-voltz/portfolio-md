<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Kind of content a Markdown document describes, from its `type` frontmatter key.
 *
 * `article` and `project` map to the plugin's custom post types, `page` to
 * the native WordPress page (About, CV, Contact). Pure domain code.
 */
enum ContentType: string
{
    case Article = 'article';
    case Project = 'project';
    case Page = 'page';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

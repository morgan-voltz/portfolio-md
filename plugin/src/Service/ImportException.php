<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentType;

/**
 * A document that is well formed but cannot be imported as it stands.
 */
final class ImportException extends RuntimeException
{
    public static function unsupportedType(ContentType $type): self
    {
        return new self("Documents of type \"{$type->value}\" cannot be imported yet: only articles and projects can.");
    }

    public static function slugMismatch(string $slug, string $fileName): self
    {
        return new self(
            "The slug \"{$slug}\" does not match the file name \"{$fileName}\": a file is named after its slug (content/README.md)."
            . ' A sheet copied from another one still carries the slug of its model and would overwrite it.',
        );
    }

    public static function inTrash(ContentType $type, string $slug, int $postId): self
    {
        return new self(
            "Post {$postId} ({$type->value}/{$slug}) is in the trash: restore it or delete it permanently before importing this document,"
            . ' otherwise a second post would be created next to it.',
        );
    }

    public static function unknownPost(ContentType $type, int $postId): self
    {
        return new self(
            "Post {$postId} is not an existing {$type->value}: a document of type \"{$type->value}\" can only be saved into a post of that type"
            . ' (check the "type" line of its frontmatter).',
        );
    }

    public static function slugTaken(ContentType $type, string $slug, int $ownerId, int $postId): self
    {
        return new self(
            "The slug \"{$slug}\" already belongs to post {$ownerId} ({$type->value}/{$slug}): post {$postId} cannot take it."
            . " Edit post {$ownerId} instead, or give this document another slug.",
        );
    }

    public static function slugChanged(ContentType $type, int $postId, string $slug): self
    {
        return new self(
            "Post {$postId} ({$type->value}) already holds a document under another slug and keeps it: renaming it to \"{$slug}\" from here"
            . ' would orphan its Markdown file, whose next import would create a second post.'
            . ' Put the former slug back, or rename the file in the repository and import it.',
        );
    }

    /**
     * @param list<string> $codes
     */
    public static function unknownSkills(array $codes): self
    {
        return new self(
            'Unknown competency code(s): ' . implode(', ', $codes)
            . '. Add them to content/skills.yaml and run `wp portfolio skills sync` first.',
        );
    }
}

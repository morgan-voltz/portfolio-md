<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentType;

/**
 * A document that is well formed but cannot be imported as it stands.
 *
 * A refusal that names a post other than the one being written carries its
 * id in $otherPostId, so that a door can decide whether its user may be
 * told about that post at all.
 */
final class ImportException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $otherPostId = null)
    {
        parent::__construct($message);
    }

    public static function slugMismatch(string $slug, string $fileName): self
    {
        return new self(
            "The slug \"{$slug}\" does not match the file name \"{$fileName}\": a file is named after its slug (content/README.md)."
            . ' A sheet copied from another one still carries the slug of its model and would overwrite it.',
        );
    }

    public static function typeMismatch(ContentType $type, ContentType $expected): self
    {
        return new self(
            "The document is of type \"{$type->value}\" but its file is in the \"{$expected->folder()}/\" folder, which holds documents of type \"{$expected->value}\" (content/README.md):"
            . ' move the file to the folder of its kind, or fix its "type" line.',
        );
    }

    public static function inTrash(ContentType $type, string $slug, int $postId): self
    {
        return new self(
            "Post {$postId} ({$type->value}/{$slug}) is in the trash: restore it or delete it permanently before importing this document,"
            . ' otherwise a second post would be created next to it.',
            $postId,
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
            $ownerId,
        );
    }

    public static function slugChanged(ContentType $type, int $postId, ?string $formerSlug, string $slug): self
    {
        $former = $formerSlug === null ? 'a document whose slug cannot be read' : "a document whose slug is \"{$formerSlug}\"";

        return new self(
            "Post {$postId} ({$type->value}) holds {$former} and keeps it: renaming it to \"{$slug}\" from here"
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

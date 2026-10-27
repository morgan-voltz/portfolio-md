<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;

/**
 * What the site would show for a document as it is being typed
 * (01-plugin-php.md §6.1): the same parser and the same renderer as the
 * import, without the store.
 *
 * The preview reports what the import would refuse (a document that does
 * not parse, a page, a competency code missing from the grid) but still
 * renders whatever can be rendered, so that the author sees the text and
 * the problem at once. What it cannot know is the state of the posts: a
 * slug taken by another post, or a conflict with Git, only shows up at the
 * save, which keeps the text and says why (Admin\EditorMetaBox).
 */
final class PreviewService
{
    public function __construct(
        private readonly FrontmatterParser $frontmatterParser,
        private readonly ContentRenderer $renderer,
        private readonly SkillTermGateway $skills,
    ) {
    }

    public function preview(string $markdown): PreviewResult
    {
        try {
            $document = $this->frontmatterParser->parse($markdown);
        } catch (MalformedDocumentException | InvalidFrontmatterException $failure) {
            return PreviewResult::refused($failure->getMessage());
        }

        $frontmatter = $document->frontmatter;
        $problems = [];
        if ($frontmatter->type === ContentType::Page) {
            $problems[] = ImportException::unsupportedType($frontmatter->type)->getMessage();
        }
        $unknown = array_values(array_filter(
            $frontmatter->skills,
            fn (string $code): bool => $this->skills->findByCode($code) === null,
        ));
        if ($unknown !== []) {
            $problems[] = ImportException::unknownSkills($unknown)->getMessage();
        }

        $html = null;
        try {
            $html = $this->renderer->render($document->body);
        } catch (RuntimeException $failure) {
            $problems[] = 'The body could not be rendered: ' . $failure->getMessage();
        }

        return new PreviewResult($frontmatter->title, $html, self::summarize($frontmatter), $problems);
    }

    /**
     * The frontmatter lines worth a glance next to the rendered body, as
     * plain strings keyed by a fixed name; the labels belong to the client.
     *
     * @return array<string, string>
     */
    private static function summarize(Frontmatter $frontmatter): array
    {
        $summary = [
            'type' => $frontmatter->type->value,
            'slug' => $frontmatter->slug,
            'date' => $frontmatter->date?->format('Y-m-d') ?? '',
        ];
        if ($frontmatter->status !== null) {
            $summary['status'] = $frontmatter->status->label();
        }
        $summary['skills'] = implode(', ', $frontmatter->skills);
        $summary['tags'] = implode(', ', $frontmatter->tags);

        return $summary;
    }
}

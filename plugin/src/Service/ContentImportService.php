<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;

/**
 * The use case every door of the plugin converges to (01-plugin-php.md §6):
 * one Markdown document in, one WordPress post out.
 *
 * Steps: parse the frontmatter (phase one), resolve the competency codes to
 * `skill` terms, look the slug up in the store, apply the source_of_truth
 * rule (ADR-005), render the body (phases two and three) and hand the store
 * a complete record (phases four and five). The Markdown is stored verbatim
 * with its SHA-256, which is what makes a second import of the same file a
 * no-op.
 *
 * Competency codes are not created on the fly: the grid in content/skills.yaml
 * is the source of the official names, so an unknown code is a mistake in the
 * document and the import says so (ADR-014, implementation note).
 *
 * Pages are not imported yet: the metas that make an import idempotent are
 * only registered on articles and projects.
 */
final class ContentImportService
{
    public function __construct(
        private readonly FrontmatterParser $frontmatterParser,
        private readonly ContentRenderer $renderer,
        private readonly ContentRepository $repository,
        private readonly SkillTermGateway $skills,
        private readonly ConflictResolver $conflicts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws MalformedDocumentException  when the document has no usable frontmatter
     * @throws InvalidFrontmatterException when the metadata break the contract
     * @throws ImportException             when the document cannot be imported as it stands
     * @throws \RuntimeException           when the store refuses the write
     */
    public function import(string $markdown, ImportOptions $options): ImportResult
    {
        $document = $this->frontmatterParser->parse($markdown);
        $frontmatter = $document->frontmatter;

        if ($frontmatter->type === ContentType::Page) {
            throw ImportException::unsupportedType($frontmatter->type);
        }

        $skillTermIds = $this->resolveSkills($frontmatter->skills);
        $hash = hash('sha256', $markdown);
        $existing = $this->repository->findBySlug($frontmatter->type, $frontmatter->slug);
        $outcome = $this->conflicts->decide($existing, $hash, ContentRenderer::VERSION, $options->source, $options->force);

        if ($outcome === ImportOutcome::Conflict) {
            return new ImportResult(
                $outcome,
                $frontmatter->type,
                $frontmatter->slug,
                $frontmatter->title,
                $existing?->id,
                'last written from ' . ($existing?->source?->value ?? 'outside the pipeline') . ' and changed since; use --force to overwrite',
            );
        }

        if ($outcome === ImportOutcome::Unchanged) {
            return new ImportResult($outcome, $frontmatter->type, $frontmatter->slug, $frontmatter->title, $existing?->id);
        }

        // Rendered even in a dry run: a document that cannot be rendered must be reported.
        $html = $this->renderer->render($document->body);

        if ($options->dryRun) {
            return new ImportResult($outcome, $frontmatter->type, $frontmatter->slug, $frontmatter->title, $existing?->id);
        }

        $postId = $this->repository->save(new ContentRecord(
            id: $existing?->id,
            frontmatter: $frontmatter,
            html: $html,
            markdown: $markdown,
            hash: $hash,
            source: $options->source,
            syncedAt: $this->clock->now(),
            renderVersion: ContentRenderer::VERSION,
            skillTermIds: $skillTermIds,
            postStatus: $options->postStatus,
        ));

        return new ImportResult($outcome, $frontmatter->type, $frontmatter->slug, $frontmatter->title, $postId);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<int>
     */
    private function resolveSkills(array $codes): array
    {
        $ids = [];
        $unknown = [];
        foreach ($codes as $code) {
            $term = $this->skills->findByCode($code);
            if ($term === null) {
                $unknown[] = $code;
                continue;
            }
            $ids[] = $term->id;
        }

        if ($unknown !== []) {
            throw ImportException::unknownSkills($unknown);
        }

        return $ids;
    }
}

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
 * Steps: parse the frontmatter (phase one), look the slug up in the store,
 * apply the source_of_truth rule (ADR-005), and only when a write is due:
 * resolve the competency codes to `skill` terms, render the body (phases
 * two and three) and hand the store a complete record (phases four and
 * five). The Markdown is stored verbatim with its SHA-256, which is what
 * makes a second import of the same file a no-op.
 *
 * Three refusals protect the posts that already exist. A document whose
 * slug differs from the name of its file is refused when the caller knows
 * that name: a sheet copied from another one keeps the slug of its model
 * and would overwrite it (content/README.md names every file after its
 * slug). A slug whose post sits in the trash is refused: the post was
 * retired by hand, and WordPress would let a second one be created next to
 * it. Competency codes are not created on the fly: the grid in
 * content/skills.yaml is the source of the official names, so an unknown
 * code is a mistake in the document and the import says so (ADR-014,
 * implementation note); that check runs only when something is about to be
 * written, so a grid that changed never fails a document that did not.
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
     * @param string|null $expectedSlug name of the file the document comes from, without its
     *                                  extension, when there is one: the slug has to match it
     *
     * @throws MalformedDocumentException  when the document has no usable frontmatter or is not UTF-8
     * @throws InvalidFrontmatterException when the metadata break the contract
     * @throws ImportException             when the document cannot be imported as it stands
     * @throws \RuntimeException           when the store refuses the write
     */
    public function import(string $markdown, ImportOptions $options, ?string $expectedSlug = null): ImportResult
    {
        $document = $this->frontmatterParser->parse($markdown);
        $frontmatter = $document->frontmatter;

        if ($frontmatter->type === ContentType::Page) {
            throw ImportException::unsupportedType($frontmatter->type);
        }
        if ($expectedSlug !== null && $frontmatter->slug !== $expectedSlug) {
            throw ImportException::slugMismatch($frontmatter->slug, $expectedSlug);
        }

        $hash = hash('sha256', $markdown);
        $existing = $this->repository->findBySlug($frontmatter->type, $frontmatter->slug);
        if ($existing !== null && $existing->inTrash) {
            throw ImportException::inTrash($frontmatter->type, $frontmatter->slug, $existing->id);
        }

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

        // Resolved only now: a code that left the grid must not fail a document that did not change.
        $skillTermIds = $this->resolveSkills($frontmatter->skills);

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

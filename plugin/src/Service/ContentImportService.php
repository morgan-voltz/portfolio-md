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
 * Steps: parse the frontmatter (phase one), find the post the document
 * belongs to, apply the source_of_truth rule (ADR-005), and only when a
 * write is due: resolve the competency codes to `skill` terms, render the
 * body (phases two and three) and hand the store a complete record (phases
 * four and five). The Markdown is stored verbatim with its SHA-256, which
 * is what makes a second import of the same file a no-op.
 *
 * The post is found in one of two ways. Doors that handle files or pasted
 * text (WP-CLI, the paste page, later the webhook) know nothing but the
 * document: the post is the one that carries its slug. The editor of a
 * post knows which post it is editing and passes its id ($targetPostId):
 * the document is then written into that post, which may hold nothing yet
 * (a post just created in the admin) or an earlier version of the document.
 *
 * Refusals protect the posts that already exist:
 *   - a document whose slug differs from the name of its file is refused
 *     when the caller knows that name: a sheet copied from another one
 *     keeps the slug of its model and would overwrite it (content/README.md
 *     names every file after its slug);
 *   - a slug whose post sits in the trash is refused: the post was retired
 *     by hand, and WordPress would let a second one be created next to it;
 *   - with a target post, the slug must be free or already the target's
 *     own, and a post that holds a document keeps its slug: renaming it
 *     from the admin would orphan its Markdown file, whose next import
 *     would create a second post;
 *   - competency codes are not created on the fly: the grid in
 *     content/skills.yaml is the source of the official names, so an unknown
 *     code is a mistake in the document and the import says so (ADR-014,
 *     implementation note); that check runs only when something is about
 *     to be written, so a grid that changed never fails a document that
 *     did not.
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
     * @param int|null    $targetPostId the post the document is written into, when the caller
     *                                  edits one; the post is found by slug otherwise
     *
     * @throws MalformedDocumentException  when the document has no usable frontmatter or is not UTF-8
     * @throws InvalidFrontmatterException when the metadata break the contract
     * @throws ImportException             when the document cannot be imported as it stands
     * @throws \RuntimeException           when the store refuses the write
     */
    public function import(string $markdown, ImportOptions $options, ?string $expectedSlug = null, ?int $targetPostId = null): ImportResult
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
        $existing = $targetPostId === null
            ? $this->findBySlug($frontmatter->type, $frontmatter->slug)
            : $this->findTarget($frontmatter->type, $frontmatter->slug, $targetPostId);
        $postId = $existing?->id ?? $targetPostId;

        $outcome = $this->conflicts->decide($existing, $hash, ContentRenderer::VERSION, $options->source, $options->force);

        if ($outcome === ImportOutcome::Conflict) {
            return new ImportResult(
                $outcome,
                $frontmatter->type,
                $frontmatter->slug,
                $frontmatter->title,
                $postId,
                'last written from ' . ($existing?->source?->value ?? 'outside the pipeline') . ' and changed since; use --force to overwrite',
            );
        }

        if ($outcome === ImportOutcome::Unchanged) {
            return new ImportResult($outcome, $frontmatter->type, $frontmatter->slug, $frontmatter->title, $postId);
        }

        // Resolved only now: a code that left the grid must not fail a document that did not change.
        $skillTermIds = $this->resolveSkills($frontmatter->skills);

        // Rendered even in a dry run: a document that cannot be rendered must be reported.
        $html = $this->renderer->render($document->body);

        if ($options->dryRun) {
            return new ImportResult($outcome, $frontmatter->type, $frontmatter->slug, $frontmatter->title, $postId);
        }

        $savedId = $this->repository->save(new ContentRecord(
            id: $postId,
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

        return new ImportResult($outcome, $frontmatter->type, $frontmatter->slug, $frontmatter->title, $savedId);
    }

    /**
     * The post that carries this slug, or null when none does.
     */
    private function findBySlug(ContentType $type, string $slug): ?StoredContent
    {
        $existing = $this->repository->findBySlug($type, $slug);
        if ($existing !== null && $existing->inTrash) {
            throw ImportException::inTrash($type, $slug, $existing->id);
        }

        return $existing;
    }

    /**
     * The post the document is written into, or null when that post holds
     * no document yet: it was just created in the admin and the document
     * is about to be created into it.
     */
    private function findTarget(ContentType $type, string $slug, int $postId): ?StoredContent
    {
        $target = $this->repository->findById($type, $postId) ?? throw ImportException::unknownPost($type, $postId);
        if ($target->inTrash) {
            throw ImportException::inTrash($type, $slug, $postId);
        }

        $owner = $this->findBySlug($type, $slug);
        if ($owner !== null && $owner->id !== $postId) {
            throw ImportException::slugTaken($type, $slug, $owner->id, $postId);
        }
        if ($owner === null && $target->holdsDocument()) {
            throw ImportException::slugChanged($type, $postId, $slug);
        }

        return $target->holdsDocument() ? $target : null;
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

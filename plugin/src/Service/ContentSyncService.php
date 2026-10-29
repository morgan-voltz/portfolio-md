<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Domain\InvalidSkillsGridException;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;

/**
 * The Git door (01-plugin-php.md §6.2): a batch of files of the content
 * repository, as a push changed them, brought into WordPress.
 *
 * Every file is handled on its own and gets its line: a failure never stops
 * the others, like the WP-CLI command. The grid, `skills.yaml`, goes first
 * when the batch holds it, because a sheet of the same push may cite a
 * code the grid introduces. A Markdown document in one of the three
 * folders goes through the import use case as a Git write, never forced:
 * the conflict rule of ADR-005 stands, and a human resolves a conflict
 * from the admin. A deleted document retires its post only when Git wrote
 * that post last: Git takes back what Git wrote, and leaves to a human
 * what a human wrote (the post is moved to the trash, where the admin can
 * restore it). Anything else in the batch (the README, an image, a file in
 * an unknown folder) is not a document and is skipped, not refused.
 *
 * Paths are relative to `content/` (`projects/<slug>.md`, `skills.yaml`);
 * a leading `content/` is tolerated, since a diff taken at the root of the
 * repository prints it.
 */
final class ContentSyncService
{
    public const SKILLS_GRID = 'skills.yaml';

    public function __construct(
        private readonly ContentImportService $import,
        private readonly ContentRepository $repository,
        private readonly SkillTermSynchronizer $skills,
        private readonly SkillsGridLoader $grid,
    ) {
    }

    /**
     * @param list<SyncFile> $files
     */
    public function synchronize(array $files, bool $dryRun = false): ContentSyncReport
    {
        $grids = [];
        $documents = [];
        foreach ($files as $file) {
            if (self::isGrid($file->path)) {
                $grids[] = $file;
            } else {
                $documents[] = $file;
            }
        }

        $lines = [];
        foreach ([...$grids, ...$documents] as $file) {
            $lines[] = self::isGrid($file->path) ? $this->grid($file, $dryRun) : $this->document($file, $dryRun);
        }

        return new ContentSyncReport($lines, $dryRun);
    }

    private static function isGrid(string $path): bool
    {
        return self::segments($path) === [self::SKILLS_GRID];
    }

    private function grid(SyncFile $file, bool $dryRun): ContentSyncLine
    {
        if ($file->deleted) {
            return ContentSyncLine::skipped($file->path, 'the skills grid is never removed by a synchronization: the taxonomy keeps its terms');
        }
        if ($file->content === null) {
            return ContentSyncLine::failed($file->path, 'no content was given for a file that is not deleted');
        }

        try {
            $report = $this->skills->synchronize($this->grid->fromYaml($file->content), $dryRun);
        } catch (InvalidSkillsGridException | RuntimeException $exception) {
            return ContentSyncLine::failed($file->path, $exception->getMessage());
        }

        $status = ContentSyncStatus::Unchanged;
        if ($report->hasFailures()) {
            $status = ContentSyncStatus::Failed;
        } elseif ($report->createdCount() + $report->updatedCount() + $report->recodedCount() > 0) {
            $status = ContentSyncStatus::Updated;
        }

        return new ContentSyncLine($file->path, $status, message: $report->summary());
    }

    private function document(SyncFile $file, bool $dryRun): ContentSyncLine
    {
        $segments = self::segments($file->path);
        if ($segments === null) {
            return ContentSyncLine::skipped($file->path, 'the path leaves the content repository');
        }
        if (count($segments) !== 2) {
            return ContentSyncLine::skipped($file->path, count($segments) < 2
                ? 'a file at the root of content/ is not a document'
                : 'a file nested below a document folder is not a document (an image, for instance)');
        }

        [$folder, $name] = $segments;
        $type = ContentType::fromFolder($folder);
        if ($type === null) {
            return ContentSyncLine::skipped($file->path, "\"{$folder}/\" is not a folder of documents (articles, projects, pages)");
        }
        if (!str_ends_with($name, '.md')) {
            return ContentSyncLine::skipped($file->path, 'not a Markdown document', $type);
        }

        $slug = substr($name, 0, -3);
        if (preg_match(Patterns::SLUG, $slug) !== 1) {
            return ContentSyncLine::failed($file->path, "\"{$slug}\" is not a slug: a document is named after its slug (content/README.md)", $type);
        }

        if ($file->deleted) {
            return $this->retire($file->path, $type, $slug, $dryRun);
        }
        if ($file->content === null) {
            return ContentSyncLine::failed($file->path, 'no content was given for a file that is not deleted', $type, $slug);
        }

        try {
            $result = $this->import->import($file->content, new ImportOptions(source: ContentSource::Git, dryRun: $dryRun), $slug, null, $type);
        } catch (MalformedDocumentException | InvalidFrontmatterException | ImportException | RuntimeException $exception) {
            return ContentSyncLine::failed($file->path, $exception->getMessage(), $type, $slug);
        }

        return new ContentSyncLine($file->path, ContentSyncStatus::fromOutcome($result->outcome), $result->type, $result->slug, $result->postId, $result->detail);
    }

    /**
     * Git takes back what Git wrote: the post of a deleted document goes to
     * the trash when its last write came from Git; a post written from the
     * admin or a paste, or made by hand, is a human's and is left alone,
     * which the line says. Idempotent: a post already in the trash is
     * unchanged, and a slug without post has nothing to retire.
     */
    private function retire(string $path, ContentType $type, string $slug, bool $dryRun): ContentSyncLine
    {
        $existing = $this->repository->findBySlug($type, $slug);
        if ($existing === null) {
            return ContentSyncLine::skipped($path, 'no post carries this slug: nothing to retire', $type, $slug);
        }
        if ($existing->inTrash) {
            return new ContentSyncLine($path, ContentSyncStatus::Unchanged, $type, $slug, $existing->id, 'already in the trash');
        }
        if (!$existing->holdsDocument() || $existing->source !== ContentSource::Git) {
            $origin = $existing->holdsDocument() ? 'from ' . ($existing->source?->value ?? 'an unknown source') : 'outside the pipeline';

            return new ContentSyncLine(
                $path,
                ContentSyncStatus::Conflict,
                $type,
                $slug,
                $existing->id,
                "post {$existing->id} was last written {$origin}: a synchronization only retires what Git wrote; remove it from the admin if it has to go",
            );
        }

        if (!$dryRun) {
            try {
                $this->repository->trash($existing->id);
            } catch (RuntimeException $exception) {
                return ContentSyncLine::failed($path, $exception->getMessage(), $type, $slug);
            }
        }

        return new ContentSyncLine($path, ContentSyncStatus::Deleted, $type, $slug, $existing->id, $dryRun ? 'would be moved to the trash' : 'moved to the trash');
    }

    /**
     * The segments of a path relative to content/, or null when the path
     * tries to leave it. `./` and a leading `content/` are dropped.
     *
     * @return list<string>|null
     */
    private static function segments(string $path): ?array
    {
        $path = trim($path);
        $path = preg_replace('#^(\./)+#', '', $path) ?? $path;
        $path = preg_replace('#^content/#', '', $path) ?? $path;

        if ($path === '' || str_starts_with($path, '/')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        return $segments;
    }
}

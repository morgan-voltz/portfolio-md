<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Cli;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportException;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ImportResult;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use WP_CLI;

/**
 * Imports Markdown documents into WordPress from the command line.
 *
 * Registered by the composition root as `wp portfolio import`. Like the
 * other commands it only translates arguments, calls the service and prints
 * the results; the import logic lives in Service\ContentImportService.
 */
final class ImportCommand
{
    public function __construct(private readonly ContentImportService $service)
    {
    }

    /**
     * Imports Markdown articles, projects and pages (content/README.md) as posts.
     *
     * Each document is parsed, rendered to Gutenberg blocks and stored with
     * its verbatim Markdown. A document whose Markdown did not change since
     * the last import is left alone. Content last written from the admin or
     * a paste is not overwritten by a Git import unless --force is given
     * (ADR-005). A file must be named after its slug (<slug>.md), so that a
     * sheet copied from another one never overwrites its model; a slug whose
     * post is in the trash is refused until the post is restored or deleted.
     * Documents are processed one by one: a failure is reported and the
     * others go on; the command exits with an error at the end if anything
     * failed or conflicted.
     *
     * ## OPTIONS
     *
     * <path>...
     * : Markdown files, or directories whose *.md files are imported in name
     * order. Each file is named after its slug. Usually under
     * /srv/portfolio-content, where content/ is mounted.
     *
     * [--source=<source>]
     * : Origin recorded on the content: git, paste or admin. Drives the
     * conflict rule of ADR-005.
     * ---
     * default: git
     * options:
     *   - git
     *   - paste
     *   - admin
     * ---
     *
     * [--force]
     * : Overwrite content whose last write came from another source.
     *
     * [--dry-run]
     * : Parse and render every document, write nothing, report what would happen.
     *
     * [--draft]
     * : Create new posts as drafts instead of publishing them. Existing posts keep their status.
     *
     * ## EXAMPLES
     *
     *     wp portfolio import /srv/portfolio-content/projects/momocad.md --user=morgan
     *     wp portfolio import /srv/portfolio-content/projects --dry-run --user=morgan
     *     wp portfolio import /srv/portfolio-content/pages --user=morgan
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (get_current_user_id() === 0) {
            WP_CLI::error('Run this command with --user=<login> so that the posts get an author.');
        }

        $source = ContentSource::tryFrom((string) ($assocArgs['source'] ?? ContentSource::Git->value));
        if ($source === null) {
            WP_CLI::error('Unknown --source; expected one of ' . implode(', ', ContentSource::values()) . '.');
        }

        $options = new ImportOptions(
            source: $source,
            force: isset($assocArgs['force']),
            dryRun: isset($assocArgs['dry-run']),
            postStatus: isset($assocArgs['draft']) ? 'draft' : 'publish',
        );

        $files = self::expandPaths($args);
        $counts = array_fill_keys(array_map(static fn (ImportOutcome $o): string => $o->value, ImportOutcome::cases()), 0);
        $counts['failed'] = 0;
        $prefix = $options->dryRun ? '[dry-run] ' : '';

        foreach ($files as $file) {
            $markdown = file_get_contents($file);
            if ($markdown === false) {
                $counts['failed']++;
                WP_CLI::warning("{$prefix}FAILED     {$file}: cannot be read");
                continue;
            }

            try {
                $result = $this->service->import($markdown, $options, basename($file, '.md'));
            } catch (MalformedDocumentException | InvalidFrontmatterException | ImportException | RuntimeException $exception) {
                $counts['failed']++;
                WP_CLI::warning("{$prefix}FAILED     {$file}: " . $exception->getMessage());
                continue;
            }

            $counts[$result->outcome->value]++;
            $line = $prefix . self::describe($result);
            $result->outcome === ImportOutcome::Conflict ? WP_CLI::warning($line) : WP_CLI::log($line);
        }

        $summary = sprintf(
            '%d document(s): %d created, %d updated, %d unchanged, %d conflict(s), %d failed',
            count($files),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['conflict'],
            $counts['failed'],
        );

        if ($counts['conflict'] > 0 || $counts['failed'] > 0) {
            WP_CLI::error($prefix . $summary);
        }

        WP_CLI::success($prefix . $summary);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function expandPaths(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $found = glob(rtrim($path, '/') . '/*.md');
                if ($found === false || $found === []) {
                    WP_CLI::error("No Markdown file in {$path}.");
                }
                sort($found);
                array_push($files, ...$found);
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                WP_CLI::error("{$path} is neither a file nor a directory.");
            }
        }

        return $files;
    }

    private static function describe(ImportResult $result): string
    {
        $label = str_pad(strtoupper($result->outcome->value), 10);
        $target = "{$result->type->value}/{$result->slug}";
        $post = $result->postId === null ? '' : " (post {$result->postId})";
        $detail = $result->detail === '' ? '' : ": {$result->detail}";

        return "{$label} {$target}{$post}{$detail}";
    }
}

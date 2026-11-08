<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Cli;

use RuntimeException;
use Voltz\PortfolioMd\Domain\InvalidTagsFileException;
use Voltz\PortfolioMd\Parsing\TagsFileLoader;
use Voltz\PortfolioMd\Service\TagTermSynchronizer;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_CLI;

/**
 * `wp portfolio tags sync <tags.yaml> [--dry-run]` (ADR-024): the names of
 * the technology tags, from the file into the tech_tag terms, one line per
 * tag and a summary; a term absent from the file is reported, never deleted.
 */
final class TagsCommand
{
    public function __construct(
        private readonly TagsFileLoader $loader,
        private readonly TagTermSynchronizer $synchronizer,
    ) {
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public function sync(array $args, array $assocArgs): void
    {
        $file = $args[0] ?? '';
        $dryRun = isset($assocArgs['dry-run']);

        if (!taxonomy_exists(TaxonomyRegistrar::TECH_TAG)) {
            WP_CLI::error('The tech_tag taxonomy is not registered: is the portfolio-md plugin active?');
        }

        try {
            $names = $this->loader->load($file);
        } catch (InvalidTagsFileException | RuntimeException $exception) {
            WP_CLI::error($exception->getMessage());
        }

        $report = $this->synchronizer->synchronize($names, $dryRun);

        foreach ($report->lines() as $line) {
            WP_CLI::log($line);
        }

        $summary = sprintf('%d tag(s) in the file; %s', $names->count(), $report->summary());

        if ($report->hasFailures()) {
            WP_CLI::error($summary);
        }

        WP_CLI::success($summary);
    }
}

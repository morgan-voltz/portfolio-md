<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Cli;

use RuntimeException;
use Voltz\PortfolioMd\Domain\InvalidSkillsGridException;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_CLI;

/**
 * Manages the `skill` taxonomy from the command line.
 *
 * Registered by the composition root as `wp portfolio skills` when the
 * plugin runs under WP-CLI. The command is a thin translator (ADR of
 * 01-plugin-php.md §1): it reads arguments, calls the services and prints
 * the report; no synchronization logic lives here.
 */
final class SkillsCommand
{
    public function __construct(
        private readonly SkillsGridLoader $loader,
        private readonly SkillTermSynchronizer $synchronizer,
    ) {
    }

    /**
     * Synchronizes the skill taxonomy with a skills grid file.
     *
     * Referentials become parent terms, competencies child terms carrying
     * their official code in the `_portfolio_skill_code` term meta. Existing
     * terms are updated in place; terms absent from the file are reported,
     * never deleted. A term WordPress refuses is reported as FAILED and the
     * command exits with an error after the full report.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the grid, usually content/skills.yaml mounted in the container.
     *
     * [--dry-run]
     * : Report what would change without writing anything.
     *
     * ## EXAMPLES
     *
     *     wp portfolio skills sync /srv/portfolio-content/skills.yaml --dry-run
     *     wp portfolio skills sync /srv/portfolio-content/skills.yaml
     *
     * @subcommand sync
     *
     * @param list<string>          $args
     * @param array<string, mixed>  $assocArgs
     */
    public function sync(array $args, array $assocArgs): void
    {
        $file = $args[0] ?? '';
        $dryRun = isset($assocArgs['dry-run']);

        if (!taxonomy_exists(TaxonomyRegistrar::SKILL)) {
            WP_CLI::error('The skill taxonomy is not registered: is the portfolio-md plugin active?');
        }

        try {
            $grid = $this->loader->load($file);
        } catch (InvalidSkillsGridException | RuntimeException $exception) {
            WP_CLI::error($exception->getMessage());
        }

        $report = $this->synchronizer->synchronize($grid, $dryRun);

        foreach ($report->lines() as $line) {
            WP_CLI::log($line);
        }

        $summary = sprintf(
            '%d referential(s), %d skill(s) in the grid; %s',
            count($grid->referentials),
            $grid->countSkills(),
            $report->summary(),
        );

        if ($report->hasFailures()) {
            WP_CLI::error($summary);
        }

        WP_CLI::success($summary);
    }
}

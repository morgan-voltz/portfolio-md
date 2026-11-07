<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Cli;

use Voltz\PortfolioMd\Service\LanguageGatewayException;
use Voltz\PortfolioMd\Service\ReconcileReport;
use Voltz\PortfolioMd\Service\TranslationReconciler;
use WP_CLI;

/**
 * Keeps the languages of the multilingual plugin in line with what the
 * plugin stores (ADR-020, lot 2), from the command line.
 *
 * Registered by the composition root as `wp portfolio translations`. Like
 * the other commands it only translates arguments, calls the service and
 * prints the report; the comparison lives in Service\TranslationReconciler.
 */
final class TranslationsCommand
{
    public function __construct(private readonly TranslationReconciler $reconciler)
    {
    }

    /**
     * Compares the language and the translation group Polylang holds for every document with what the plugin stores, and repairs the differences.
     *
     * The plugin owns the language of a document (content/<lang>/, stored in
     * its metas): Polylang's own screens (its box, its column, quick edit,
     * bulk edit) can drift from it, and this command pushes the stored
     * truth back. A post without stored language (made by hand) is skipped,
     * never adopted from Polylang. Two live posts sharing a key and a
     * language are reported and left alone: give one of them its own key.
     * A failure of Polylang mid-run stops the command; the fixes already made
     * stay, and running it again is safe.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report the differences and write nothing. Exits with an error when there is at least one.
     *
     * [--format=<format>]
     * : Shape of the report.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp portfolio translations reconcile --dry-run
     *     wp portfolio translations reconcile
     *     wp portfolio translations reconcile --format=json
     *
     * @subcommand reconcile
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function reconcile(array $args, array $assocArgs): void
    {
        $dryRun = isset($assocArgs['dry-run']);
        $format = (string) ($assocArgs['format'] ?? 'table');

        try {
            $report = $this->reconciler->reconcile($dryRun);
        } catch (LanguageGatewayException $exception) {
            WP_CLI::error($exception->getMessage());
        }

        if ($format === 'json') {
            WP_CLI::log((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($report->differences as $difference) {
                WP_CLI::log(sprintf(
                    '%s %s/%s (post %d): %s%s',
                    str_pad(strtoupper($difference['kind']), 12),
                    $difference['type'],
                    $difference['slug'],
                    $difference['postId'],
                    $difference['detail'],
                    $difference['fixed'] ? ' [fixed]' : ($difference['kind'] === ReconcileReport::DUPLICATE ? ' [needs a manual fix]' : ($dryRun ? ' [would fix]' : '')),
                ));
            }
        }

        $summary = $report->summary();
        if ($report->failed > 0) {
            WP_CLI::error($summary);
        }
        if ($dryRun && $report->hasDifferences()) {
            WP_CLI::warning($summary . ' A group difference next to a language difference may vanish once the language is fixed: run without --dry-run.');
            WP_CLI::halt(1);
        }

        WP_CLI::success($summary);
    }
}

<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Cli;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageReadiness;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use WP_CLI;

/**
 * Opens and closes the languages of the site (ADR-022, point 2), from the
 * command line and from nowhere else: Morgan decides, never the plugin.
 *
 * Registered by the composition root as `wp portfolio languages`. Like the
 * other commands it only translates arguments, calls the services and
 * prints their reports: the grid lives in Service\LanguageReadiness, the
 * option in Service\PublishedLanguages.
 */
final class LanguagesCommand
{
    public function __construct(
        private readonly PublishedLanguages $published,
        private readonly LanguageReadiness $readiness,
    ) {
    }

    /**
     * Shows which languages are open to the public and what each closed one still misses.
     *
     * ## OPTIONS
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
     *     wp portfolio languages status
     *     wp portfolio languages status --format=json
     *
     * @subcommand status
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function status(array $args, array $assocArgs): void
    {
        $format = (string) ($assocArgs['format'] ?? 'table');
        $rows = [];
        foreach (Language::cases() as $language) {
            $report = $this->readiness->assess($language);
            $rows[] = ['slug' => $language->value, 'published' => $this->published->isPublished($language)] + $report->toArray();
        }

        if ($format === 'json') {
            WP_CLI::log((string) json_encode(['languages' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        foreach ($rows as $row) {
            WP_CLI::log(sprintf('%s  %s  %s', $row['slug'], $row['published'] ? 'open  ' : 'closed', $row['ready'] ? 'ready' : 'not ready'));
            foreach ($row['checks'] as $check) {
                WP_CLI::log(sprintf('      %s %s: %s', $check['ok'] ? 'ok     ' : 'MISSING', $check['label'], $check['detail']));
            }
        }
    }

    /**
     * Opens a language to the public once its readiness grid is green: the four core pages, a menu and the translated sheets.
     *
     * A closed language is served but not announced (no browser detection, no switcher entry, no hreflang, noindex, out of the sitemap); opening it reverses all of that at once.
     *
     * ## OPTIONS
     *
     * <language>
     * : The language to open: de or en.
     *
     * [--force]
     * : Open it even when the grid is not green.
     *
     * ## EXAMPLES
     *
     *     wp portfolio languages publish de
     *     wp portfolio languages publish en --force
     *
     * @subcommand publish
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function publish(array $args, array $assocArgs): void
    {
        $language = $this->language($args);
        if ($this->published->isPublished($language)) {
            WP_CLI::success("{$language->value} is already open.");

            return;
        }

        $report = $this->readiness->assess($language);
        foreach ($report->checks as $check) {
            WP_CLI::log(sprintf('%s %s: %s', $check['ok'] ? 'ok     ' : 'MISSING', $check['label'], $check['detail']));
        }
        if (!$report->isReady() && !isset($assocArgs['force'])) {
            WP_CLI::error("{$language->value} is not ready: " . implode(', ', $report->missing()) . '. Fill the grid, or use --force to open it anyway.');
        }

        foreach ($language->fallbackChain() as $step) {
            if ($step !== $language && !$step->isDefault() && !$this->published->isPublished($step)) {
                WP_CLI::warning("{$step->value} is closed: a reader of {$language->value} may receive {$step->value} documents through the fallback chain, unannounced.");
            }
        }

        $this->published->publish($language);
        WP_CLI::success(sprintf(
            '%s is open%s. Open languages: %s.',
            $language->value,
            $report->isReady() ? '' : ' (forced, the grid is not green)',
            implode(', ', array_map(static fn (Language $open): string => $open->value, $this->published->all())),
        ));
    }

    /**
     * Closes a language: it stays served by URL, but is no longer announced anywhere and is noindex.
     *
     * ## OPTIONS
     *
     * <language>
     * : The language to close: de or en. French, the language of the site, cannot be closed.
     *
     * ## EXAMPLES
     *
     *     wp portfolio languages unpublish de
     *
     * @subcommand unpublish
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function unpublish(array $args, array $assocArgs): void
    {
        $language = $this->language($args);
        if (!$this->published->isPublished($language)) {
            WP_CLI::success("{$language->value} is already closed.");

            return;
        }

        try {
            $this->published->unpublish($language);
        } catch (InvalidArgumentException $exception) {
            WP_CLI::error($exception->getMessage());
        }

        WP_CLI::success(sprintf(
            '%s is closed. Open languages: %s.',
            $language->value,
            implode(', ', array_map(static fn (Language $open): string => $open->value, $this->published->all())),
        ));
    }

    /**
     * @param list<string> $args
     */
    private function language(array $args): Language
    {
        $slug = (string) ($args[0] ?? '');
        $language = Language::tryFrom($slug);
        if ($language === null) {
            WP_CLI::error('The language must be one of ' . implode(', ', Language::values()) . ", \"{$slug}\" given.");
        }

        return $language;
    }
}

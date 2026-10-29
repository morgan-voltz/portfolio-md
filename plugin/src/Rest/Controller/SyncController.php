<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest\Controller;

use Voltz\PortfolioMd\Rest\RestController;
use Voltz\PortfolioMd\Service\Clock;
use Voltz\PortfolioMd\Service\ContentSyncLine;
use Voltz\PortfolioMd\Service\ContentSyncReport;
use Voltz\PortfolioMd\Service\ContentSyncService;
use Voltz\PortfolioMd\Service\SyncFile;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /portfolio/v1/sync`: the Git door (01-plugin-php.md §6.2). A
 * continuous-integration job posts the files a push changed in the
 * content repository, and the service brings them into WordPress, one
 * line of answer per file.
 *
 * The caller authenticates as a WordPress user, with an application
 * password over Basic auth (ADR-019): the request then runs as that user,
 * who authors the posts the synchronization creates and whose rights
 * WordPress applies. The route asks of that user what the paste page asks
 * of its own (a document updates the post that carries its slug, whoever
 * wrote it), `manage_categories` (the grid `skills.yaml` writes the terms
 * of the skill taxonomy, which the admin's own screens gate on it) and
 * `unfiltered_html`: without it, WordPress would filter the stored HTML a
 * second time with a stricter list than the store's, and the task-list
 * checkboxes would vanish. An anonymous call answers 401, a user without
 * these rights 403, both from the server.
 *
 * The body carries the files (path relative to `content/`, text, or the
 * deletion flag), an optional `dry_run` and the optional `commit_sha` of
 * the push, kept for the logs only: idempotence rests on the hashes of the
 * documents (ADR-005), not on the commit. There is no `force` here: a
 * conflict is a human's decision (ADR-005), taken from the admin. The
 * answer is 200 when every file went through, 409 when at least one file
 * waits for a human, 422 when at least one file could not be processed:
 * the job of an unattended caller turns red whenever someone has to look.
 * Every synchronization that is not a dry run is recorded in the option
 * `portfolio_md_last_sync` (commit, instant, counts), even one that changed
 * nothing: it says when the site last heard from the repository, not
 * whether that call wrote.
 */
final class SyncController implements RestController
{
    public const ROUTE = '/sync';
    public const LAST_SYNC_OPTION = 'portfolio_md_last_sync';

    /** What the paste page asks (01-plugin-php.md §11), the right to write taxonomy terms, and the right to store HTML as the store shaped it. */
    public const CAPABILITIES = ['edit_others_posts', 'edit_others_pages', 'manage_categories', 'unfiltered_html'];

    /** Files per request; a push of the whole repository is sent in several requests. */
    public const MAX_FILES = 100;

    /** Longest document accepted, in characters: the cap of the preview route. */
    public const MAX_CONTENT_LENGTH = 1_000_000;

    public function __construct(
        private readonly ContentSyncService $service,
        private readonly Clock $clock,
    ) {
    }

    public function register(string $namespace): void
    {
        register_rest_route($namespace, self::ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'files' => [
                    'description' => 'The files the push changed, with their path relative to content/.',
                    'type' => 'array',
                    'required' => true,
                    'minItems' => 1,
                    'maxItems' => self::MAX_FILES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                            'content' => ['type' => ['string', 'null'], 'maxLength' => self::MAX_CONTENT_LENGTH],
                            'deleted' => ['type' => 'boolean', 'default' => false],
                        ],
                        'required' => ['path'],
                    ],
                ],
                'dry_run' => [
                    'description' => 'Parse, render and decide for every file, write nothing.',
                    'type' => 'boolean',
                    'default' => false,
                ],
                'commit_sha' => [
                    'description' => 'The commit the files come from, for the logs.',
                    'type' => 'string',
                    'pattern' => '^[0-9a-f]{7,64}$',
                ],
            ],
        ]);
    }

    /** Permission callback of the route: every capability, for the current user. */
    public function permission(): bool
    {
        foreach (self::CAPABILITIES as $capability) {
            if (!current_user_can($capability)) {
                return false;
            }
        }

        return true;
    }

    /** Callback of the route. */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Sanitized as the REST server does from a schema, so that a direct call of handle() reads "false" as false too.
        $dryRun = rest_sanitize_boolean($request->get_param('dry_run') ?? false);
        $commit = $request->get_param('commit_sha');
        $commit = is_string($commit) && $commit !== '' ? $commit : null;

        $report = $this->service->synchronize(self::files($request), $dryRun);

        if (!$dryRun) {
            update_option(self::LAST_SYNC_OPTION, [
                'commit' => $commit,
                'at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'counts' => $report->counts(),
            ], false);
        }

        return new WP_REST_Response([
            'commit_sha' => $commit,
            'dry_run' => $dryRun,
            'summary' => $report->counts(),
            'results' => array_map(self::line(...), $report->lines),
        ], self::status($report));
    }

    /**
     * @return list<SyncFile>
     */
    private static function files(WP_REST_Request $request): array
    {
        $files = [];
        foreach ((array) $request->get_param('files') as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = $item['content'] ?? null;
            $files[] = new SyncFile(
                (string) ($item['path'] ?? ''),
                is_string($content) ? $content : null,
                rest_sanitize_boolean($item['deleted'] ?? false),
            );
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(ContentSyncLine $line): array
    {
        return [
            'path' => $line->path,
            'status' => $line->status->value,
            'type' => $line->type?->value,
            'slug' => $line->slug,
            'post_id' => $line->postId,
            'message' => $line->message,
        ];
    }

    private static function status(ContentSyncReport $report): int
    {
        if ($report->hasFailures()) {
            return 422;
        }

        return $report->hasConflicts() ? 409 : 200;
    }
}

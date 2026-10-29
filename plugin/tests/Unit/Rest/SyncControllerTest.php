<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Rest\Controller\SyncController;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ContentSyncService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Tests\Support\FixedClock;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_REST_Request;

/**
 * The Git door with the real synchronization service behind and stubbed
 * WordPress functions: how it is registered, who may call it, how it
 * answers a push.
 */
#[CoversClass(SyncController::class)]
final class SyncControllerTest extends TestCase
{
    private const PAGE = "---\ntitle: \"À propos\"\nslug: \"a-propos\"\ntype: \"page\"\n---\n\nLe parcours.\n";

    private InMemoryContentRepository $repository;
    private ContentImportService $import;
    private SyncController $controller;

    protected function setUp(): void
    {
        WordPressSpy::reset();

        $this->repository = new InMemoryContentRepository();
        $skills = new InMemorySkillTermGateway();
        $this->import = new ContentImportService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            $this->repository,
            $skills,
            new ConflictResolver(),
            FixedClock::at('2026-10-29T10:00:00'),
        );
        $this->controller = new SyncController(
            new ContentSyncService($this->import, $this->repository, new SkillTermSynchronizer($skills), new SkillsGridLoader()),
            FixedClock::at('2026-10-29T10:05:00'),
        );
    }

    #[Test]
    public function registers_a_post_route_with_the_schema_of_a_push(): void
    {
        $this->controller->register('portfolio/v1');

        self::assertCount(1, WordPressSpy::$restRoutes);
        $route = WordPressSpy::$restRoutes[0];
        self::assertSame('portfolio/v1', $route['namespace']);
        self::assertSame('/sync', $route['route']);
        self::assertSame('POST', $route['args']['methods']);
        self::assertSame([$this->controller, 'handle'], $route['args']['callback']);
        self::assertSame([$this->controller, 'permission'], $route['args']['permission_callback']);
        self::assertSame(['files', 'dry_run', 'commit_sha'], array_keys($route['args']['args']));

        $files = $route['args']['args']['files'];
        self::assertTrue($files['required']);
        self::assertSame('array', $files['type']);
        self::assertSame(1, $files['minItems']);
        self::assertSame(100, $files['maxItems']);
        self::assertSame('object', $files['items']['type']);
        self::assertSame(['path', 'content', 'deleted'], array_keys($files['items']['properties']));
        self::assertSame(['type' => ['string', 'null'], 'maxLength' => 1_000_000], $files['items']['properties']['content']);
        self::assertSame(['path'], $files['items']['required']);
        self::assertSame(['type' => 'boolean', 'default' => false], self::schemaOf($route['args']['args']['dry_run']));
        self::assertSame(['type' => 'string', 'pattern' => '^[0-9a-f]{7,64}$'], self::schemaOf($route['args']['args']['commit_sha']));
    }

    #[Test]
    public function the_route_asks_the_rights_of_the_paste_page_of_the_taxonomy_screens_and_the_right_to_store_html(): void
    {
        foreach (SyncController::CAPABILITIES as $capability) {
            WordPressSpy::$capabilityResults[$capability] = true;
        }
        self::assertTrue($this->controller->permission());
        self::assertSame([['edit_others_posts'], ['edit_others_pages'], ['manage_categories'], ['unfiltered_html']], WordPressSpy::$currentUserCanCalls);

        foreach (SyncController::CAPABILITIES as $missing) {
            foreach (SyncController::CAPABILITIES as $capability) {
                WordPressSpy::$capabilityResults[$capability] = $capability !== $missing;
            }
            self::assertFalse($this->controller->permission(), $missing);
        }
    }

    #[Test]
    public function answers_200_with_a_line_per_file_and_records_the_synchronization(): void
    {
        $response = $this->controller->handle(self::request([
            'files' => [
                ['path' => 'pages/a-propos.md', 'content' => self::PAGE],
                ['path' => 'README.md', 'content' => "# Content\n"],
            ],
            'commit_sha' => 'abc1234',
        ]));
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertSame(['commit_sha', 'dry_run', 'summary', 'results'], array_keys($data));
        self::assertSame('abc1234', $data['commit_sha']);
        self::assertFalse($data['dry_run']);
        self::assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'failed' => 0, 'deleted' => 0, 'skipped' => 1], $data['summary']);
        self::assertSame(['path', 'status', 'type', 'slug', 'post_id', 'message'], array_keys($data['results'][0]));
        self::assertSame(['path' => 'pages/a-propos.md', 'status' => 'created', 'type' => 'page', 'slug' => 'a-propos', 'post_id' => 100, 'message' => ''], $data['results'][0]);
        self::assertSame('skipped', $data['results'][1]['status']);
        self::assertNull($data['results'][1]['type']);

        self::assertSame([
            'commit' => 'abc1234',
            'at' => '2026-10-29T10:05:00Z',
            'counts' => $data['summary'],
        ], get_option(SyncController::LAST_SYNC_OPTION));
    }

    #[Test]
    public function a_replayed_push_is_recorded_too_even_though_it_wrote_nothing(): void
    {
        $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'content' => self::PAGE]], 'commit_sha' => 'abc1234']));

        $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'content' => self::PAGE]]]));

        $recorded = get_option(SyncController::LAST_SYNC_OPTION);
        self::assertNull($recorded['commit']);
        self::assertSame(1, $recorded['counts']['unchanged']);
    }

    #[Test]
    public function the_booleans_are_read_as_the_rest_server_reads_them(): void
    {
        $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'content' => self::PAGE]]]));

        $response = $this->controller->handle(self::request([
            'files' => [['path' => 'pages/a-propos.md', 'content' => self::PAGE, 'deleted' => 'false']],
            'dry_run' => 'false',
        ]));

        self::assertFalse($response->get_data()['dry_run']);
        self::assertSame('unchanged', $response->get_data()['results'][0]['status']);
        self::assertSame([], $this->repository->trashed);
        self::assertIsArray(get_option(SyncController::LAST_SYNC_OPTION, null));
    }

    #[Test]
    public function answers_409_when_a_file_waits_for_a_human(): void
    {
        $this->import->import(self::PAGE, new ImportOptions(source: ContentSource::Admin));

        $response = $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'content' => str_replace('Le parcours.', 'Depuis Git.', self::PAGE)]]]));

        self::assertSame(409, $response->get_status());
        self::assertSame('conflict', $response->get_data()['results'][0]['status']);
        self::assertSame(1, $response->get_data()['summary']['conflict']);
        self::assertArrayHasKey(SyncController::LAST_SYNC_OPTION, WordPressSpy::$options);
    }

    #[Test]
    public function answers_422_when_a_file_could_not_be_processed_whatever_else_happened(): void
    {
        $this->import->import(self::PAGE, new ImportOptions(source: ContentSource::Admin));

        $response = $this->controller->handle(self::request(['files' => [
            ['path' => 'pages/a-propos.md', 'content' => str_replace('Le parcours.', 'Depuis Git.', self::PAGE)],
            ['path' => 'pages/casse.md', 'content' => "No frontmatter.\n"],
        ]]));

        self::assertSame(422, $response->get_status());
        self::assertSame(['conflict', 'failed'], array_column($response->get_data()['results'], 'status'));
    }

    #[Test]
    public function a_dry_run_writes_nothing_and_records_nothing(): void
    {
        $response = $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'content' => self::PAGE]], 'dry_run' => true]));
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($data['dry_run']);
        self::assertNull($data['commit_sha']);
        self::assertSame('created', $data['results'][0]['status']);
        self::assertSame([], $this->repository->saved);
        self::assertSame([], WordPressSpy::$options);
        self::assertNull(get_option(SyncController::LAST_SYNC_OPTION, null));
    }

    #[Test]
    public function a_deleted_file_is_handed_over_without_content(): void
    {
        $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'content' => self::PAGE]]]));

        $response = $this->controller->handle(self::request(['files' => [['path' => 'pages/a-propos.md', 'deleted' => true]]]));

        self::assertSame(200, $response->get_status());
        self::assertSame('deleted', $response->get_data()['results'][0]['status']);
        self::assertSame([100], $this->repository->trashed);
    }

    #[Test]
    public function items_that_are_not_objects_are_ignored(): void
    {
        $response = $this->controller->handle(self::request(['files' => ['pages/a-propos.md', ['path' => 'pages/a-propos.md', 'content' => self::PAGE]]]));

        self::assertCount(1, $response->get_data()['results']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/portfolio/v1/sync');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $arg
     *
     * @return array<string, mixed>
     */
    private static function schemaOf(array $arg): array
    {
        unset($arg['description']);

        return $arg;
    }
}

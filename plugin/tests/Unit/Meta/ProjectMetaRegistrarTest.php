<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Meta\MetaRegistration;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(ProjectMetaRegistrar::class)]
final class ProjectMetaRegistrarTest extends TestCase
{
    private const EXPECTED_KEYS = [
        ProjectMetaRegistrar::PROJECT_STATUS,
        ProjectMetaRegistrar::PROJECT_ROLE,
        ProjectMetaRegistrar::PROJECT_PERIOD_START,
        ProjectMetaRegistrar::PROJECT_PERIOD_END,
        ProjectMetaRegistrar::PROJECT_REPO_URL,
        ProjectMetaRegistrar::PROJECT_DEMO_URL,
        ProjectMetaRegistrar::PROJECT_CLIENT,
        ProjectMetaRegistrar::IS_FIL_ROUGE,
    ];

    protected function setUp(): void
    {
        WordPressSpy::reset();
        (new ProjectMetaRegistrar())->register();
    }

    #[Test]
    public function registers_the_eight_project_metas_on_projects_only(): void
    {
        self::assertSame(self::EXPECTED_KEYS, WordPressSpy::postMetaKeys('project'));
        self::assertSame([], WordPressSpy::postMetaKeys('article'));
    }

    #[Test]
    #[DataProvider('definitionProvider')]
    public function each_meta_is_public_single_and_typed(string $metaKey, string $type, mixed $default, string $sanitizer): void
    {
        $args = WordPressSpy::postMeta('project', $metaKey);

        self::assertNotNull($args);
        self::assertSame($type, $args['type']);
        self::assertTrue($args['single']);
        self::assertSame($default, $args['default']);
        self::assertTrue($args['show_in_rest']);
        self::assertSame([MetaRegistration::class, 'authorizeEditPost'], $args['auth_callback']);
        self::assertSame([ProjectMetaRegistrar::class, $sanitizer], $args['sanitize_callback']);
        self::assertIsCallable($args['sanitize_callback']);
        self::assertNotSame('', $args['description']);
    }

    /** @return iterable<string, array{string, string, mixed, string}> */
    public static function definitionProvider(): iterable
    {
        yield 'status' => [ProjectMetaRegistrar::PROJECT_STATUS, 'string', '', 'sanitizeProjectStatus'];
        yield 'role' => [ProjectMetaRegistrar::PROJECT_ROLE, 'string', '', 'sanitizeProjectRole'];
        yield 'period start' => [ProjectMetaRegistrar::PROJECT_PERIOD_START, 'string', '', 'sanitizePeriodStart'];
        yield 'period end' => [ProjectMetaRegistrar::PROJECT_PERIOD_END, 'string', '', 'sanitizePeriodEnd'];
        yield 'repo url' => [ProjectMetaRegistrar::PROJECT_REPO_URL, 'string', '', 'sanitizeRepoUrl'];
        yield 'demo url' => [ProjectMetaRegistrar::PROJECT_DEMO_URL, 'string', '', 'sanitizeDemoUrl'];
        yield 'client' => [ProjectMetaRegistrar::PROJECT_CLIENT, 'string', '', 'sanitizeProjectClient'];
        yield 'fil rouge' => [ProjectMetaRegistrar::IS_FIL_ROUGE, 'boolean', false, 'sanitizeIsFilRouge'];
    }

    #[Test]
    public function status_accepts_exactly_the_taxonomy_terms(): void
    {
        foreach (array_keys(TaxonomyRegistrar::PROJECT_STATUS_TERMS) as $slug) {
            self::assertSame($slug, ProjectMetaRegistrar::sanitizeProjectStatus($slug));
        }

        self::assertSame('stable', ProjectMetaRegistrar::sanitizeProjectStatus(' stable '));
        self::assertSame('', ProjectMetaRegistrar::sanitizeProjectStatus('finished'));
        self::assertSame('', ProjectMetaRegistrar::sanitizeProjectStatus('Stable'));
        self::assertSame('', ProjectMetaRegistrar::sanitizeProjectStatus(''));
    }

    #[Test]
    public function period_dates_must_be_real_iso_dates(): void
    {
        self::assertSame('2025-09-01', ProjectMetaRegistrar::sanitizePeriodStart('2025-09-01'));
        self::assertSame('', ProjectMetaRegistrar::sanitizePeriodStart('2025-02-30'));
        self::assertSame('', ProjectMetaRegistrar::sanitizePeriodStart('01/09/2025'));
        self::assertSame('', ProjectMetaRegistrar::sanitizePeriodEnd(''));
    }

    #[Test]
    public function urls_are_restricted_to_http_and_https(): void
    {
        self::assertSame('https://github.com/x/y', ProjectMetaRegistrar::sanitizeRepoUrl('https://github.com/x/y'));
        self::assertSame('', ProjectMetaRegistrar::sanitizeRepoUrl('javascript:alert(1)'));
        self::assertSame('', ProjectMetaRegistrar::sanitizeDemoUrl('ftp://demo.example'));
        self::assertSame('', ProjectMetaRegistrar::sanitizeDemoUrl(''));
    }

    #[Test]
    public function role_and_client_are_single_line_plain_text(): void
    {
        self::assertSame('Lead developer', ProjectMetaRegistrar::sanitizeProjectRole("<b>Lead</b>\ndeveloper"));
        self::assertSame('ACME', ProjectMetaRegistrar::sanitizeProjectClient('  ACME  '));
    }

    #[Test]
    public function fil_rouge_flag_accepts_textual_booleans(): void
    {
        self::assertTrue(ProjectMetaRegistrar::sanitizeIsFilRouge('yes'));
        self::assertFalse(ProjectMetaRegistrar::sanitizeIsFilRouge('no'));
        self::assertFalse(ProjectMetaRegistrar::sanitizeIsFilRouge(null));
    }
}

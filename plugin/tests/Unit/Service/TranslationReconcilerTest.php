<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageGatewayException;
use Voltz\PortfolioMd\Service\ReconcileReport;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Service\TranslationReconciler;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemoryLanguageGateway;

/**
 * The reconciliation against an in-memory store and an in-memory Polylang
 * (InMemoryLanguageGateway plays both the gateway and the inspector): what
 * the plugin stores is the truth, what Polylang holds is primed to drift.
 */
#[CoversClass(TranslationReconciler::class)]
#[CoversClass(ReconcileReport::class)]
final class TranslationReconcilerTest extends TestCase
{
    private InMemoryContentRepository $repository;

    private InMemoryLanguageGateway $polylang;

    private TranslationReconciler $reconciler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryContentRepository();
        $this->polylang = new InMemoryLanguageGateway();
        $this->reconciler = new TranslationReconciler($this->repository, $this->repository, $this->polylang, $this->polylang);
    }

    #[Test]
    public function a_site_in_line_with_the_store_reports_nothing(): void
    {
        $this->seedGroup();

        $report = $this->reconciler->reconcile(false);

        self::assertSame(2, $report->checked);
        self::assertFalse($report->hasDifferences());
        self::assertSame([], $this->polylang->calls);
        self::assertSame([], $this->polylang->links);
        self::assertSame('2 post(s) checked, 0 skipped (no stored language), 0 language difference(s), 0 group difference(s), 0 fixed, 0 failed', $report->summary());
    }

    #[Test]
    public function a_language_drift_is_reported_in_a_dry_run_and_fixed_in_a_real_run(): void
    {
        $this->seedGroup();
        $this->polylang->polylangLanguages[41] = 'fr'; // quick edit put the German post in French

        $dry = $this->reconciler->reconcile(true);

        self::assertCount(1, $dry->differences);
        self::assertSame(['postId' => 41, 'type' => 'project', 'slug' => 'projet-de', 'kind' => 'language', 'detail' => 'stored "de", Polylang says "fr"', 'fixed' => false], $dry->differences[0]);
        self::assertSame([], $this->polylang->calls, 'a dry run writes nothing');
        self::assertStringEndsWith('(dry run: nothing written)', $dry->summary());

        $real = $this->reconciler->reconcile(false);

        self::assertTrue($real->differences[0]['fixed']);
        self::assertSame(1, $real->languageFixes);
        self::assertSame([[41, Language::De]], $this->polylang->calls);
        self::assertFalse($this->reconciler->reconcile(false)->hasDifferences(), 'a second run finds nothing');
    }

    #[Test]
    public function a_missing_language_in_polylang_is_a_difference_too(): void
    {
        $this->seedGroup();
        unset($this->polylang->polylangLanguages[40]);

        $report = $this->reconciler->reconcile(true);

        self::assertSame('stored "fr", Polylang says none', $report->differences[0]['detail']);
    }

    #[Test]
    public function an_unlinked_group_is_reported_and_linked_again(): void
    {
        $this->seedGroup();
        $this->polylang->polylangGroups[41] = []; // the German post lost its group

        $report = $this->reconciler->reconcile(false);

        self::assertCount(1, $report->differences);
        self::assertSame('group', $report->differences[0]['kind']);
        self::assertSame(41, $report->differences[0]['postId']);
        self::assertSame('expected {fr: 40, de: 41}, Polylang shows no group from this post', $report->differences[0]['detail']);
        self::assertSame([['fr' => 40, 'de' => 41]], $this->polylang->links);
        self::assertSame(1, $report->groupFixes);
    }

    #[Test]
    public function a_post_alone_under_its_key_that_still_sees_a_group_is_dissolved(): void
    {
        $this->seedGroup();
        $this->repository->trash(41); // the German translation was retired by Git
        $this->polylang->polylangGroups[40] = ['fr' => 40, 'de' => 41]; // Polylang still shows the group

        $report = $this->reconciler->reconcile(false);

        self::assertSame('stale-group', $report->differences[0]['kind']);
        self::assertSame('alone under the key "projet" but still grouped with {fr: 40, de: 41}', $report->differences[0]['detail']);
        self::assertSame([['fr' => 40]], $this->polylang->links, 'a group of one dissolves what remains');
        self::assertSame(1, $report->checked, 'the trashed post is out of scope');
    }

    #[Test]
    public function a_post_without_stored_language_is_skipped_never_adopted_from_polylang(): void
    {
        $this->repository->seed(ContentType::Page, 'contact', new StoredContent(50, str_repeat('a', 64), null, '1.2.0', documentSlug: 'contact'));
        $this->polylang->polylangLanguages[50] = 'de';

        $report = $this->reconciler->reconcile(false);

        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->checked);
        self::assertFalse($report->hasDifferences());
        self::assertSame([], $this->polylang->calls);
    }

    #[Test]
    public function two_live_posts_sharing_a_key_and_a_language_are_reported_and_left_alone(): void
    {
        $this->seedGroup();
        $this->repository->seed(ContentType::Project, 'projet-copie', new StoredContent(42, str_repeat('c', 64), ContentSource::Git, '1.2.0', documentSlug: 'projet-copie', lang: Language::Fr, translationKey: 'projet'));
        $this->polylang->polylangLanguages[42] = 'fr';

        $report = $this->reconciler->reconcile(false);

        $duplicate = array_values(array_filter($report->differences, static fn (array $d): bool => $d['kind'] === 'duplicate'));
        self::assertCount(1, $duplicate);
        self::assertSame(42, $duplicate[0]['postId']);
        self::assertStringContainsString('shares the key "projet" and the language "fr" with post 40', $duplicate[0]['detail']);
        self::assertSame(1, $report->failed);
        self::assertSame([], $this->polylang->links, 'the group of the oldest posts is intact: nothing to fix');
    }

    #[Test]
    public function refuses_to_run_when_polylang_is_absent_or_misses_a_language(): void
    {
        $this->seedGroup();
        $this->polylang->configuredLanguages = ['fr', 'de'];
        try {
            $this->reconciler->reconcile(true);
            self::fail('a missing language must stop the reconciliation');
        } catch (LanguageGatewayException $exception) {
            self::assertStringContainsString('"en" is not configured', $exception->getMessage());
        }

        $this->polylang->configuredLanguages = null;
        $this->expectException(LanguageGatewayException::class);
        $this->expectExceptionMessage('does not answer');
        $this->reconciler->reconcile(true);
    }

    #[Test]
    public function the_report_has_a_json_shape(): void
    {
        $this->seedGroup();
        $this->polylang->polylangLanguages[41] = 'en';

        $array = $this->reconciler->reconcile(true)->toArray();

        self::assertTrue($array['dryRun']);
        self::assertSame(['checked' => 2, 'skipped' => 0, 'languageFixes' => 0, 'groupFixes' => 0, 'failed' => 0], $array['counts']);
        self::assertSame('language', $array['differences'][0]['kind']);
    }

    /** A French project and its German translation, stored and held by Polylang alike. */
    private function seedGroup(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(40, str_repeat('a', 64), ContentSource::Git, '1.2.0', documentSlug: 'projet', lang: Language::Fr, translationKey: 'projet'));
        $this->repository->seed(ContentType::Project, 'projet-de', new StoredContent(41, str_repeat('b', 64), ContentSource::Git, '1.2.0', documentSlug: 'projet-de', lang: Language::De, translationKey: 'projet'));
        $this->polylang->polylangLanguages = [40 => 'fr', 41 => 'de'];
        $this->polylang->polylangGroups = [40 => ['fr' => 40, 'de' => 41], 41 => ['fr' => 40, 'de' => 41]];
    }
}

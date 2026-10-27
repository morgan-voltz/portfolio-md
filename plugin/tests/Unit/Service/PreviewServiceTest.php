<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\PreviewResult;
use Voltz\PortfolioMd\Service\PreviewService;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;

/**
 * The preview with the real parser and renderer and an in-memory skill
 * taxonomy: what it renders, what it summarises, what it flags.
 */
#[CoversClass(PreviewService::class)]
#[CoversClass(PreviewResult::class)]
final class PreviewServiceTest extends TestCase
{
    private const PROJECT = <<<'MD'
---
title: "Projet"
slug: "projet"
type: "project"
date: 2026-10-27
status: "en-cours"
skills: ["4.0.2/E07"]
tags: [php, rust]
---

# Projet

Un paragraphe.

MD;

    private PreviewService $service;

    protected function setUp(): void
    {
        $skills = new InMemorySkillTermGateway();
        $skills->seed('Licence 4.0.2', '4-0-2', '', 0, null);
        $skills->seed('Concevoir', 'e07', '', 1, '4.0.2/E07');

        $this->service = new PreviewService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            $skills,
        );
    }

    #[Test]
    public function renders_a_project_with_its_title_and_a_summary_of_its_frontmatter(): void
    {
        $result = $this->service->preview(self::PROJECT);

        self::assertTrue($result->importable());
        self::assertSame([], $result->problems);
        self::assertSame('Projet', $result->title);
        self::assertStringContainsString('<!-- wp:heading {"level":1} -->', (string) $result->html);
        self::assertStringContainsString('<p>Un paragraphe.</p>', (string) $result->html);
        self::assertSame([
            'type' => 'project',
            'slug' => 'projet',
            'date' => '2026-10-27',
            'status' => 'En cours',
            'skills' => '4.0.2/E07',
            'tags' => 'php, rust',
        ], $result->summary);
    }

    #[Test]
    public function an_article_has_no_status_line_and_shows_what_is_empty_as_empty(): void
    {
        $result = $this->service->preview("---\ntitle: \"Article\"\nslug: \"article\"\ntype: \"article\"\n---\n\nTexte.\n");

        self::assertSame(['type' => 'article', 'slug' => 'article', 'date' => '', 'skills' => '', 'tags' => ''], $result->summary);
    }

    #[Test]
    public function names_the_competency_codes_the_grid_does_not_know_and_still_renders(): void
    {
        $result = $this->service->preview(str_replace('skills: ["4.0.2/E07"]', 'skills: ["4.0.2/E07", "4.0.2/E99", "4.0.1/E01"]', self::PROJECT));

        self::assertFalse($result->importable());
        self::assertCount(1, $result->problems);
        self::assertStringContainsString('Unknown competency code(s): 4.0.2/E99, 4.0.1/E01', $result->problems[0]);
        self::assertStringContainsString('<p>Un paragraphe.</p>', (string) $result->html);
        self::assertSame('4.0.2/E07, 4.0.2/E99, 4.0.1/E01', $result->summary['skills']);
    }

    #[Test]
    public function a_page_is_rendered_but_reported_as_not_importable(): void
    {
        $result = $this->service->preview("---\ntitle: \"À propos\"\nslug: \"a-propos\"\ntype: \"page\"\n---\n\nQui je suis.\n");

        self::assertFalse($result->importable());
        self::assertCount(1, $result->problems);
        self::assertStringContainsString('cannot be imported yet', $result->problems[0]);
        self::assertSame('À propos', $result->title);
        self::assertStringContainsString('<p>Qui je suis.</p>', (string) $result->html);
        self::assertSame('page', $result->summary['type']);
    }

    #[Test]
    public function a_document_without_frontmatter_is_refused_without_rendering(): void
    {
        $result = $this->service->preview("Just a paragraph, no frontmatter.\n");

        self::assertFalse($result->importable());
        self::assertNull($result->title);
        self::assertNull($result->html);
        self::assertSame([], $result->summary);
        self::assertCount(1, $result->problems);
        self::assertNotSame('', $result->problems[0]);
    }

    #[Test]
    public function an_invalid_frontmatter_is_refused_with_the_key_at_fault(): void
    {
        $result = $this->service->preview(str_replace('slug: "projet"', 'slug: "Projet Un"', self::PROJECT));

        self::assertNull($result->html);
        self::assertCount(1, $result->problems);
        self::assertStringContainsString('slug', $result->problems[0]);
    }

    #[Test]
    public function an_empty_text_is_refused_like_any_other_unreadable_document(): void
    {
        $result = $this->service->preview('');

        self::assertFalse($result->importable());
        self::assertNull($result->html);
        self::assertCount(1, $result->problems);
    }
}

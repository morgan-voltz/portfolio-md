<?php

/**
 * Smoke test of the Markdown import against a real WordPress, run through
 * WP-CLI. It exercises Storage\WordPressContentRepository, the only part of
 * the import chain the unit suite cannot reach, with synthetic documents.
 *
 * From the repository root, with the stack up and the plugin activated:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/import-smoke.php --user=<admin login>
 *
 * `--user` is required: posts need an author. The script creates one
 * article and one project (slugs zz-smoke-*), checks what WordPress stored,
 * plays the conflict rule, and deletes everything it created, including the
 * tech tag it made.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\MetaWriter;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportException;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (get_current_user_id() === 0) {
    WP_CLI::error('Run this script with --user=<admin login>: posts need an author.');
}

$failures = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        WP_CLI::log("PASS  {$label}");

        return;
    }

    $failures++;
    WP_CLI::warning("FAIL  {$label}" . ($detail !== '' ? " ({$detail})" : ''));
};

$postBySlug = static function (string $type, string $slug): ?WP_Post {
    $posts = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => -1]);

    return $posts[0] ?? null;
};

$countBySlug = static function (string $type, string $slug): int {
    return count(get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => -1]));
};

$meta = static fn (int $postId, string $key): mixed => get_post_meta($postId, $key, true);

$renderer = new ContentRenderer(new MarkdownParser(), new GutenbergTransformer());
$frontmatterParser = new FrontmatterParser();
$skillGateway = new WordPressSkillTermGateway();
$service = new ContentImportService(
    $frontmatterParser,
    $renderer,
    new WordPressContentRepository(),
    $skillGateway,
    new ConflictResolver(),
    new SystemClock(),
);

$started = microtime(true);
new Highlight\Highlighter();
WP_CLI::log(sprintf('INFO  highlighter loads its %d grammars in %d ms', count(Highlight\Highlighter::listBundledLanguages()), (int) round((microtime(true) - $started) * 1000)));

$articleSlug = 'zz-smoke-article';
$projectSlug = 'zz-smoke-project';
$tagSlug = 'zz-smoke-tag';
$createdPosts = [];

$article = <<<'MD'
---
title: "Article de fumée"
slug: "zz-smoke-article"
type: "article"
date: 2026-10-26
subtitle: "Sous-titre de fumée"
seo_description: "Description SEO de fumée."
featured: true
reading_time: 7
tags: [zz-smoke-tag]
---

# Titre

Un paragraphe avec `C:\chemin\fichier` et un [lien](https://example.com).

```php
<?php
echo $tableau[0];
```

- [x] fait
- [ ] à faire

| Colonne | Valeur |
|---|:-:|
| a | 1 |

> Citation.

---

![Image](https://example.com/image.png)

MD;

$skill = null;
foreach (array_merge(['4.0.2/E00'], array_map(static fn (int $i): string => sprintf('4.0.1/FR-%02d', $i), range(1, 3))) as $candidate) {
    $skill = $skillGateway->findByCode($candidate);
    if ($skill !== null) {
        break;
    }
}
if ($skill === null) {
    WP_CLI::error('No competency term found: run `wp portfolio skills sync` first.');
}

$project = static fn (string $skillCode): string => <<<MD
---
title: "Projet de fumée"
slug: "zz-smoke-project"
type: "project"
status: "en-cours"
role: "Développeur de fumée"
period_start: 2026-01-12
period_end: ""
repo_url: "https://example.com/repo"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [zz-smoke-tag]
skills: ["{$skillCode}"]
---

# Projet de fumée

Corps du projet.

MD;

try {
    // 1. An article is created with its content, metas and tags -----------------------------

    $result = $service->import($article, new ImportOptions());
    $check('the article is created', $result->outcome === ImportOutcome::Created && $result->postId !== null, $result->outcome->value);
    $post = $postBySlug(PostTypeRegistrar::ARTICLE, $articleSlug);
    $check('the article exists under its slug', $post instanceof WP_Post);
    if (!$post instanceof WP_Post) {
        WP_CLI::error('cannot go on without the article');
    }
    $createdPosts[] = (int) $post->ID;
    $articleId = (int) $post->ID;

    $check('the post id reported is the post created', $result->postId === $articleId);
    $check('title, status and author are set', $post->post_title === 'Article de fumée' && $post->post_status === 'publish' && (int) $post->post_author === get_current_user_id(), "{$post->post_title} / {$post->post_status} / {$post->post_author}");
    $check('the frontmatter date became the post date in the site timezone', $post->post_date === '2026-10-26 00:00:00' && $post->post_date_gmt === get_gmt_from_date('2026-10-26 00:00:00'), "{$post->post_date} / {$post->post_date_gmt}");

    $content = $post->post_content;
    $check('the content starts with a heading block', str_starts_with($content, "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Titre</h1>"));
    // kses writes numeric entities on three digits: the "[" escaped as &#91; is stored as &#091;.
    // The highlighter wraps the "0" in a span of its own, hence the partial match.
    $check('the code block carries its classes on the pre and escapes brackets', str_contains($content, '<!-- wp:code {"className":"hljs language-php"} -->') && str_contains($content, '$tableau&#091;<span class="hljs-number">0</span>];'));
    $check('the backslashes of inline code survived', str_contains($content, '<code>C:\chemin\fichier</code>'));
    $check('the task list is kept as raw HTML with its checkboxes', str_contains($content, '<!-- wp:html -->') && substr_count($content, 'type="checkbox"') === 2);
    $check('table, quote, separator and image blocks are there', str_contains($content, '<!-- wp:table') && str_contains($content, '<!-- wp:quote') && str_contains($content, '<!-- wp:separator') && str_contains($content, '<!-- wp:image'));

    // kses normalises two things it leaves otherwise intact: numeric entities get three digits
    // and a self-closing tag gets a space before its slash. Once undone, nothing else may differ.
    $normalised = str_replace(['&#091;', ' />'], ['&#91;', '/>'], $content);
    $check('kses stripped nothing from the rendered HTML', $normalised === $renderer->render($frontmatterParser->parse($article)->body));

    // The block parser reports the blank lines between blocks as nameless freeform blocks; nothing else may be nameless.
    $freeform = array_filter(parse_blocks($content), static fn (array $block): bool => $block['blockName'] === null && trim($block['innerHTML']) !== '');
    $check('parse_blocks() reads the content as blocks only', $freeform === []);

    $check('the Markdown is stored verbatim', $meta($articleId, InternalMetaRegistrar::MD_SOURCE) === $article);
    $check('the hash is the SHA-256 of the Markdown', $meta($articleId, InternalMetaRegistrar::MD_HASH) === hash('sha256', $article));
    $check('source type, render version and sync time are recorded', $meta($articleId, InternalMetaRegistrar::SOURCE_TYPE) === 'git' && $meta($articleId, InternalMetaRegistrar::RENDER_VERSION) === ContentRenderer::VERSION && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $meta($articleId, InternalMetaRegistrar::LAST_SYNC_AT)) === 1);
    $check('the common metas are filled', $meta($articleId, CommonMetaRegistrar::SEO_DESCRIPTION) === 'Description SEO de fumée.' && $meta($articleId, CommonMetaRegistrar::SUBTITLE) === 'Sous-titre de fumée' && (bool) $meta($articleId, CommonMetaRegistrar::FEATURED) === true && (int) $meta($articleId, CommonMetaRegistrar::READING_TIME) === 7);
    $check('the tech tag is created and attached', wp_get_object_terms($articleId, TaxonomyRegistrar::TECH_TAG, ['fields' => 'slugs']) === [$tagSlug]);

    // 2. Idempotence and the conflict rule --------------------------------------------------

    $again = $service->import($article, new ImportOptions());
    $check('a second import is unchanged', $again->outcome === ImportOutcome::Unchanged && $again->postId === $articleId, $again->outcome->value);
    $check('no duplicate post was created', $countBySlug(PostTypeRegistrar::ARTICLE, $articleSlug) === 1);

    MetaWriter::update($articleId, InternalMetaRegistrar::SOURCE_TYPE, ContentSource::Admin->value);
    MetaWriter::update($articleId, InternalMetaRegistrar::MD_HASH, str_repeat('0', 64));
    $conflict = $service->import($article, new ImportOptions());
    $check('an admin edit blocks a git import', $conflict->outcome === ImportOutcome::Conflict && $conflict->postId === $articleId, $conflict->outcome->value);
    $check('the conflicting post is left alone', $meta($articleId, InternalMetaRegistrar::SOURCE_TYPE) === 'admin');

    $forced = $service->import($article, new ImportOptions(force: true));
    $check('--force overwrites the admin edit', $forced->outcome === ImportOutcome::Updated && $forced->postId === $articleId, $forced->outcome->value);
    $check('the post is back under git with the right hash', $meta($articleId, InternalMetaRegistrar::SOURCE_TYPE) === 'git' && $meta($articleId, InternalMetaRegistrar::MD_HASH) === hash('sha256', $article));

    $renamed = str_replace('title: "Article de fumée"', 'title: "Article renommé"', $article);
    $updated = $service->import($renamed, new ImportOptions());
    $check('a changed file updates the same post', $updated->outcome === ImportOutcome::Updated && $updated->postId === $articleId, $updated->outcome->value);
    $check('the title changed, the slug did not', get_post($articleId)->post_title === 'Article renommé' && get_post($articleId)->post_name === $articleSlug);

    // 3. A project gets its status, skills and project metas ----------------------------------

    $result = $service->import($project($skill->code), new ImportOptions());
    $check('the project is created', $result->outcome === ImportOutcome::Created, $result->outcome->value);
    $post = $postBySlug(PostTypeRegistrar::PROJECT, $projectSlug);
    $check('the project exists under its slug', $post instanceof WP_Post);
    if ($post instanceof WP_Post) {
        $createdPosts[] = (int) $post->ID;
        $projectId = (int) $post->ID;
        $check('the project status term is attached', wp_get_object_terms($projectId, TaxonomyRegistrar::PROJECT_STATUS, ['fields' => 'slugs']) === ['en-cours']);
        $check('the competency term is attached by its code', wp_get_object_terms($projectId, TaxonomyRegistrar::SKILL, ['fields' => 'ids']) === [$skill->id]);
        $check('the shared tech tag is reused, not duplicated', wp_get_object_terms($projectId, TaxonomyRegistrar::TECH_TAG, ['fields' => 'slugs']) === [$tagSlug] && get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG) instanceof WP_Term);
        $check('the project metas are filled', $meta($projectId, ProjectMetaRegistrar::PROJECT_STATUS) === 'en-cours' && $meta($projectId, ProjectMetaRegistrar::PROJECT_ROLE) === 'Développeur de fumée' && $meta($projectId, ProjectMetaRegistrar::PROJECT_PERIOD_START) === '2026-01-12' && $meta($projectId, ProjectMetaRegistrar::PROJECT_PERIOD_END) === '' && $meta($projectId, ProjectMetaRegistrar::PROJECT_REPO_URL) === 'https://example.com/repo' && (bool) $meta($projectId, ProjectMetaRegistrar::IS_FIL_ROUGE) === false);
        $check('the project keeps no post date of its own', get_post($projectId)->post_date !== '0000-00-00 00:00:00');
    }

    // 4. Refusals write nothing -------------------------------------------------------------------

    $refused = null;
    try {
        $service->import(str_replace($skill->code, '9.9.9/ZZ-99', $project($skill->code)), new ImportOptions());
    } catch (ImportException $exception) {
        $refused = $exception->getMessage();
    }
    $check('an unknown competency code is refused', $refused !== null && str_contains($refused, '9.9.9/ZZ-99'), (string) $refused);

    $refused = null;
    try {
        $service->import("---\ntitle: \"Page\"\nslug: \"zz-smoke-page\"\ntype: \"page\"\n---\n\nCorps.\n", new ImportOptions());
    } catch (ImportException $exception) {
        $refused = $exception->getMessage();
    }
    $check('a page is refused for now', $refused !== null && str_contains($refused, 'page'), (string) $refused);

    $dry = $service->import(str_replace('zz-smoke-article', 'zz-smoke-dry', $article), new ImportOptions(dryRun: true));
    $check('a dry run reports a creation', $dry->outcome === ImportOutcome::Created && $dry->postId === null, $dry->outcome->value);
    $check('a dry run creates nothing', $countBySlug(PostTypeRegistrar::ARTICLE, 'zz-smoke-dry') === 0);
} finally {
    foreach ($createdPosts as $postId) {
        wp_delete_post($postId, true);
    }
    $tag = get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG);
    if ($tag instanceof WP_Term) {
        wp_delete_term($tag->term_id, TaxonomyRegistrar::TECH_TAG);
    }
}

$check('the smoke posts are cleaned up', $countBySlug(PostTypeRegistrar::ARTICLE, $articleSlug) === 0 && $countBySlug(PostTypeRegistrar::PROJECT, $projectSlug) === 0);
$check('the smoke tag is cleaned up', get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG) === false);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');

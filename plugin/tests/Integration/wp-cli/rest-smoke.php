<?php

/**
 * Smoke test of the public read routes against a real WordPress, run
 * through WP-CLI: `GET /portfolio/v1/{articles,projects,pages}`, the same
 * with a slug, and `GET /portfolio/v1/sitemap` are called through the REST
 * server (routes, parameters, filters, headers, refusals) over documents
 * the script imports. It exercises Storage\WordPressContentCatalog, the one
 * class of the read side the unit suite cannot reach.
 *
 * From the repository root, with the stack up, the plugin activated and the
 * skills grid synchronized (`wp portfolio skills sync …`):
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/rest-smoke.php --user=<admin login>
 *
 * `--user` is required: the imported documents need an author. The script
 * creates zz-smoke-* posts (a published article, two projects and a page; a
 * draft, a password-protected article; an article and a page made by hand
 * without document; a German translation of the project and of the page,
 * and a German project without French original) and deletes them at the
 * end, with the tech tag it made. The routes themselves are called as an
 * anonymous visitor. The translations are imported without a language
 * gateway, so Polylang knows nothing of them: their `link` is not checked
 * here (the i18n smoke does, through the real adapter), only what the
 * plugin serves from its own metas (ADR-021).
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Rest\Controller\CollectionController;
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
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
    WP_CLI::error('Run this script with --user=<admin login>: the imported documents need an author.');
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

$adminId = get_current_user_id();
$namespace = '/' . RouteRegistrar::NAMESPACE;

/** @return WP_REST_Response */
$get = static function (string $path, array $query = []) use ($namespace): WP_REST_Response {
    $request = new WP_REST_Request('GET', $namespace . $path);
    $request->set_query_params($query);

    return rest_do_request($request);
};

$slugsOf = static fn (WP_REST_Response $response): array => array_values(array_map(
    static fn (array $item): string => (string) ($item['slug'] ?? ''),
    is_array($response->get_data()) ? $response->get_data() : [],
));

$errorCode = static fn (WP_REST_Response $response): string => (string) (($response->get_data()['code'] ?? ''));

$isNull = static fn (array $data, string $key): bool => array_key_exists($key, $data) && $data[$key] === null;

$skillGateway = new WordPressSkillTermGateway();
$skill = null;
foreach ($skillGateway->all() as $term) {
    if ($term->code !== null && $term->parentId !== 0) {
        $skill = $term;
        break;
    }
}
if ($skill === null) {
    WP_CLI::error('No competency term found: run `wp portfolio skills sync` first.');
}

$service = new ContentImportService(
    new FrontmatterParser(),
    new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
    new WordPressContentRepository(),
    $skillGateway,
    new ConflictResolver(),
    new SystemClock(),
);

$tagSlug = 'zz-smoke-tag';
$createdPosts = [];

$article = <<<'MD'
---
title: "Article de fumée (lecture)"
slug: "zz-smoke-article"
type: "article"
lang: "fr"
date: 2026-10-26
subtitle: "Sous-titre de fumée"
seo_description: "Description SEO de fumée."
featured: true
reading_time: 7
tags: [zz-smoke-tag]
---

Un paragraphe avec un `antislash \*` à garder tel quel.

MD;

$project = <<<MD
---
title: "Projet de fumée (lecture)"
slug: "zz-smoke-project"
type: "project"
lang: "fr"
date: 2026-10-25
status: "en-cours"
role: "Développeur de fumée"
period_start: 2026-01-12
period_end: ""
repo_url: "https://example.com/repo"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [zz-smoke-tag]
skills: ["{$skill->code}"]
---

Corps du projet.

MD;

// A second project, untagged and in another state: the pagination and the filters never depend on what the site already holds.
$secondProject = <<<'MD'
---
title: "Second projet de fumée (lecture)"
slug: "zz-smoke-project-2"
type: "project"
lang: "fr"
date: 2026-10-23
status: "archive"
tags: []
skills: []
---

Corps du second projet.

MD;

$page = <<<'MD'
---
title: "Page de fumée (lecture)"
slug: "zz-smoke-page"
type: "page"
lang: "fr"
date: 2026-10-24
seo_description: "Description de la page de fumée."
---

Corps de la page.

MD;

// The German translation of the project (same key, slug suffixed) and of the page (natural German slug).
$germanProject = <<<MD
---
title: "Rauchprojekt (Lesen)"
slug: "zz-smoke-project-de"
type: "project"
lang: "de"
translation_key: "zz-smoke-project"
date: 2026-10-26
status: "en-cours"
tags: [zz-smoke-tag]
skills: ["{$skill->code}"]
---

Text des Projekts.

MD;

$germanPage = <<<'MD'
---
title: "Rauchseite (Lesen)"
slug: "zz-smoke-seite"
type: "page"
lang: "de"
translation_key: "zz-smoke-page"
date: 2026-10-24
---

Text der Seite.

MD;

// A German project whose French original does not exist: the anomaly `translations reconcile` reports.
$orphan = <<<'MD'
---
title: "Waise (Lesen)"
slug: "zz-smoke-waise-de"
type: "project"
lang: "de"
translation_key: "zz-smoke-waise"
date: 2026-10-22
status: "prototype"
tags: []
skills: []
---

Ohne französisches Original.

MD;

$import = static function (string $document, ImportOptions $options) use ($service, &$createdPosts, $check): ?int {
    $result = $service->import($document, $options);
    if ($result->postId !== null) {
        $createdPosts[] = $result->postId;
    }
    $check("the fixture \"{$result->slug}\" is imported", $result->outcome === ImportOutcome::Created && $result->postId !== null, $result->outcome->value . ' ' . $result->detail);

    return $result->postId;
};

$handMade = static function (string $postType, string $slug) use (&$createdPosts, $check): int {
    $id = wp_insert_post(['post_type' => $postType, 'post_status' => 'publish', 'post_title' => "Fait main {$slug}", 'post_name' => $slug, 'post_content' => '<p>Sans document.</p>'], true);
    if ($id instanceof WP_Error) {
        $check("a hand-made {$postType} can be created", false, $id->get_error_message());

        return 0;
    }
    $createdPosts[] = (int) $id;

    return (int) $id;
};

try {
    // 0. The fixtures -----------------------------------------------------------------------------------

    $articleId = $import($article, new ImportOptions());
    $projectId = $import($project, new ImportOptions());
    $import($secondProject, new ImportOptions());
    $pageId = $import($page, new ImportOptions());
    $import(str_replace(['zz-smoke-article', 'Article de fumée (lecture)'], ['zz-smoke-draft', 'Brouillon de fumée'], $article), new ImportOptions(postStatus: 'draft'));
    $secretId = $import(str_replace(['zz-smoke-article', 'Article de fumée (lecture)'], ['zz-smoke-secret', 'Secret de fumée'], $article), new ImportOptions());
    if ($secretId !== null) {
        wp_update_post(['ID' => $secretId, 'post_password' => 'zz-smoke']);
    }
    $handMade(PostTypeRegistrar::ARTICLE, 'zz-smoke-handmade');
    $handMade(PostTypeRegistrar::PAGE, 'zz-smoke-handmade-page');

    // 1. The routes -------------------------------------------------------------------------------------

    $routes = rest_get_server()->get_routes(RouteRegistrar::NAMESPACE);
    foreach (['/articles', '/projects', '/pages', '/sitemap'] as $route) {
        $check("the route {$route} is registered under the plugin namespace", isset($routes[$namespace . $route]) && isset($routes[$namespace . $route][0]['methods']['GET']));
    }
    $check('the three document routes are registered', count(array_filter(array_keys($routes), static fn (string $route): bool => str_contains($route, '(?P<slug>'))) === 3);

    // Everything below is asked as an anonymous visitor.
    wp_set_current_user(0);

    // 2. The lists --------------------------------------------------------------------------------------

    $response = $get('/articles');
    $slugs = $slugsOf($response);
    $check('GET /articles answers 200 with a list', $response->get_status() === 200 && is_array($response->get_data()) && array_is_list($response->get_data()), 'status ' . $response->get_status());
    $check('the published article is listed', in_array('zz-smoke-article', $slugs, true), implode(',', $slugs));
    $check('the draft is not', !in_array('zz-smoke-draft', $slugs, true));
    $check('nor the password-protected article', !in_array('zz-smoke-secret', $slugs, true));
    $check('nor the article made by hand without document', !in_array('zz-smoke-handmade', $slugs, true));
    $headers = $response->get_headers();
    $check('the totals are in the headers', isset($headers['X-WP-Total'], $headers['X-WP-TotalPages']) && ctype_digit((string) $headers['X-WP-Total']) && (int) $headers['X-WP-Total'] >= 1, json_encode($headers));
    $check('and the page holds min(total, per_page) items', count($slugs) === min((int) ($headers['X-WP-Total'] ?? 0), 10), count($slugs) . ' items');

    $response = $get('/projects', ['per_page' => '1']);
    $headers = $response->get_headers();
    $total = (int) ($headers['X-WP-Total'] ?? 0);
    $check('GET /projects?per_page=1 lists one project', $response->get_status() === 200 && count($slugsOf($response)) === 1 && $total >= 2, "total {$total}");
    $check('with as many pages as projects', (int) ($headers['X-WP-TotalPages'] ?? -1) === $total);
    $check('and a Link header to the next page only', isset($headers['Link']) && str_contains($headers['Link'], 'rel="next"') && !str_contains($headers['Link'], 'rel="prev"') && str_contains($headers['Link'], 'page=2') && str_contains($headers['Link'], 'per_page=1'), (string) ($headers['Link'] ?? 'no Link'));

    $response = $get('/projects', ['per_page' => '1', 'page' => '2']);
    $headers = $response->get_headers();
    $check('the second page links the previous and the next', isset($headers['Link']) && str_contains($headers['Link'], 'page=1&per_page=1>; rel="prev"') && ($total === 2 || str_contains($headers['Link'], 'page=3&per_page=1>; rel="next"')), (string) ($headers['Link'] ?? 'no Link'));

    $response = $get('/projects', ['per_page' => '1', 'page' => (string) ($total + 1)]);
    $check('a page beyond the last answers 400 like the native collections', $response->get_status() === 400 && $errorCode($response) === CollectionController::INVALID_PAGE, 'status ' . $response->get_status() . ' ' . $errorCode($response));
    $response = $get('/projects', ['per_page' => '100', 'page' => '2']);
    $check('even when the first page would have held everything', $total <= 100 && $response->get_status() === 400 && $errorCode($response) === CollectionController::INVALID_PAGE, 'status ' . $response->get_status());
    $response = $get('/projects', ['tag' => 'zz-smoke-nobody', 'page' => '2']);
    $check('but any page of an emptied collection is an empty list, like the native collections', $response->get_status() === 200 && $response->get_data() === [] && (string) ($response->get_headers()['X-WP-Total'] ?? '') === '0' && !isset($response->get_headers()['Link']), 'status ' . $response->get_status() . ' ' . json_encode($response->get_headers()));

    $newest = $slugsOf($get('/projects', ['per_page' => '100']));
    $dates = array_map(static fn (array $item): string => (string) $item['date'], $get('/projects', ['per_page' => '100'])->get_data());
    $sorted = $dates;
    rsort($sorted);
    $check('the projects come newest first', $dates === $sorted && in_array('zz-smoke-project', $newest, true));

    // 3. The parameter checks of the REST server ------------------------------------------------------

    foreach ([['per_page' => '0'], ['per_page' => '101'], ['page' => '0'], ['page' => 'two'], ['tag' => 'Not a slug'], ['status' => 'bogus'], ['skill' => 'A/B']] as $bad) {
        $response = $get('/projects', $bad);
        $check('the server rejects ' . http_build_query($bad) . ' with 400', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param', 'status ' . $response->get_status() . ' ' . $errorCode($response));
    }

    // 4. The filters ------------------------------------------------------------------------------------

    $check('GET /articles?tag= lists the tagged article only', $slugsOf($get('/articles', ['tag' => $tagSlug])) === ['zz-smoke-article']);
    $check('GET /projects?tag= lists the tagged project only', $slugsOf($get('/projects', ['tag' => $tagSlug])) === ['zz-smoke-project']);
    $check('GET /projects?status=en-cours lists the project', in_array('zz-smoke-project', $slugsOf($get('/projects', ['status' => 'en-cours', 'per_page' => '100'])), true));
    $archived = $slugsOf($get('/projects', ['status' => 'archive', 'per_page' => '100']));
    $check('GET /projects?status=archive lists the archived one and not the other', in_array('zz-smoke-project-2', $archived, true) && !in_array('zz-smoke-project', $archived, true), implode(',', $archived));
    $check('two filters combine with AND', $slugsOf($get('/projects', ['tag' => $tagSlug, 'status' => 'archive'])) === [] && $slugsOf($get('/projects', ['tag' => $tagSlug, 'status' => 'en-cours'])) === ['zz-smoke-project']);
    $check('GET /projects?skill= lists the projects that demonstrate the competency', in_array('zz-smoke-project', $slugsOf($get('/projects', ['skill' => $skill->slug, 'per_page' => '100'])), true));
    $referential = get_term($skill->parentId, TaxonomyRegistrar::SKILL);
    $check('the slug of its referential lists them too', $referential instanceof WP_Term && in_array('zz-smoke-project', $slugsOf($get('/projects', ['skill' => $referential->slug, 'per_page' => '100'])), true));
    $check('a filter that matches no term is an empty list, not an error', $get('/projects', ['tag' => 'zz-smoke-nobody'])->get_status() === 200 && $slugsOf($get('/projects', ['tag' => 'zz-smoke-nobody'])) === []);
    $check('a filter a collection does not declare is ignored', in_array('zz-smoke-article', $slugsOf($get('/articles', ['status' => 'archive', 'skill' => $skill->slug])), true));

    // 5. The documents ----------------------------------------------------------------------------------

    $response = $get('/articles/zz-smoke-article');
    $data = $response->get_data();
    $check('GET /articles/{slug} answers 200', $response->get_status() === 200 && is_array($data), 'status ' . $response->get_status());
    $check('with the keys of the contract, in order, the Markdown last', array_keys($data) === ['id', 'type', 'slug', 'lang', 'translation_key', 'translations', 'is_fallback', 'requested_language', 'title', 'link', 'date', 'updated', 'tags', 'subtitle', 'seo_description', 'reading_time', 'featured', 'markdown'], implode(',', array_keys($data)));
    $check('the id, type, slug and title are the post\'s', ($data['id'] ?? null) === $articleId && ($data['type'] ?? null) === 'article' && ($data['slug'] ?? null) === 'zz-smoke-article' && ($data['title'] ?? null) === 'Article de fumée (lecture)');
    $check('the link is the permalink', ($data['link'] ?? null) === get_permalink($articleId), (string) ($data['link'] ?? ''));
    $expectedDate = get_post_datetime($articleId, 'date', 'gmt');
    $check('the date is the publication instant in UTC, ISO 8601 with a Z', $expectedDate !== false && ($data['date'] ?? null) === $expectedDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $data['date']) === 1, (string) ($data['date'] ?? ''));
    $check('and it is the frontmatter day at midnight in the site timezone', $expectedDate !== false && $expectedDate->format('Y-m-d H:i:s') === '2026-10-26 00:00:00', $expectedDate === false ? 'no date' : $expectedDate->format('c'));
    $check('the modification instant has the same shape', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($data['updated'] ?? '')) === 1);
    $check('the tags are objects {slug, name}', ($data['tags'] ?? null) === [['slug' => $tagSlug, 'name' => $tagSlug]], json_encode($data['tags'] ?? null));
    $check('the texts and numbers are typed', ($data['subtitle'] ?? null) === 'Sous-titre de fumée' && ($data['seo_description'] ?? null) === 'Description SEO de fumée.' && ($data['reading_time'] ?? null) === 7 && ($data['featured'] ?? null) === true);
    $check('the Markdown is the document, verbatim', ($data['markdown'] ?? null) === $article);
    $check('and nothing internal leaks (hash, provenance, HTML)', !isset($data['hash'], $data['source'], $data['content'], $data['html']) && !str_contains(json_encode($data), '<!-- wp:'));

    $response = $get('/projects/zz-smoke-project');
    $data = $response->get_data();
    $check('GET /projects/{slug} answers 200', $response->get_status() === 200 && is_array($data), 'status ' . $response->get_status());
    $check('with the project fields between the common ones and the Markdown', array_keys($data) === ['id', 'type', 'slug', 'lang', 'translation_key', 'translations', 'is_fallback', 'requested_language', 'title', 'link', 'date', 'updated', 'tags', 'subtitle', 'seo_description', 'reading_time', 'featured', 'status', 'skills', 'is_fil_rouge', 'role', 'period_start', 'period_end', 'repo_url', 'demo_url', 'client', 'markdown'], implode(',', array_keys($data)));
    $check('the status is an object {slug, label}', ($data['status'] ?? null) === ['slug' => 'en-cours', 'label' => 'En cours'], json_encode($data['status'] ?? null));
    $check('the skills are objects {slug, code, label} with the official code', ($data['skills'] ?? null) === [['slug' => $skill->slug, 'code' => $skill->code, 'label' => $skill->name]], json_encode($data['skills'] ?? null));
    $check('the empty optional fields are null', $isNull($data, 'subtitle') && $isNull($data, 'reading_time') && $isNull($data, 'period_end') && $isNull($data, 'demo_url') && $isNull($data, 'client') && $isNull($data, 'seo_description'), json_encode(array_intersect_key($data, array_flip(['subtitle', 'reading_time', 'period_end', 'demo_url', 'client', 'seo_description']))));
    $check('the filled ones are typed', ($data['is_fil_rouge'] ?? null) === false && ($data['role'] ?? null) === 'Développeur de fumée' && ($data['period_start'] ?? null) === '2026-01-12' && ($data['repo_url'] ?? null) === 'https://example.com/repo' && ($data['featured'] ?? null) === false);

    $response = $get('/pages/zz-smoke-page');
    $data = $response->get_data();
    $check('GET /pages/{slug} answers 200 with the common fields only', $response->get_status() === 200 && array_keys($data) === ['id', 'type', 'slug', 'lang', 'translation_key', 'translations', 'is_fallback', 'requested_language', 'title', 'link', 'date', 'updated', 'seo_description', 'markdown'], implode(',', array_keys((array) $data)));
    $check('and the page\'s values', ($data['id'] ?? null) === $pageId && ($data['type'] ?? null) === 'page' && ($data['seo_description'] ?? null) === 'Description de la page de fumée.' && ($data['markdown'] ?? null) === $page);
    $check('GET /pages lists the documented page and not the hand-made one', in_array('zz-smoke-page', $slugsOf($get('/pages', ['per_page' => '100'])), true) && !in_array('zz-smoke-handmade-page', $slugsOf($get('/pages', ['per_page' => '100'])), true));

    // 6. What is not served -----------------------------------------------------------------------------

    foreach (['/articles/zz-smoke-draft' => 'a draft', '/articles/zz-smoke-secret' => 'a password-protected post', '/articles/zz-smoke-handmade' => 'a post made by hand without document', '/pages/zz-smoke-handmade-page' => 'a page made by hand', '/articles/zz-smoke-project' => 'a slug of another kind', '/projects/zz-smoke-nobody' => 'an unknown slug'] as $path => $what) {
        $response = $get($path);
        $check("{$what} answers 404 with the plugin code", $response->get_status() === 404 && $errorCode($response) === CollectionController::NOT_FOUND, 'status ' . $response->get_status() . ' ' . $errorCode($response));
    }
    // The server matches routes case-insensitively, so the capitals reach the slug parameter, whose schema refuses them.
    $response = $get('/articles/Zz-Smoke');
    $check('a slug with capitals is refused by the slug schema with 400', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param', 'status ' . $response->get_status() . ' ' . $errorCode($response));
    $response = $get('/articles/zz_smoke');
    $check('a slug with an underscore matches no route', $response->get_status() === 404 && $errorCode($response) === 'rest_no_route', 'status ' . $response->get_status() . ' ' . $errorCode($response));

    // 7. The sitemap ------------------------------------------------------------------------------------

    $response = $get('/sitemap');
    $data = $response->get_data();
    $check('GET /sitemap answers 200 with one list per collection', $response->get_status() === 200 && is_array($data) && array_keys($data) === ['articles', 'projects', 'pages'], implode(',', array_keys((array) $data)));
    $entries = static fn (string $collection): array => array_column($data[$collection] ?? [], 'updated', 'slug');
    $check('the article, the project and the page are in their lists', isset($entries('articles')['zz-smoke-article'], $entries('projects')['zz-smoke-project'], $entries('pages')['zz-smoke-page']));
    $check('the draft, the protected and the hand-made posts are not', !isset($entries('articles')['zz-smoke-draft'], $entries('articles')['zz-smoke-secret'], $entries('articles')['zz-smoke-handmade'], $entries('pages')['zz-smoke-handmade-page']));
    $check('each line is {slug, updated, lang, translation_key}', array_keys($data['articles'][0] ?? []) === ['slug', 'updated', 'lang', 'translation_key']);
    $projectSlugs = array_keys($entries('projects'));
    $sortedSlugs = $projectSlugs;
    sort($sortedSlugs, SORT_STRING);
    $check('the lines of a collection come in slug order', $projectSlugs === $sortedSlugs && count($projectSlugs) >= 2, implode(',', $projectSlugs));
    $check('and the instant is the one of the document route', ($entries('articles')['zz-smoke-article'] ?? null) === ($get('/articles/zz-smoke-article')->get_data()['updated'] ?? null));

    // 8. The server's own conveniences ------------------------------------------------------------------

    // Over HTTP the server applies this filter itself, on `rest_post_dispatch` in serve_request(); rest_do_request() stops before it.
    $request = new WP_REST_Request('GET', $namespace . '/articles');
    $request->set_query_params(['_fields' => 'slug,title']);
    $response = rest_filter_response_fields(rest_do_request($request), rest_get_server(), $request);
    $check('_fields narrows every item of a list (the server does it for any route)', array_keys($response->get_data()[0] ?? []) === ['slug', 'title'], json_encode($response->get_data()[0] ?? null));
    $request = new WP_REST_Request('GET', $namespace . '/projects/zz-smoke-project');
    $request->set_query_params(['_fields' => 'slug,status']);
    $response = rest_filter_response_fields(rest_do_request($request), rest_get_server(), $request);
    $check('and a document', array_keys((array) $response->get_data()) === ['slug', 'status'], json_encode($response->get_data()));

    wp_set_current_user($adminId);
    $check('a logged-in user gets the same answer', $get('/articles/zz-smoke-article')->get_status() === 200);

    // 9. The languages (ADR-021) ------------------------------------------------------------------------

    $germanProjectId = $import($germanProject, new ImportOptions());
    $germanPageId = $import($germanPage, new ImportOptions());
    $import($orphan, new ImportOptions());
    wp_set_current_user(0);

    foreach (['xx', '', 'DE', ['de']] as $bad) {
        $shown = is_array($bad) ? 'language[]=de' : "language={$bad}";
        $response = $get('/projects', ['language' => $bad]);
        $check("the list refuses {$shown} with 400 from the schema", $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param', 'status ' . $response->get_status() . ' ' . $errorCode($response));
        $response = $get('/projects/zz-smoke-project', ['language' => $bad]);
        $check("so does the document route for {$shown}", $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param', 'status ' . $response->get_status() . ' ' . $errorCode($response));
    }

    $bySlug = static fn (WP_REST_Response $response): array => array_column(is_array($response->get_data()) ? $response->get_data() : [], null, 'slug');
    $french = $get('/projects', ['per_page' => '100', 'language' => 'fr']);
    $german = $get('/projects', ['per_page' => '100', 'language' => 'de']);
    $english = $get('/projects', ['per_page' => '100', 'language' => 'en']);
    $bare = $get('/projects', ['per_page' => '100']);
    $check('an absent language is French: the same list as language=fr', $bare->get_data() === $french->get_data() && $bare->get_headers()['X-WP-Total'] === $french->get_headers()['X-WP-Total']);
    $check('a German list has the total and the size of the French one', $german->get_headers()['X-WP-Total'] === $french->get_headers()['X-WP-Total'] && count($german->get_data()) === count($french->get_data()), json_encode([$french->get_headers()['X-WP-Total'] ?? null, $german->get_headers()['X-WP-Total'] ?? null]));
    $germanItems = $bySlug($german);
    $check('the translated project is replaced by its German sheet', isset($germanItems['zz-smoke-project-de']) && !isset($germanItems['zz-smoke-project']) && $germanItems['zz-smoke-project-de']['lang'] === 'de' && $germanItems['zz-smoke-project-de']['is_fallback'] === false && $germanItems['zz-smoke-project-de']['requested_language'] === 'de' && $germanItems['zz-smoke-project-de']['translation_key'] === 'zz-smoke-project', implode(',', array_keys($germanItems)));
    $check('its translations map says the slugs by language, French first', (array) ($germanItems['zz-smoke-project-de']['translations'] ?? []) === ['fr' => 'zz-smoke-project', 'de' => 'zz-smoke-project-de']);
    $check('the untranslated project is served in French and flagged', isset($germanItems['zz-smoke-project-2']) && $germanItems['zz-smoke-project-2']['lang'] === 'fr' && $germanItems['zz-smoke-project-2']['is_fallback'] === true && $germanItems['zz-smoke-project-2']['requested_language'] === 'de' && (array) $germanItems['zz-smoke-project-2']['translations'] === ['fr' => 'zz-smoke-project-2']);
    $check('the German list keeps the order of the French one', array_keys($germanItems) === array_map(static fn (string $slug): string => $slug === 'zz-smoke-project' ? 'zz-smoke-project-de' : $slug, array_keys($bySlug($french))));
    $check('a document without French original is in no list', !isset($germanItems['zz-smoke-waise-de']) && !isset($bySlug($french)['zz-smoke-waise-de']) && !isset($bySlug($english)['zz-smoke-waise-de']));
    $check('an English reader gets the French sheet flagged, requested_language en', ($bySlug($english)['zz-smoke-project']['is_fallback'] ?? null) === true && ($bySlug($english)['zz-smoke-project']['requested_language'] ?? null) === 'en' && ($bySlug($english)['zz-smoke-project']['lang'] ?? null) === 'fr');
    $check('the tag filter applies to the French document: the German sheet comes out for the French tag', $slugsOf($get('/projects', ['tag' => $tagSlug, 'language' => 'de'])) === ['zz-smoke-project-de']);

    $response = $get('/projects', ['per_page' => '1', 'language' => 'de']);
    $check('the Link header carries the language when the request did', str_contains((string) ($response->get_headers()['Link'] ?? ''), 'page=2&per_page=1&language=de>; rel="next"'), (string) ($response->get_headers()['Link'] ?? 'no Link'));
    $response = $get('/projects', ['per_page' => '1']);
    $check('and not when it did not', isset($response->get_headers()['Link']) && !str_contains((string) $response->get_headers()['Link'], 'language'), (string) ($response->get_headers()['Link'] ?? 'no Link'));

    $data = $get('/projects/zz-smoke-project', ['language' => 'de'])->get_data();
    $check('GET /projects/{slug}?language=de serves the German sheet of the group', ($data['slug'] ?? null) === 'zz-smoke-project-de' && ($data['id'] ?? null) === $germanProjectId && $data['lang'] === 'de' && $data['is_fallback'] === false, json_encode(array_intersect_key((array) $data, array_flip(['slug', 'lang', 'is_fallback']))));
    $check('with the skills and the status of the German sheet', ($data['status']['slug'] ?? null) === 'en-cours' && ($data['skills'][0]['code'] ?? null) === $skill->code, json_encode([$data['status'] ?? null, $data['skills'] ?? null]));
    $data = $get('/projects/zz-smoke-project-de', ['language' => 'fr'])->get_data();
    $check('the German slug asked in French answers the French sheet, no fallback', ($data['slug'] ?? null) === 'zz-smoke-project' && $data['is_fallback'] === false && $data['requested_language'] === 'fr');
    $data = $get('/projects/zz-smoke-project', ['language' => 'en'])->get_data();
    $check('asked in English, the French sheet is served as a fallback', ($data['slug'] ?? null) === 'zz-smoke-project' && $data['lang'] === 'fr' && $data['is_fallback'] === true && $data['requested_language'] === 'en');
    $data = $get('/projects/zz-smoke-project-2', ['language' => 'de'])->get_data();
    $check('a group of one is served as itself, flagged', ($data['slug'] ?? null) === 'zz-smoke-project-2' && $data['is_fallback'] === true && (array) $data['translations'] === ['fr' => 'zz-smoke-project-2']);
    $response = $get('/projects/zz-smoke-waise-de', ['language' => 'fr']);
    $data = $response->get_data();
    $check('a document without French original is served under its slug, flagged, never 404', $response->get_status() === 200 && ($data['lang'] ?? null) === 'de' && $data['is_fallback'] === true && $data['requested_language'] === 'fr' && (array) $data['translations'] === ['de' => 'zz-smoke-waise-de'], 'status ' . $response->get_status());
    $response = $get('/projects/zz-smoke-nobody', ['language' => 'de']);
    $check('an unknown slug is still 404 whatever the language', $response->get_status() === 404 && $errorCode($response) === CollectionController::NOT_FOUND);
    $data = $get('/pages/zz-smoke-page', ['language' => 'de'])->get_data();
    $check('a page is translated the same way, under its natural German slug', ($data['slug'] ?? null) === 'zz-smoke-seite' && ($data['id'] ?? null) === $germanPageId && $data['translation_key'] === 'zz-smoke-page' && array_keys((array) $data['translations']) === ['fr', 'de']);
    $pages = $slugsOf($get('/pages', ['per_page' => '100', 'language' => 'de']));
    $check('GET /pages?language=de lists the German page in place of the French one', in_array('zz-smoke-seite', $pages, true) && !in_array('zz-smoke-page', $pages, true), implode(',', $pages));
    $check('a translation is never an anchor: the German slugs are in no French list', !in_array('zz-smoke-seite', $slugsOf($get('/pages', ['per_page' => '100'])), true) && !isset($bySlug($french)['zz-smoke-project-de']));

    $check('Polylang\'s lang parameter changes nothing on our routes', $get('/projects/zz-smoke-project', ['lang' => 'de'])->get_data() === $get('/projects/zz-smoke-project')->get_data());
    $data = $get('/projects/zz-smoke-project', ['lang' => 'fr', 'language' => 'de'])->get_data();
    $check('and language alone drives when both are given', ($data['slug'] ?? null) === 'zz-smoke-project-de');

    $data = $get('/sitemap')->get_data();
    $lines = array_column($data['projects'] ?? [], null, 'slug');
    $check('the sitemap holds every language with its key, the orphan included', ($lines['zz-smoke-project']['lang'] ?? null) === 'fr' && ($lines['zz-smoke-project']['translation_key'] ?? null) === 'zz-smoke-project' && ($lines['zz-smoke-project-de']['lang'] ?? null) === 'de' && ($lines['zz-smoke-project-de']['translation_key'] ?? null) === 'zz-smoke-project' && ($lines['zz-smoke-waise-de']['translation_key'] ?? null) === 'zz-smoke-waise', implode(',', array_keys($lines)));
    $check('the sitemap ignores a language parameter', $get('/sitemap', ['language' => 'de'])->get_data() === $data);

    $documented = get_posts(['post_type' => [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT, PostTypeRegistrar::PAGE], 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'meta_query' => [['key' => InternalMetaRegistrar::MD_HASH, 'value' => '', 'compare' => '!=']]]);
    $lacking = array_values(array_filter($documented, static fn (int $id): bool => get_post_meta($id, InternalMetaRegistrar::LANG, true) === '' || get_post_meta($id, InternalMetaRegistrar::TRANSLATION_KEY, true) === ''));
    $check('every published document stores its language and its key (otherwise the API hides it: import it again)', $lacking === [], implode(',', $lacking));

    global $wpdb;
    $queries = static function (callable $call) use ($wpdb): int {
        wp_cache_flush();
        $before = (int) $wpdb->num_queries;
        $call();

        return (int) $wpdb->num_queries - $before;
    };
    $one = $queries(static fn () => $get('/projects', ['per_page' => '1', 'language' => 'de']));
    $all = $queries(static fn () => $get('/projects', ['per_page' => '100', 'language' => 'de']));
    // One page is a fixed number of queries whatever its size: the posts, their metas and terms, their
    // groups, the terms and metas of the translations served in place of a French sheet, and the term metas
    // (skill codes), each read in one query for the whole page. Measured on the local stack: 11 queries
    // for one project, 13 for fourteen with a German twin served. Anything per post would add one query
    // per project and fail this bound.
    $check("a page of every project costs about as many queries as a page of one ({$one}, then {$all})", $all <= $one + 6, "{$one} queries for one, {$all} for all");
} finally {
    wp_set_current_user($adminId);
    foreach ($createdPosts as $postId) {
        wp_delete_post($postId, true);
    }
    $tag = get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG);
    if ($tag instanceof WP_Term) {
        wp_delete_term($tag->term_id, TaxonomyRegistrar::TECH_TAG);
    }
}

$left = count(get_posts(['post_type' => [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT, PostTypeRegistrar::PAGE], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 's' => 'fumée (lecture)']))
    + count(get_posts(['post_type' => [PostTypeRegistrar::PROJECT, PostTypeRegistrar::PAGE], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 's' => '(Lesen)']))
    + count(get_posts(['post_type' => [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PAGE], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'name' => 'zz-smoke-handmade']))
    + count(get_posts(['post_type' => PostTypeRegistrar::PAGE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'name' => 'zz-smoke-handmade-page']));
$check('the smoke posts are cleaned up', $left === 0, "{$left} left");
$check('the smoke tag is cleaned up', get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG) === false);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');

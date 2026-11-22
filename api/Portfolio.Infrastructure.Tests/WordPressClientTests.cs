using System.Diagnostics;
using System.Text.Encodings.Web;
using System.Text.Json;
using System.Text.Json.Nodes;
using Portfolio.Application;
using Portfolio.Application.Documents;
using Portfolio.Application.Ports;
using Portfolio.Infrastructure.Tests.Support;
using Portfolio.Infrastructure.WordPress;

namespace Portfolio.Infrastructure.Tests;

/// <summary>
/// The client of the plugin (ADR-026, points 2 and 7), against the answers the
/// plugin gave in production on 2026-11-22 and against the failures it can have.
/// </summary>
public sealed class WordPressClientTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task The_sitemap_gives_one_line_per_published_document_of_every_collection()
    {
        var sitemap = (await FakePlugin.Recorded().Client().GetSitemapAsync(Token)).AssertFetched();

        Assert.Equal(54, sitemap.Lines.Count);
        Assert.Equal(42, sitemap.Lines.Count(line => line.Collection == CollectionKind.Project));
        Assert.Equal(12, sitemap.Lines.Count(line => line.Collection == CollectionKind.Page));
        Assert.DoesNotContain(sitemap.Lines, line => line.Collection == CollectionKind.Article);
        Assert.Contains(
            new SitemapLine(CollectionKind.Project, "momocad-de", Language.De, "momocad", new DateTimeOffset(2026, 10, 25, 23, 0, 0, TimeSpan.Zero)),
            sitemap.Lines);
    }

    [Fact]
    public async Task A_list_comes_whole_in_the_plugin_s_order_and_in_the_language_asked()
    {
        var projects = (await FakePlugin.Recorded().Client().ListAsync(CollectionKind.Project, Language.De, Token)).AssertFetched();

        Assert.Equal(14, projects.Count);
        Assert.Equal<string>(["xvii-de", "vefta-de", "uha-learn-de"], projects.Take(3).Select(project => project.Slug));
        Assert.All(projects, project => Assert.IsType<Project>(project));
        Assert.All(projects, project => Assert.Equal(Language.De, project.RequestedLanguage));
    }

    [Fact]
    public async Task A_project_keeps_every_field_the_plugin_sends()
    {
        var projects = (await FakePlugin.Recorded().Client().ListAsync(CollectionKind.Project, Language.Fr, Token)).AssertFetched();

        var momocad = Assert.IsType<Project>(Assert.Single(projects, project => project.Slug == "momocad"));
        Assert.Equal(13, momocad.WordPressId);
        Assert.Equal(Language.Fr, momocad.Lang);
        Assert.Equal("momocad", momocad.TranslationKey);
        Assert.Equal(3, momocad.Translations.Count);
        Assert.Equal("momocad", momocad.Translations[Language.Fr]);
        Assert.Equal("momocad-de", momocad.Translations[Language.De]);
        Assert.Equal("momocad-en", momocad.Translations[Language.En]);
        Assert.Equal(Language.Fr, momocad.RequestedLanguage);
        Assert.False(momocad.IsFallback);
        Assert.Equal("MomoCad", momocad.Title);
        Assert.Equal("https://morganvoltz.dev/fr/projets/momocad/", momocad.CanonicalUrl);
        Assert.Equal(new DateTimeOffset(2026, 10, 25, 23, 0, 0, TimeSpan.Zero), momocad.Date);
        Assert.Equal(new DateTimeOffset(2026, 10, 25, 23, 0, 0, TimeSpan.Zero), momocad.Updated);
        Assert.StartsWith("MomoCad, projet fil rouge UHA 4.0.2", momocad.SeoDescription);
        Assert.StartsWith("---\ntitle: \"MomoCad\"", momocad.Markdown);
        Assert.Equal("", momocad.Html);
        Assert.Equal(10, momocad.Tags.Count);
        Assert.Equal(new Tag("dotnet", ".NET"), momocad.Tags[0]);
        Assert.Equal("Un logiciel de dessin 2D façon CAO dont l'état n'est qu'un journal d'événements rejoué", momocad.Subtitle);
        Assert.Null(momocad.ReadingTime);
        Assert.True(momocad.Featured);
        Assert.Equal(new Status(ProjectStatus.EnCours, "En cours"), momocad.Status);
        Assert.Equal(8, momocad.Skills.Count);
        Assert.Equal(new Skill("e00-specifier-un-mvp-en-uml", "4.0.2/E00", "Spécifier un MVP avec UML et des user stories", Official: false), momocad.Skills[0]);
        Assert.True(momocad.IsFilRouge);
        Assert.Equal("Conception, développement, tests et documentation, en solo", momocad.Role);
        Assert.Equal(new DateOnly(2026, 9, 4), momocad.PeriodStart);
        Assert.Null(momocad.PeriodEnd);
        Assert.Null(momocad.RepoUrl);
        Assert.Null(momocad.DemoUrl);
        Assert.Null(momocad.Client);
    }

    [Fact]
    public async Task Pages_are_read_and_an_empty_collection_gives_an_empty_list()
    {
        var client = FakePlugin.Recorded().Client();

        var pages = (await client.ListAsync(CollectionKind.Page, Language.En, Token)).AssertFetched();
        var articles = (await client.ListAsync(CollectionKind.Article, Language.Fr, Token)).AssertFetched();

        Assert.Equal<string>(["get-in-touch", "home", "resume", "about"], pages.Select(page => page.Slug));
        Assert.All(pages, page => Assert.IsType<Page>(page));
        Assert.Empty(articles);
    }

    [Fact]
    public async Task A_page_keeps_its_headline_and_subtitle_and_null_when_its_front_matter_has_none()
    {
        var client = FakePlugin.Recorded().Client();

        var pages = (await client.ListAsync(CollectionKind.Page, Language.En, Token)).AssertFetched();

        var home = Assert.IsType<Page>(pages.Single(page => page.Slug == "home"));
        Assert.Equal("Home", home.Title);
        Assert.Equal("From wiring cabinets to code that holds up.", home.Headline);
        Assert.Equal("Full-stack developer · Rust, C#, PHP · looking for an internship from January 2027", home.Subtitle);
        var about = Assert.IsType<Page>(pages.Single(page => page.Slug == "about"));
        Assert.Null(about.Headline);
        Assert.Null(about.Subtitle);
    }

    [Fact]
    public async Task The_client_asks_with_language_page_and_per_page_only_and_says_who_it_is()
    {
        var plugin = FakePlugin.Recorded();

        await plugin.Client().ListAsync(CollectionKind.Project, Language.De, Token);

        var request = Assert.Single(plugin.Requests);
        Assert.Equal(HttpMethod.Get, request.Method);
        Assert.Equal("https://morganvoltz.dev/wp-json/portfolio/v1/projects?language=de&page=1&per_page=100", request.Uri.AbsoluteUri);
        Assert.Equal(WordPressClient.UserAgent, request.UserAgent);
        Assert.Equal("application/json", request.Accept);
    }

    [Fact]
    public async Task A_list_longer_than_a_page_is_read_page_after_page()
    {
        var plugin = FakePlugin.Answering(request => request.RequestUri!.Query switch
        {
            "?language=fr&page=1&per_page=100" => RecordedAnswers.List(PagesJson(first: 1, count: 100), total: 150, totalPages: 2),
            "?language=fr&page=2&per_page=100" => RecordedAnswers.List(PagesJson(first: 101, count: 50), total: 150, totalPages: 2),
            var other => throw new InvalidOperationException($"Unexpected request {other}."),
        });

        var pages = (await plugin.Client().ListAsync(CollectionKind.Page, Language.Fr, Token)).AssertFetched();

        Assert.Equal(Enumerable.Range(1, 150).Select(number => $"page-{number}"), pages.Select(page => page.Slug));
        Assert.Equal(2, plugin.Requests.Count);
    }

    [Fact]
    public async Task A_list_that_changes_between_two_pages_is_unavailable()
    {
        // A document published between the two reads moves the others one rank down: the
        // second page would repeat the last document of the first.
        var plugin = FakePlugin.Answering(request => request.RequestUri!.Query.Contains("&page=1&")
            ? RecordedAnswers.List(PagesJson(first: 1, count: 100), total: 150, totalPages: 2)
            : RecordedAnswers.List(PagesJson(first: 100, count: 51), total: 151, totalPages: 2));

        var reason = (await plugin.Client().ListAsync(CollectionKind.Page, Language.Fr, Token)).AssertUnavailable();

        Assert.Equal(
            "the list changed while it was being read: X-WP-Total went from 150 to 151 (GET pages?language=fr&page=2&per_page=100)",
            reason);
    }

    [Fact]
    public async Task A_list_that_falls_short_of_its_announced_total_is_unavailable()
    {
        var plugin = FakePlugin.Answering(_ => RecordedAnswers.List(PagesJson(first: 1, count: 2), total: 3, totalPages: 1));

        var reason = (await plugin.Client().ListAsync(CollectionKind.Page, Language.Fr, Token)).AssertUnavailable();

        Assert.Equal(
            "the list changed while it was being read: 2 documents read where X-WP-Total announced 3 (GET pages?language=fr&page=1&per_page=100)",
            reason);
    }

    [Fact]
    public async Task A_number_of_pages_that_the_total_does_not_explain_is_unexpected_and_not_followed()
    {
        // Followed, a header gone wrong would make the client ask for thousands of pages that cannot exist.
        var plugin = FakePlugin.Answering(_ => RecordedAnswers.List(PagesJson(first: 1, count: 100), total: 150, totalPages: 5000));

        var reason = (await plugin.Client().ListAsync(CollectionKind.Page, Language.Fr, Token)).AssertUnavailable();

        Assert.EndsWith("X-WP-TotalPages is 5000 where X-WP-Total 150 makes 2 pages of 100", reason);
        Assert.Single(plugin.Requests);
    }

    [Theory]
    [InlineData("ISO-8859-1")]
    [InlineData("not-a-real-charset")]
    public async Task A_json_answer_is_read_as_utf8_whatever_charset_its_content_type_names(string charset)
    {
        // JSON is UTF-8 by definition. WordPress escapes every accent ("fa\u00e7on"), which hides the
        // question: here the same answer carries "façon" in plain UTF-8 bytes, as a proxy or another
        // version of the plugin could. Trusting a wrong charset would garble it into "faÃ§on";
        // trusting an unknown one would throw instead of reporting.
        var body = JsonNode.Parse(RecordedAnswers.Body("projects-fr"))!
            .ToJsonString(new JsonSerializerOptions { Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping });
        Assert.Contains("façon", body);
        var plugin = FakePlugin.Answering(_ => RecordedAnswers.Response(
            ["HTTP/2 200", $"content-type: application/json; charset={charset}", "x-wp-total: 14", "x-wp-totalpages: 1"],
            body));

        var projects = (await plugin.Client().ListAsync(CollectionKind.Project, Language.Fr, Token)).AssertFetched();

        var momocad = Assert.IsType<Project>(Assert.Single(projects, project => project.Slug == "momocad"));
        Assert.Equal("Un logiciel de dessin 2D façon CAO dont l'état n'est qu'un journal d'événements rejoué", momocad.Subtitle);
    }

    [Theory]
    [InlineData(503)]
    [InlineData(403)]
    [InlineData(200)]
    public async Task The_firewall_page_is_recognised_and_named_whatever_its_status(int status)
    {
        var plugin = FakePlugin.Answering(_ =>
            RecordedAnswers.Response([$"HTTP/2 {status}", "content-type: text/html; charset=UTF-8"], RecordedAnswers.Body("firewall-challenge")));

        var reason = (await plugin.Client().GetSitemapAsync(Token)).AssertUnavailable();

        Assert.Equal(
            $"the host's firewall answered a JavaScript challenge page instead of the site (Tiger Protect), HTTP {status} (GET sitemap)",
            reason);
    }

    [Fact]
    public async Task An_error_of_the_plugin_is_unavailable_with_its_status_and_its_code()
    {
        // The plugin's real answer to a page beyond the last.
        var plugin = FakePlugin.Answering(_ => RecordedAnswers.Load("projects-fr-page-2"));

        var reason = (await plugin.Client().ListAsync(CollectionKind.Project, Language.Fr, Token)).AssertUnavailable();

        Assert.Equal("the plugin answered HTTP 400 (rest_post_invalid_page_number) (GET projects?language=fr&page=1&per_page=100)", reason);
    }

    [Fact]
    public async Task An_html_error_page_is_unavailable_without_its_markup()
    {
        var plugin = FakePlugin.Answering(_ =>
            RecordedAnswers.Response(["HTTP/2 502", "content-type: text/html"], "<html><body><h1>Bad Gateway</h1></body></html>"));

        var reason = (await plugin.Client().GetSitemapAsync(Token)).AssertUnavailable();

        Assert.Equal("the plugin answered HTTP 502 (GET sitemap)", reason);
    }

    [Fact]
    public async Task A_success_that_is_not_json_is_unavailable()
    {
        var plugin = FakePlugin.Answering(_ =>
            RecordedAnswers.Response(["HTTP/2 200", "content-type: text/html; charset=UTF-8"], "<html><body>Maintenance</body></html>"));

        var reason = (await plugin.Client().GetSitemapAsync(Token)).AssertUnavailable();

        Assert.Equal("the plugin answered text/html instead of JSON, HTTP 200 (GET sitemap)", reason);
    }

    [Theory]
    [InlineData("seo_description")]
    [InlineData("headline")]
    [InlineData("subtitle")]
    public async Task A_document_missing_a_key_of_the_contract_is_unexpected(string key)
    {
        // A key renamed by a later plugin must fail the reading, not become
        // null everywhere. The same goes for the two keys of ADR-027, which
        // are null when the front matter has none: their absence means a
        // plugin older than ADR-027, which this client no longer reads.
        var reason = await ReasonForPagesWhere(page => page.Remove(key));

        Assert.StartsWith("unexpected answer from the plugin (GET pages?language=fr&page=1&per_page=100): ", reason);
        Assert.Contains(key, reason);
    }

    [Fact]
    public async Task A_null_where_the_contract_promises_a_value_is_unexpected()
    {
        var reason = await ReasonForPagesWhere(page => page["title"] = null);

        Assert.StartsWith("unexpected answer from the plugin (GET pages?language=fr&page=1&per_page=100): ", reason);
    }

    [Fact]
    public async Task A_document_in_an_unknown_language_is_unexpected()
    {
        var reason = await ReasonForPagesWhere(page => page["lang"] = "it");

        Assert.EndsWith("the document \"contact\" names the unknown language \"it\"", reason);
    }

    [Fact]
    public async Task A_document_of_another_collection_is_unexpected()
    {
        var reason = await ReasonForPagesWhere(page => page["type"] = "article");

        Assert.EndsWith("the document \"contact\" is of type \"article\" in a list of type \"page\"", reason);
    }

    [Fact]
    public async Task A_list_served_in_another_language_than_the_one_asked_is_unexpected()
    {
        // What a proxy that drops the language parameter would cause (02-api-csharp.md §7):
        // the plugin then serves French, and the German lists of the catalog would be French.
        var reason = await ReasonForPagesWhere(_ => { }, asked: Language.De);

        Assert.EndsWith("the document \"contact\" is served in \"fr\" where \"de\" was asked", reason);
    }

    [Fact]
    public async Task A_fallback_flag_that_contradicts_the_languages_is_unexpected()
    {
        var reason = await ReasonForPagesWhere(page => page["is_fallback"] = true);

        Assert.EndsWith("the document \"contact\" says is_fallback True while its language is \"fr\" and the one asked \"fr\"", reason);
    }

    [Fact]
    public async Task A_project_in_an_unknown_state_is_unexpected()
    {
        var projects = JsonNode.Parse(RecordedAnswers.Body("projects-fr"))!.AsArray();
        projects[0]!["status"]!["slug"] = "abandonne";
        var plugin = FakePlugin.Answering(_ => RecordedAnswers.List(projects.ToJsonString(), total: 14, totalPages: 1));

        var reason = (await plugin.Client().ListAsync(CollectionKind.Project, Language.Fr, Token)).AssertUnavailable();

        Assert.EndsWith("the project \"xvii\" has the unknown state \"abandonne\"", reason);
    }

    [Fact]
    public async Task No_answer_is_unavailable()
    {
        var plugin = new FakePlugin((_, _) => throw new HttpRequestException("Connection refused (morganvoltz.dev:443)"));

        var reason = (await plugin.Client().GetSitemapAsync(Token)).AssertUnavailable();

        Assert.Equal("no answer from the plugin (GET sitemap): Connection refused (morganvoltz.dev:443)", reason);
    }

    [Fact]
    public async Task No_answer_in_time_is_unavailable()
    {
        var plugin = new FakePlugin(async (_, cancellationToken) =>
        {
            await Task.Delay(Timeout.Infinite, cancellationToken);
            throw new UnreachableException();
        });

        var reason = (await plugin.Client(timeout: TimeSpan.FromMilliseconds(50)).GetSitemapAsync(Token)).AssertUnavailable();

        Assert.Equal("no answer from the plugin within 0.05 s (GET sitemap)", reason);
    }

    [Fact]
    public async Task A_shorter_timeout_of_the_http_client_is_the_one_reported()
    {
        // The registration of lot 5 should leave HttpClient.Timeout alone; if it shortens it,
        // the log must still say which limit cut the request.
        var plugin = new FakePlugin(async (_, cancellationToken) =>
        {
            await Task.Delay(Timeout.Infinite, cancellationToken);
            throw new UnreachableException();
        });
        var client = new WordPressClient(new HttpClient(plugin) { BaseAddress = FakePlugin.Root, Timeout = TimeSpan.FromMilliseconds(50) });

        var reason = (await client.GetSitemapAsync(Token)).AssertUnavailable();

        Assert.Equal("no answer from the plugin within 0.05 s (GET sitemap)", reason);
    }

    [Fact]
    public async Task A_cancellation_asked_by_the_caller_is_thrown_not_reported()
    {
        // When the API stops, its refresh must stop, not log the plugin as unavailable.
        using var stopping = CancellationTokenSource.CreateLinkedTokenSource(Token);
        var plugin = new FakePlugin(async (_, cancellationToken) =>
        {
            await stopping.CancelAsync();
            await Task.Delay(Timeout.Infinite, cancellationToken);
            throw new UnreachableException();
        });

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => plugin.Client().GetSitemapAsync(stopping.Token));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("https://morganvoltz.dev/wp-json/portfolio/v1")]
    public void The_client_needs_the_root_of_the_plugin_routes_ending_with_a_slash(string? baseAddress)
    {
        // Without the final slash, a request for "projects" would replace "v1" instead of following it.
        using var http = new HttpClient { BaseAddress = baseAddress is null ? null : new Uri(baseAddress) };

        Assert.Throws<ArgumentException>(() => new WordPressClient(http));
    }

    /// <summary>
    /// The reason the client gives for the recorded French pages once
    /// <paramref name="change"/> has broken the first of them, the contact page.
    /// </summary>
    private static async Task<string> ReasonForPagesWhere(Action<JsonObject> change, Language asked = Language.Fr)
    {
        var pages = JsonNode.Parse(RecordedAnswers.Body("pages-fr"))!.AsArray();
        change(pages[0]!.AsObject());
        var plugin = FakePlugin.Answering(_ => RecordedAnswers.List(pages.ToJsonString(), total: 4, totalPages: 1));

        return (await plugin.Client().ListAsync(CollectionKind.Page, asked, Token)).AssertUnavailable();
    }

    /// <summary>
    /// French pages numbered from <paramref name="first"/>, made from the recorded
    /// contact page: enough to fill several pages of a list, which the site does not have.
    /// </summary>
    private static string PagesJson(int first, int count)
    {
        var template = JsonNode.Parse(RecordedAnswers.Body("pages-fr"))!.AsArray()[0]!;
        var pages = new JsonArray();
        for (var number = first; number < first + count; number++)
        {
            var page = template.DeepClone();
            page["id"] = number;
            page["slug"] = $"page-{number}";
            page["translation_key"] = $"page-{number}";
            page["translations"] = new JsonObject { ["fr"] = $"page-{number}" };
            pages.Add(page);
        }

        return pages.ToJsonString();
    }
}

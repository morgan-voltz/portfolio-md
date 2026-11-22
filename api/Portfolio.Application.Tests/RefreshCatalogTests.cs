using Portfolio.Application.Documents;
using Portfolio.Application.Ports;
using Portfolio.Application.UseCases;
using Portfolio.TestSupport;
using static Portfolio.TestSupport.TestDocuments;

namespace Portfolio.Application.Tests;

/// <summary>
/// The refresh of the catalog (ADR-026, point 2), against a plugin and a
/// renderer written by hand, and a clock the test moves.
/// </summary>
public sealed class RefreshCatalogTests
{
    private static readonly DateTimeOffset Start = new(2026, 11, 22, 8, 0, 0, TimeSpan.Zero);
    private static readonly TimeSpan FullReloadPeriod = TimeSpan.FromMinutes(360);

    /// <summary>The nine lists, in the order the refresh reads them.</summary>
    private static readonly string[] EveryList =
        ["Article/Fr", "Article/De", "Article/En", "Project/Fr", "Project/De", "Project/En", "Page/Fr", "Page/De", "Page/En"];

    private readonly CatalogStore _store = new();
    private readonly FakeWordPressClient _plugin = new();
    private readonly FakeMarkdownRenderer _renderer = new();
    private readonly ManualTimeProvider _clock = new(Start);
    private readonly RefreshCatalog _refresh;

    // A sheet in the three languages, and one in French only, which the German and
    // English lists serve as a fallback: the same document, sent once per list.
    private readonly (Document Fr, Document De, Document En) _momocad = TranslatedProject("momocad");
    private readonly (Document Fr, Document De, Document En) _atlas = Untranslated(Project("atlas", Language.Fr, Group("atlas")));

    public RefreshCatalogTests() => _refresh = new RefreshCatalog(_store, _plugin, _renderer, _clock);

    private LanguageLists Projects => Ranks(_momocad, _atlas);

    [Fact]
    public async Task The_first_tick_reads_the_sitemap_and_the_nine_lists_and_stores_a_rendered_catalog()
    {
        var sitemap = SitemapOf(All(Projects));
        _plugin.Serve(sitemap, projects: Projects);

        var reloaded = (await TickAsync()).AssertReloaded();

        Assert.Empty(reloaded.Discarded);
        Assert.Empty(reloaded.Unmatched);
        Assert.Equal<string>(["sitemap", .. EveryList], _plugin.Calls);
        var edition = _store.Edition!;
        Assert.Same(sitemap, edition.Sitemap);
        Assert.Equal(Start, edition.BuiltAt);
        var german = edition.Catalog.Find(CollectionKind.Project, "momocad", Language.De)!;
        Assert.Equal("momocad-de", german.Slug);
        Assert.Equal(FakeMarkdownRenderer.HtmlOf("# momocad-de", Language.De), german.Html);
    }

    [Fact]
    public async Task Each_document_is_rendered_once_with_its_own_language_and_each_list_keeps_its_own_copy()
    {
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);

        await TickAsync();

        Assert.Equal<(string, Language)>(
            [("# momocad", Language.Fr), ("# momocad-de", Language.De), ("# momocad-en", Language.En), ("# atlas", Language.Fr)],
            _renderer.Calls);

        // The German list's copy of atlas keeps its own list's language, the French HTML
        // added: a fallback, as the plugin sent it.
        var atlas = _store.Current!.List(CollectionKind.Project, new CatalogQuery(Language.De)).Items[1];
        Assert.Equal("atlas", atlas.Slug);
        Assert.Equal(Language.De, atlas.RequestedLanguage);
        Assert.True(atlas.IsFallback);
        Assert.Equal(FakeMarkdownRenderer.HtmlOf("# atlas", Language.Fr), atlas.Html);
    }

    [Fact]
    public async Task Two_translations_with_the_same_markdown_are_each_rendered_with_their_own_language()
    {
        // A German translation that still carries the French text: the same Markdown in
        // another language, so another rendering, a link to another document pointing
        // to the version in the document's own language.
        var group = Group("orion", "orion-de", "orion-en");
        var projects = Ranks((
            Project("orion", Language.Fr, group) with { Markdown = "# Orion" },
            Project("orion-de", Language.De, group) with { Markdown = "# Orion" },
            Project("orion-en", Language.En, group)));
        _plugin.Serve(SitemapOf(All(projects)), projects: projects);

        await TickAsync();

        Assert.Equal<(string, Language)>(
            [("# Orion", Language.Fr), ("# Orion", Language.De), ("# orion-en", Language.En)],
            _renderer.Calls);
        var german = _store.Current!.Find(CollectionKind.Project, "orion-de", Language.De)!;
        Assert.Equal(FakeMarkdownRenderer.HtmlOf("# Orion", Language.De), german.Html);
    }

    [Fact]
    public async Task The_same_lines_in_another_order_within_the_period_change_nothing()
    {
        var sitemap = SitemapOf(All(Projects));
        _plugin.Serve(sitemap, projects: Projects);
        await TickAsync();
        var first = _store.Edition;
        _plugin.Serve(new Sitemap([.. sitemap.Lines.Reverse()]), projects: Projects);
        _clock.Advance(FullReloadPeriod - TimeSpan.FromMinutes(1));
        var before = _plugin.Calls.Count;

        (await TickAsync()).AssertUnchanged();

        Assert.Equal<string>(["sitemap"], _plugin.Calls.Skip(before));
        Assert.Same(first, _store.Edition);
    }

    [Theory]
    [InlineData("edited")]
    [InlineData("unpublished")]
    [InlineData("published")]
    public async Task A_sitemap_whose_lines_changed_reloads_everything(string change)
    {
        var sitemap = SitemapOf(All(Projects));
        _plugin.Serve(sitemap, projects: Projects);
        await TickAsync();
        var first = _store.Edition;
        SitemapLine[] lines = change switch
        {
            "edited" => [.. sitemap.Lines.Select(line => line.Slug == "momocad-de" ? line with { Updated = line.Updated.AddDays(1) } : line)],
            "unpublished" => [.. sitemap.Lines.Where(line => line.Slug != "momocad-de")],
            "published" => [.. sitemap.Lines, new SitemapLine(CollectionKind.Project, "orion", Language.Fr, "orion", Start)],
            _ => throw new ArgumentOutOfRangeException(nameof(change), change, "Not a change of this test."),
        };
        _plugin.Serve(new Sitemap(lines), projects: Projects);
        var before = _plugin.Calls.Count;

        (await TickAsync()).AssertReloaded();

        Assert.Equal<string>(["sitemap", .. EveryList], _plugin.Calls.Skip(before));
        Assert.NotSame(first, _store.Edition);
    }

    [Fact]
    public async Task The_same_sitemap_reloads_everything_once_the_catalog_reaches_the_period()
    {
        // A renamed tag changes the documents, not the sitemap (ADR-026, context, point 4).
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);
        await TickAsync();
        _clock.Advance(FullReloadPeriod);
        var before = _plugin.Calls.Count;

        (await TickAsync()).AssertReloaded();

        Assert.Equal<string>(["sitemap", .. EveryList], _plugin.Calls.Skip(before));
        Assert.Equal(Start + FullReloadPeriod, _store.Edition!.BuiltAt);
    }

    [Fact]
    public async Task Before_the_first_catalog_an_unavailable_plugin_leaves_the_store_empty()
    {
        // The fake plugin serves no site until it is told to.
        (await TickAsync()).AssertUnavailable();

        Assert.Null(_store.Edition);
    }

    [Fact]
    public async Task An_unavailable_sitemap_ends_the_tick_and_the_catalog_stays_as_it_was()
    {
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);
        await TickAsync();
        var first = _store.Edition;
        _plugin.FailSitemap("the host's firewall answered");
        var before = _plugin.Calls.Count;

        var unavailable = (await TickAsync()).AssertUnavailable();

        Assert.Equal("the host's firewall answered", unavailable.Reason);
        Assert.Equal<string>(["sitemap"], _plugin.Calls.Skip(before));
        Assert.Same(first, _store.Edition);
    }

    [Fact]
    public async Task A_list_that_fails_ends_the_tick_at_once_and_the_catalog_stays_as_it_was()
    {
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);
        await TickAsync();
        var first = _store.Edition;
        _clock.Advance(FullReloadPeriod);
        _plugin.FailList(CollectionKind.Project, Language.De, "no answer within 20 seconds");
        var before = _plugin.Calls.Count;

        var unavailable = (await TickAsync()).AssertUnavailable();

        Assert.Equal("no answer within 20 seconds", unavailable.Reason);
        Assert.Equal<string>(["sitemap", "Article/Fr", "Article/De", "Article/En", "Project/Fr", "Project/De"], _plugin.Calls.Skip(before));
        Assert.Same(first, _store.Edition);
    }

    [Fact]
    public async Task Lists_that_do_not_line_up_end_the_tick_before_any_rendering_and_nothing_is_stored()
    {
        // atlas left the German list between two readings: the content moved meanwhile.
        var moved = new LanguageLists([_momocad.Fr, _atlas.Fr], [_momocad.De], [_momocad.En, _atlas.En]);
        _plugin.Serve(SitemapOf(All(moved)), projects: moved);

        var unavailable = (await TickAsync()).AssertUnavailable();

        Assert.StartsWith("the Project lists do not line up", unavailable.Reason);
        Assert.DoesNotContain("Page/Fr", _plugin.Calls);
        Assert.Empty(_renderer.Calls);
        Assert.Null(_store.Edition);
    }

    [Fact]
    public async Task A_document_that_cannot_be_rendered_is_discarded_with_its_rank_and_the_other_ranks_stay()
    {
        _renderer.FailOn("# momocad-de");
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);

        var reloaded = (await TickAsync()).AssertReloaded();

        Assert.Equal<DiscardedDocument>(
            [
                new(CollectionKind.Project, "momocad", Language.Fr, "its rank went with momocad-de (de), whose rendering failed"),
                new(CollectionKind.Project, "momocad-de", Language.De, "its rendering failed: the fake renderer fails on \"# momocad-de\""),
                new(CollectionKind.Project, "momocad-en", Language.En, "its rank went with momocad-de (de), whose rendering failed"),
            ],
            reloaded.Discarded);
        var catalog = _store.Current!;
        Assert.Null(catalog.Find(CollectionKind.Project, "momocad", Language.Fr));
        Assert.Null(catalog.Find(CollectionKind.Project, "momocad-en", Language.En));
        Assert.Equal("atlas", Assert.Single(catalog.List(CollectionKind.Project, new CatalogQuery(Language.De)).Items).Slug);

        // The sitemap still lists the three sheets of the rank, but each already has its
        // warning as a discarded document: none comes a second time as a line without a document.
        Assert.Empty(reloaded.Unmatched);
    }

    [Fact]
    public async Task When_two_documents_of_a_rank_cannot_be_rendered_the_third_names_both()
    {
        _renderer.FailOn("# momocad").FailOn("# momocad-en");
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);

        var reloaded = (await TickAsync()).AssertReloaded();

        Assert.Equal<DiscardedDocument>(
            [
                new(CollectionKind.Project, "momocad", Language.Fr, "its rendering failed: the fake renderer fails on \"# momocad\""),
                new(CollectionKind.Project, "momocad-de", Language.De, "its rank went with momocad (fr) and momocad-en (en), whose rendering failed"),
                new(CollectionKind.Project, "momocad-en", Language.En, "its rendering failed: the fake renderer fails on \"# momocad-en\""),
            ],
            reloaded.Discarded);
    }

    [Fact]
    public async Task A_fallback_that_cannot_be_rendered_is_discarded_once_not_once_per_list()
    {
        _renderer.FailOn("# atlas");
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);

        var reloaded = (await TickAsync()).AssertReloaded();

        Assert.Equal(
            new DiscardedDocument(CollectionKind.Project, "atlas", Language.Fr, "its rendering failed: the fake renderer fails on \"# atlas\""),
            Assert.Single(reloaded.Discarded));
        Assert.Single(_renderer.Calls, call => call.Markdown == "# atlas");
        Assert.Null(_store.Current!.Find(CollectionKind.Project, "atlas", Language.De));
    }

    [Fact]
    public async Task A_line_of_the_sitemap_that_leads_to_no_document_is_reported_and_the_catalog_replaced_all_the_same()
    {
        // A German sheet without a French original is in no list of the plugin (ADR-026, point 3).
        var orphan = new SitemapLine(CollectionKind.Project, "waise", Language.De, "waise", Start);
        _plugin.Serve(new Sitemap([.. SitemapOf(All(Projects)).Lines, orphan]), projects: Projects);

        var reloaded = (await TickAsync()).AssertReloaded();

        Assert.Equal(orphan, Assert.Single(reloaded.Unmatched));
        Assert.NotNull(_store.Current);
    }

    [Fact]
    public async Task A_cancellation_asked_by_the_caller_is_thrown_not_reported()
    {
        _plugin.Serve(SitemapOf(All(Projects)), projects: Projects);
        using var stopping = new CancellationTokenSource();
        await stopping.CancelAsync();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => _refresh.ExecuteAsync(FullReloadPeriod, stopping.Token));
        Assert.Null(_store.Edition);
    }

    private Task<RefreshOutcome> TickAsync() => _refresh.ExecuteAsync(FullReloadPeriod, TestContext.Current.CancellationToken);
}

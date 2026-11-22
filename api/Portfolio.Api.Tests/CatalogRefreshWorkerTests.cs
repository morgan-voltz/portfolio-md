using Microsoft.Extensions.Logging;
using Portfolio.Api.Tests.Support;
using Portfolio.Application;
using Portfolio.Application.Ports;
using Portfolio.TestSupport;
using static Portfolio.TestSupport.TestDocuments;

namespace Portfolio.Api.Tests;

/// <summary>
/// The background service of the refresh (ADR-026, point 2), in the API
/// started in memory: its rhythm, read from the timers it asks the manual
/// clock for, and the log it writes.
/// </summary>
public sealed class CatalogRefreshWorkerTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task Until_a_first_catalog_the_attempts_follow_after_5_15_45_then_every_60_seconds()
    {
        await using var factory = new PortfolioApiFactory();
        factory.StartApi();

        foreach (var seconds in new[] { 5, 15, 45, 60, 60 })
        {
            Assert.Equal(TimeSpan.FromSeconds(seconds), await factory.Clock.NextTimerAsync(Token));
            factory.Clock.Advance(TimeSpan.FromSeconds(seconds));
        }

        // The sixth attempt is done once it asks for its own wait.
        await factory.Clock.NextTimerAsync(Token);
        Assert.Equal(
            Enumerable.Repeat("The plugin could not be read, the catalog stays as it was: the fake plugin serves no site yet", 6),
            factory.Logs.Messages<CatalogRefreshWorker>(LogLevel.Warning));
        Assert.Null(factory.Store.Current);
    }

    [Fact]
    public async Task Once_a_catalog_exists_the_refresh_comes_at_the_period_of_the_settings()
    {
        await using var factory = new PortfolioApiFactory { Settings = { ["Portfolio:Cache:SitemapRefreshSeconds"] = "30" } };
        factory.Plugin.Serve(new Sitemap([]));
        factory.StartApi();

        Assert.Equal(TimeSpan.FromSeconds(30), await factory.Clock.NextTimerAsync(Token));
        Assert.NotNull(factory.Store.Current);
    }

    [Fact]
    public async Task The_catalog_is_reloaded_whole_once_it_reaches_the_age_of_the_settings()
    {
        await using var factory = new PortfolioApiFactory { Settings = { ["Portfolio:Cache:FullRefreshMinutes"] = "2" } };
        factory.Plugin.Serve(new Sitemap([]));
        factory.StartApi();

        await factory.Clock.NextTimerAsync(Token);
        factory.Clock.Advance(TimeSpan.FromSeconds(60));
        await factory.Clock.NextTimerAsync(Token);
        factory.Clock.Advance(TimeSpan.FromSeconds(60));
        await factory.Clock.NextTimerAsync(Token);

        // Three ticks, at 0, 1 and 2 minutes: the first loads, the second finds the
        // same sitemap, the third reloads all the same, the catalog being 2 minutes old.
        Assert.Equal(3, factory.Plugin.Calls.Count(call => call == "sitemap"));
        Assert.Equal(2, factory.Plugin.Calls.Count(call => call == "Page/En"));
        Assert.Equal(PortfolioApiFactory.Start + TimeSpan.FromMinutes(2), factory.Store.Edition!.BuiltAt);
    }

    [Fact]
    public async Task An_exception_in_a_tick_is_logged_and_the_attempts_go_on()
    {
        await using var factory = new PortfolioApiFactory();
        factory.Plugin.Crash(new InvalidOperationException("a bug in the client"));
        factory.StartApi();

        Assert.Equal(TimeSpan.FromSeconds(5), await factory.Clock.NextTimerAsync(Token));
        var error = Assert.Single(factory.Logs.Of<CatalogRefreshWorker>(LogLevel.Error));
        Assert.Equal("The refresh of the catalog failed unexpectedly, the catalog stays as it was.", error.Message);
        Assert.Equal("a bug in the client", error.Exception?.Message);

        factory.Plugin.Serve(new Sitemap([]));
        factory.Clock.Advance(TimeSpan.FromSeconds(5));

        Assert.Equal(TimeSpan.FromSeconds(60), await factory.Clock.NextTimerAsync(Token));
        Assert.NotNull(factory.Store.Current);
    }

    [Fact]
    public async Task A_reload_logs_each_discarded_document_and_each_line_of_the_sitemap_without_a_document()
    {
        var projects = Ranks(TranslatedProject("momocad"));
        var orphan = new SitemapLine(CollectionKind.Project, "waise", Language.De, "waise", PortfolioApiFactory.Start);
        await using var factory = new PortfolioApiFactory { Renderer = new FakeMarkdownRenderer().FailOn("# momocad-en") };
        factory.Plugin.Serve(new Sitemap([.. SitemapOf(All(projects)).Lines, orphan]), projects: projects);
        factory.StartApi();

        await factory.Clock.NextTimerAsync(Token);

        Assert.Equal<string>(
            ["Catalog reloaded: 3 document(s) discarded, 1 line(s) of the sitemap without a document."],
            factory.Logs.Messages<CatalogRefreshWorker>(LogLevel.Information));
        Assert.Equal<string>(
            [
                "Project momocad (fr) discarded: its rank went with momocad-en (en), whose rendering failed",
                "Project momocad-de (de) discarded: its rank went with momocad-en (en), whose rendering failed",
                "Project momocad-en (en) discarded: its rendering failed: the fake renderer fails on \"# momocad-en\"",
                "The sitemap lists Project waise (de), which leads to no document of the catalog.",
            ],
            factory.Logs.Messages<CatalogRefreshWorker>(LogLevel.Warning));
    }

    [Fact]
    public async Task The_api_stopping_in_the_middle_of_a_tick_is_not_logged_as_a_failure()
    {
        await using var factory = new PortfolioApiFactory();
        factory.Plugin.HangUntilCancelled();
        factory.StartApi();
        await factory.Plugin.Hanging.WaitAsync(TimeSpan.FromSeconds(10), Token);

        await factory.DisposeAsync();

        Assert.Empty(factory.Logs.Of<CatalogRefreshWorker>(LogLevel.Error));
    }
}

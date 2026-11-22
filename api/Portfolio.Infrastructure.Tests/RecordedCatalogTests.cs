using System.Text.Json;
using Portfolio.Application;
using Portfolio.Application.Documents;
using Portfolio.Infrastructure.Tests.Support;
using Portfolio.Infrastructure.WordPress;

namespace Portfolio.Infrastructure.Tests;

/// <summary>
/// The client and the core together, on the plugin's real answers: the nine
/// recorded lists build a catalog (lot 2), which answers the way the plugin's
/// own detail routes answered on the same day.
/// </summary>
public sealed class RecordedCatalogTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task The_recorded_lists_build_a_catalog_that_answers_as_the_plugin_did()
    {
        var catalog = await CatalogOf(FakePlugin.Recorded().Client());

        // GET /projects/momocad-de?language=fr gave the French sheet, GET /pages/lebenslauf?language=en the English résumé.
        AssertSameDocument("project-momocad-de-in-fr", catalog.Find(CollectionKind.Project, "momocad-de", Language.Fr));
        AssertSameDocument("page-lebenslauf-in-en", catalog.Find(CollectionKind.Page, "lebenslauf", Language.En));
    }

    [Fact]
    public async Task Every_line_of_the_recorded_sitemap_leads_to_a_document_of_the_catalog()
    {
        // The refresh will warn about any line the index lacks (ADR-026, point 3): on the
        // content of 2026-11-22, there is none.
        var client = FakePlugin.Recorded().Client();
        var catalog = await CatalogOf(client);

        var sitemap = (await client.GetSitemapAsync(Token)).AssertFetched();

        Assert.All(sitemap.Lines, line => Assert.NotNull(catalog.Find(line.Collection, line.Slug, line.Lang)));
    }

    private static async Task<Catalog> CatalogOf(WordPressClient client)
    {
        async Task<LanguageLists> ListsOf(CollectionKind collection) => new(
            (await client.ListAsync(collection, Language.Fr, Token)).AssertFetched(),
            (await client.ListAsync(collection, Language.De, Token)).AssertFetched(),
            (await client.ListAsync(collection, Language.En, Token)).AssertFetched());

        return Catalog.Build(await ListsOf(CollectionKind.Article), await ListsOf(CollectionKind.Project), await ListsOf(CollectionKind.Page)) switch
        {
            Catalog catalog => catalog,
            RanksMisaligned misaligned => throw new InvalidOperationException(
                $"The recorded {misaligned.Collection} lists do not line up: {misaligned.Reason}."),
        };
    }

    /// <summary>The document is the one the plugin answered in <paramref name="recording"/>.</summary>
    private static void AssertSameDocument(string recording, Document? document)
    {
        Assert.NotNull(document);
        using var expected = JsonDocument.Parse(RecordedAnswers.Body(recording));
        var plugin = expected.RootElement;
        Assert.Equal(plugin.GetProperty("id").GetInt32(), document.WordPressId);
        Assert.Equal(plugin.GetProperty("slug").GetString(), document.Slug);
        Assert.Equal(plugin.GetProperty("lang").GetString(), document.Lang.Code());
        Assert.Equal(plugin.GetProperty("requested_language").GetString(), document.RequestedLanguage.Code());
        Assert.Equal(plugin.GetProperty("is_fallback").GetBoolean(), document.IsFallback);
        Assert.Equal(plugin.GetProperty("title").GetString(), document.Title);
        Assert.Equal(plugin.GetProperty("link").GetString(), document.CanonicalUrl);
        Assert.Equal(plugin.GetProperty("markdown").GetString(), document.Markdown);
    }
}

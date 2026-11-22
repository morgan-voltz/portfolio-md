using System.Diagnostics.CodeAnalysis;
using Portfolio.Application.Documents;
using Portfolio.Application.Ports;

namespace Portfolio.Application.UseCases;

/// <summary>
/// Brings the catalog up to date with the plugin (ADR-026, point 2): reads the
/// sitemap and, when it changed or when the catalog is older than the full
/// reload period, reads the nine lists, renders every document and replaces
/// the catalog in one write.
/// </summary>
/// <remarks>
/// <para>
/// A background service of the API calls it at each tick. It never throws for
/// a failure of the plugin: that gives <see cref="SourceUnavailable"/>, and the
/// catalog stays as it was. It logs nothing either, since the core has no
/// logger (ADR-026, point 6): its outcome carries what the service logs.
/// </para>
/// <para>
/// The nine lists are read one after the other, and the first failure ends
/// the tick: no visitor waits for them, and the host's firewall, which
/// sometimes answers a burst of requests with its challenge page, is better
/// asked one thing at a time.
/// </para>
/// </remarks>
public sealed class RefreshCatalog(CatalogStore store, IWordPressClient client, IMarkdownRenderer renderer, TimeProvider clock)
{
    private static readonly CollectionKind[] Collections = [CollectionKind.Article, CollectionKind.Project, CollectionKind.Page];
    private static readonly Language[] Languages = [Language.Fr, Language.De, Language.En];

    /// <param name="fullReloadPeriod">
    /// The age past which the catalog is reloaded even when the sitemap did not
    /// change (<c>PORTFOLIO__CACHE__FULLREFRESHMINUTES</c>): a renamed tag or
    /// skill changes documents without changing a line of the sitemap
    /// (ADR-026, context, point 4).
    /// </param>
    /// <param name="cancellationToken">The API stops: thrown, never reported as a failure of the plugin.</param>
    public async Task<RefreshOutcome> ExecuteAsync(TimeSpan fullReloadPeriod, CancellationToken cancellationToken) =>
        await client.GetSitemapAsync(cancellationToken) switch
        {
            SourceUnavailable unavailable => unavailable,
            Sitemap sitemap when IsCurrent(sitemap, fullReloadPeriod) => new Unchanged(),
            Sitemap sitemap => await ReloadAsync(sitemap, cancellationToken),
        };

    /// <summary>
    /// Whether the current catalog was built from the same lines, compared as
    /// two sets so that their order does not count, and is younger than the
    /// full reload period.
    /// </summary>
    private bool IsCurrent(Sitemap sitemap, TimeSpan fullReloadPeriod) =>
        store.Edition is { } edition
        && clock.GetUtcNow() - edition.BuiltAt < fullReloadPeriod
        && edition.Sitemap.Lines.ToHashSet().SetEquals(sitemap.Lines);

    private async Task<RefreshOutcome> ReloadAsync(Sitemap sitemap, CancellationToken cancellationToken)
    {
        var read = new Dictionary<CollectionKind, LanguageLists>();
        foreach (var collection in Collections)
        {
            var lists = new Dictionary<Language, IReadOnlyList<Document>>();
            foreach (var language in Languages)
            {
                if (!Fetched(await client.ListAsync(collection, language, cancellationToken), out var documents, out var unavailable))
                {
                    return unavailable;
                }

                lists[language] = documents;
            }

            var collectionLists = new LanguageLists(lists[Language.Fr], lists[Language.De], lists[Language.En]);

            // Checked before rendering, which goes through the lists rank by rank.
            if (CollectionSnapshot.FindMisalignment(collection, collectionLists) is { } misaligned)
            {
                return Abandoned(misaligned);
            }

            read[collection] = collectionLists;
        }

        var discarded = new List<DiscardedDocument>();
        var rendered = read.ToDictionary(pair => pair.Key, pair => Render(pair.Key, pair.Value, discarded));

        return Catalog.Build(rendered[CollectionKind.Article], rendered[CollectionKind.Project], rendered[CollectionKind.Page]) switch
        {
            Catalog catalog => Publish(catalog, sitemap, discarded),

            // Cannot happen: the lists were checked above, and rendering drops whole
            // ranks. Catalog.Build checks all the same, and its answer must be read.
            RanksMisaligned misaligned => Abandoned(misaligned),
        };
    }

    /// <summary>
    /// The three lists of a collection with the HTML of each document, without
    /// the ranks where a rendering failed.
    /// </summary>
    /// <remarks>
    /// <para>
    /// A rendering depends only on the Markdown and the language of the
    /// document, so a rank renders each pair once: the plugin sends a document
    /// once per list that serves it as a fallback, each copy keeping the
    /// language of its own list, and each copy gets the same HTML.
    /// </para>
    /// <para>
    /// When a rendering fails, its rank goes, in the three languages. The other
    /// ranks stay (ADR-026, point 2), but this one cannot: dropping a single
    /// document would misalign the three lists, which <see cref="Catalog.Build"/>
    /// refuses, and every rank is anchored on its French document. Every
    /// document of the rank is noted as discarded, with its own reason, so that
    /// the log counts all that left the catalog.
    /// </para>
    /// </remarks>
    private LanguageLists Render(CollectionKind collection, LanguageLists lists, List<DiscardedDocument> discarded)
    {
        var fr = new List<Document>();
        var de = new List<Document>();
        var en = new List<Document>();
        for (var rank = 0; rank < lists.Fr.Count; rank++)
        {
            Document[] served = [lists.Fr[rank], lists.De[rank], lists.En[rank]];
            var html = new Dictionary<(string Markdown, Language Lang), string>();
            var failures = new Dictionary<(string Markdown, Language Lang), string>();
            foreach (var document in served)
            {
                var pair = (document.Markdown, document.Lang);
                if (html.ContainsKey(pair) || failures.ContainsKey(pair))
                {
                    continue;
                }

                try
                {
                    html[pair] = renderer.Render(document.Markdown, document.Lang);
                }
                catch (Exception exception)
                {
                    // Any exception: the renderer promises nothing narrower, and one
                    // document must not cost the whole refresh (ADR-026, point 2).
                    failures[pair] = exception.Message;
                }
            }

            if (failures.Count > 0)
            {
                Discard(collection, served, failures, discarded);
                continue;
            }

            fr.Add(served[0] with { Html = html[(served[0].Markdown, served[0].Lang)] });
            de.Add(served[1] with { Html = html[(served[1].Markdown, served[1].Lang)] });
            en.Add(served[2] with { Html = html[(served[2].Markdown, served[2].Lang)] });
        }

        return new LanguageLists(fr, de, en);
    }

    /// <summary>
    /// Notes every document of a rank as discarded: each one whose rendering
    /// failed, with the exception's message, and the others, which go with
    /// them and name them all. A fallback copy is the same document, noted once.
    /// </summary>
    private static void Discard(
        CollectionKind collection,
        Document[] served,
        Dictionary<(string Markdown, Language Lang), string> failures,
        List<DiscardedDocument> discarded)
    {
        var documents = served.DistinctBy(document => (document.Slug, document.Lang)).ToArray();
        var failed = string.Join(
            " and ",
            documents
                .Where(document => failures.ContainsKey((document.Markdown, document.Lang)))
                .Select(document => $"{document.Slug} ({document.Lang.Code()})"));
        foreach (var document in documents)
        {
            var reason = failures.TryGetValue((document.Markdown, document.Lang), out var message)
                ? $"its rendering failed: {message}"
                : $"its rank went with {failed}, whose rendering failed";
            discarded.Add(new DiscardedDocument(collection, document.Slug, document.Lang, reason));
        }
    }

    /// <summary>
    /// Puts the new catalog in the store, in one write, then lists the lines of
    /// the sitemap that lead to none of its documents, which the service logs
    /// (ADR-026, point 3). A discarded document already has its warning: its
    /// line is not listed a second time.
    /// </summary>
    private Reloaded Publish(Catalog catalog, Sitemap sitemap, IReadOnlyList<DiscardedDocument> discarded)
    {
        store.Replace(new CatalogEdition(catalog, sitemap, clock.GetUtcNow()));
        var gone = discarded.Select(document => (document.Collection, document.Slug, document.Lang)).ToHashSet();
        SitemapLine[] unmatched = [.. sitemap.Lines.Where(line =>
            !gone.Contains((line.Collection, line.Slug, line.Lang)) && catalog.Find(line.Collection, line.Slug, line.Lang) is null)];
        return new Reloaded(discarded, unmatched);
    }

    private static SourceUnavailable Abandoned(RanksMisaligned misaligned) =>
        new($"the {misaligned.Collection} lists do not line up, the content changed while they were read: {misaligned.Reason}");

    /// <summary>
    /// Splits a fetched list into its two cases, for the loop of
    /// <see cref="ReloadAsync"/>, which must return from its middle. Two switch
    /// expressions rather than a switch statement: only an expression makes the
    /// compiler check that every case of the union is handled.
    /// </summary>
    private static bool Fetched(
        Fetch<IReadOnlyList<Document>> fetched,
        [NotNullWhen(true)] out IReadOnlyList<Document>? documents,
        [NotNullWhen(false)] out SourceUnavailable? unavailable)
    {
        documents = fetched switch
        {
            IReadOnlyList<Document> list => list,
            SourceUnavailable => null,
        };
        unavailable = fetched switch
        {
            SourceUnavailable failed => failed,
            IReadOnlyList<Document> => null,
        };
        return documents is not null;
    }
}

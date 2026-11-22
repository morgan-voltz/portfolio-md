using Portfolio.Application.Documents;

namespace Portfolio.Application;

/// <summary>
/// The published content of the site, every collection in every language,
/// held in memory (ADR-026): built once from the lists of the plugin, never
/// changed afterwards, replaced as a whole by the next refresh.
/// </summary>
/// <remarks>
/// <para>
/// The catalog decides nothing the plugin has already decided: which
/// translation a language serves, the labels, the order of the lists all
/// arrive settled in the lists. What it adds is putting nine lists together
/// so that a request can be answered without calling WordPress.
/// </para>
/// <para>
/// Limits, all on content the plugin itself reports as abnormal
/// (<c>wp portfolio translations reconcile</c>, ADR-026 point 3). A document
/// without a published French original is in none of the plugin's lists,
/// which are anchored on the French documents, so it is not in the catalog
/// either. When two documents of one language share a translation key, the
/// oldest keeps the slugs they share. If that language is French, each of
/// the two still has its rank and its own slug, as in the plugin. In another
/// language, the younger one is served by no list, so the catalog does not
/// know its slug, which the plugin's detail route still serves. The refresh
/// (lot 5) logs a warning for every line of the sitemap the catalog cannot
/// find.
/// </para>
/// </remarks>
public sealed class Catalog
{
    private readonly CollectionSnapshot _articles;
    private readonly CollectionSnapshot _projects;
    private readonly CollectionSnapshot _pages;

    private Catalog(CollectionSnapshot articles, CollectionSnapshot projects, CollectionSnapshot pages)
    {
        _articles = articles;
        _projects = projects;
        _pages = pages;
    }

    /// <summary>
    /// Builds a catalog from the lists of the three collections, or says which
    /// collection does not line up.
    /// </summary>
    public static CatalogBuildOutcome Build(LanguageLists articles, LanguageLists projects, LanguageLists pages)
    {
        var misalignment = CollectionSnapshot.FindMisalignment(CollectionKind.Article, articles)
            ?? CollectionSnapshot.FindMisalignment(CollectionKind.Project, projects)
            ?? CollectionSnapshot.FindMisalignment(CollectionKind.Page, pages);
        if (misalignment is not null)
        {
            return misalignment;
        }

        return new Catalog(new CollectionSnapshot(articles), new CollectionSnapshot(projects), new CollectionSnapshot(pages));
    }

    /// <summary>
    /// The document known under <paramref name="slug"/>, whatever the language
    /// of that slug, served in <paramref name="language"/>; <see langword="null"/>
    /// when no document of the collection is known under it.
    /// </summary>
    /// <example>
    /// <c>Find(CollectionKind.Project, "momocad-de", Language.Fr)</c> is the
    /// French sheet <c>momocad</c>, as the plugin answers
    /// <c>GET /projects/momocad-de?language=fr</c>.
    /// </example>
    public Document? Find(CollectionKind collection, string slug, Language language) =>
        In(collection).Find(slug, language);

    /// <summary>
    /// One page of a collection: the ranks whose French document passes the
    /// filters, in the plugin's order, served in the language of the query. A
    /// page beyond the last is empty, with the real totals (ADR-026, point 5).
    /// </summary>
    public PagedList<Document> List(CollectionKind collection, CatalogQuery query) =>
        In(collection).List(query);

    private CollectionSnapshot In(CollectionKind collection) => collection switch
    {
        CollectionKind.Article => _articles,
        CollectionKind.Project => _projects,
        CollectionKind.Page => _pages,
        _ => throw new ArgumentOutOfRangeException(nameof(collection), collection, "Not a collection of the site."),
    };
}

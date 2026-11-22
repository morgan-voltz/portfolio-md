using System.Collections.Frozen;
using Portfolio.Application.Documents;

namespace Portfolio.Application;

/// <summary>
/// One collection of a <see cref="Catalog"/>: its lists, copied, and an index
/// of its slugs. Built once, never changed.
/// </summary>
internal sealed class CollectionSnapshot
{
    private static readonly Language[] Languages = [Language.Fr, Language.De, Language.En];

    /// <summary>The French document of each rank, in the plugin's order: what the filters look at.</summary>
    private readonly Document[] _french;

    /// <summary>For each language, the document each rank serves: what a list returns.</summary>
    private readonly FrozenDictionary<Language, Document[]> _served;

    /// <summary>For each language, every slug a group is known under, to the document served for it.</summary>
    private readonly FrozenDictionary<Language, FrozenDictionary<string, Document>> _bySlug;

    /// <summary>Call <see cref="FindMisalignment"/> first: the constructor assumes lists that line up.</summary>
    public CollectionSnapshot(LanguageLists lists)
    {
        // Copies: the snapshot must not change if the caller changes its lists later.
        _served = Languages.ToFrozenDictionary(language => language, language => lists.In(language).ToArray());
        _french = _served[Language.Fr];

        var indexes = Languages.ToDictionary(language => language, _ => new Dictionary<string, Document>());
        for (var rank = 0; rank < _french.Length; rank++)
        {
            var slugs = KnownSlugs(rank);
            foreach (var language in Languages)
            {
                var document = _served[language][rank];
                foreach (var slug in slugs)
                {
                    Claim(indexes[language], slug, document);
                }
            }
        }

        _bySlug = indexes.ToFrozenDictionary(index => index.Key, index => index.Value.ToFrozenDictionary());
    }

    /// <summary>Why the three lists do not line up rank by rank, or <see langword="null"/> when they do.</summary>
    public static RanksMisaligned? FindMisalignment(CollectionKind collection, LanguageLists lists)
    {
        if (lists.De.Count != lists.Fr.Count || lists.En.Count != lists.Fr.Count)
        {
            return new RanksMisaligned(
                collection,
                $"the lists differ in length: fr {lists.Fr.Count}, de {lists.De.Count}, en {lists.En.Count}");
        }

        for (var rank = 0; rank < lists.Fr.Count; rank++)
        {
            var key = lists.Fr[rank].TranslationKey;
            if (lists.De[rank].TranslationKey != key || lists.En[rank].TranslationKey != key)
            {
                return new RanksMisaligned(
                    collection,
                    $"rank {rank} carries the keys {key}, {lists.De[rank].TranslationKey} and {lists.En[rank].TranslationKey}");
            }
        }

        return null;
    }

    public Document? Find(string slug, Language language) => _bySlug[language].GetValueOrDefault(slug);

    public PagedList<Document> List(CatalogQuery query)
    {
        var matching = Enumerable.Range(0, _french.Length).Where(rank => query.Matches(_french[rank])).ToArray();

        // A long: a huge page number times the page size would overflow an int, turn
        // negative, and Skip would then start from the first document.
        var offset = (long)(query.Page - 1) * query.PerPage;
        var served = _served[query.Language];
        Document[] items = offset >= matching.Length
            ? []
            : [.. matching.Skip((int)offset).Take(query.PerPage).Select(rank => served[rank])];

        return new PagedList<Document>(items, query.Page, query.PerPage, matching.Length);
    }

    /// <summary>
    /// Every slug the group of a rank is known under: the slug of each of the
    /// three documents served at that rank, and every slug their translations
    /// list. A slug of one language then finds the document served in
    /// another, as the plugin's detail route does, and every slug the catalog
    /// hands out in a translation leads somewhere in the same catalog.
    /// </summary>
    private HashSet<string> KnownSlugs(int rank)
    {
        var slugs = new HashSet<string>();
        foreach (var language in Languages)
        {
            var document = _served[language][rank];
            slugs.Add(document.Slug);
            slugs.UnionWith(document.Translations.Values);
        }

        return slugs;
    }

    /// <summary>
    /// Gives a slug to a document, unless an older document already holds it.
    /// </summary>
    /// <remarks>
    /// Two ranks claim the same slug only when two documents of one language
    /// share a translation key, an anomaly the plugin reports
    /// (<c>wp portfolio translations reconcile</c>). The oldest post, the
    /// smaller WordPress id, then keeps the shared slug, as in the plugin's
    /// <c>GroupResolver</c> (ADR-026, point 3). Comparing ids, rather than
    /// keeping whichever rank comes first, makes the result independent of
    /// the order: the plugin lists the newest first, so "first come" would
    /// get it backwards. Checked against the plugin's own PHP on made-up
    /// groups (fallbacks, duplicates, a group without French) when the lot
    /// was designed.
    /// </remarks>
    private static void Claim(Dictionary<string, Document> index, string slug, Document candidate)
    {
        if (!index.TryGetValue(slug, out var holder) || candidate.WordPressId < holder.WordPressId)
        {
            index[slug] = candidate;
        }
    }
}

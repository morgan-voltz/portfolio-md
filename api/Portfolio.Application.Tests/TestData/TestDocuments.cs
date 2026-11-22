using Portfolio.Application.Documents;
using Portfolio.Application.UseCases;

namespace Portfolio.Application.Tests.TestData;

/// <summary>
/// Hand-written test data (ADR-026, point 10): documents as one list of the
/// plugin serves them, and the lists of a collection given rank by rank.
/// </summary>
internal static class TestDocuments
{
    private static readonly DateTimeOffset SomeDay = new(2026, 11, 1, 0, 0, 0, TimeSpan.Zero);

    /// <summary>The slugs of a translation group by language, as <see cref="Document.Translations"/> lists them.</summary>
    public static IReadOnlyDictionary<Language, string> Group(string? fr = null, string? de = null, string? en = null)
    {
        var slugs = new Dictionary<Language, string>();
        if (fr is not null)
        {
            slugs[Language.Fr] = fr;
        }

        if (de is not null)
        {
            slugs[Language.De] = de;
        }

        if (en is not null)
        {
            slugs[Language.En] = en;
        }

        return slugs;
    }

    /// <summary>
    /// A project as the list of <paramref name="servedIn"/> serves it (its own
    /// language when omitted). The translation key is the French slug of the
    /// group, as in the plugin, unless <paramref name="translationKey"/> says
    /// otherwise.
    /// </summary>
    public static Project Project(
        string slug,
        Language lang,
        IReadOnlyDictionary<Language, string> group,
        Language? servedIn = null,
        int wordPressId = 1,
        string? translationKey = null,
        IReadOnlyList<Tag>? tags = null,
        Status? status = null,
        IReadOnlyList<Skill>? skills = null) => new()
        {
            WordPressId = wordPressId,
            Slug = slug,
            Lang = lang,
            TranslationKey = translationKey ?? group.GetValueOrDefault(Language.Fr, slug),
            Translations = group,
            RequestedLanguage = servedIn ?? lang,
            Title = slug,
            CanonicalUrl = $"https://example.test/{lang.ToString().ToLowerInvariant()}/projets/{slug}/",
            Date = SomeDay,
            Updated = SomeDay,
            Markdown = $"# {slug}",
            Tags = tags ?? [],
            Featured = false,
            Status = status,
            Skills = skills ?? [],
            IsFilRouge = false,
        };

    /// <summary>An article as the list of <paramref name="servedIn"/> serves it.</summary>
    public static Article Article(
        string slug,
        Language lang,
        IReadOnlyDictionary<Language, string> group,
        Language? servedIn = null,
        IReadOnlyList<Tag>? tags = null) => new()
        {
            WordPressId = 1,
            Slug = slug,
            Lang = lang,
            TranslationKey = group.GetValueOrDefault(Language.Fr, slug),
            Translations = group,
            RequestedLanguage = servedIn ?? lang,
            Title = slug,
            CanonicalUrl = $"https://example.test/{lang.ToString().ToLowerInvariant()}/articles/{slug}/",
            Date = SomeDay,
            Updated = SomeDay,
            Markdown = $"# {slug}",
            Tags = tags ?? [],
            Featured = false,
        };

    /// <summary>A static page as the list of <paramref name="servedIn"/> serves it.</summary>
    public static Page Page(
        string slug,
        Language lang,
        IReadOnlyDictionary<Language, string> group,
        Language? servedIn = null) => new()
        {
            WordPressId = 1,
            Slug = slug,
            Lang = lang,
            TranslationKey = group.GetValueOrDefault(Language.Fr, slug),
            Translations = group,
            RequestedLanguage = servedIn ?? lang,
            Title = slug,
            CanonicalUrl = $"https://example.test/{lang.ToString().ToLowerInvariant()}/{slug}/",
            Date = SomeDay,
            Updated = SomeDay,
            Markdown = $"# {slug}",
        };

    /// <summary>
    /// A project translated in the three languages, as the three lists serve it:
    /// each list serves the member in its own language.
    /// </summary>
    public static (Document Fr, Document De, Document En) TranslatedProject(string frenchSlug, IReadOnlyList<Tag>? tags = null)
    {
        var group = Group(frenchSlug, $"{frenchSlug}-de", $"{frenchSlug}-en");
        return (
            Project(frenchSlug, Language.Fr, group, tags: tags),
            Project($"{frenchSlug}-de", Language.De, group, tags: tags),
            Project($"{frenchSlug}-en", Language.En, group, tags: tags));
    }

    /// <summary>
    /// A document without any translation, as the three lists serve it: in its
    /// own language everywhere, as a fallback in the lists of the two others.
    /// </summary>
    public static (Document Fr, Document De, Document En) Untranslated(Document document) =>
        (document with { RequestedLanguage = Language.Fr },
         document with { RequestedLanguage = Language.De },
         document with { RequestedLanguage = Language.En });

    /// <summary>The three lists of a collection, given rank by rank.</summary>
    public static LanguageLists Ranks(params (Document Fr, Document De, Document En)[] ranks) =>
        new([.. ranks.Select(rank => rank.Fr)], [.. ranks.Select(rank => rank.De)], [.. ranks.Select(rank => rank.En)]);

    /// <summary>A catalog whose only documents are the given projects.</summary>
    public static Catalog CatalogOfProjects(LanguageLists projects) =>
        Catalog.Build(LanguageLists.Empty, projects, LanguageLists.Empty).AssertBuilt();

    public static Catalog AssertBuilt(this CatalogBuildOutcome outcome) => outcome switch
    {
        Catalog catalog => catalog,
        RanksMisaligned misaligned => throw new InvalidOperationException(
            $"Expected a catalog; the {misaligned.Collection} lists are misaligned: {misaligned.Reason}."),
    };

    public static RanksMisaligned AssertMisaligned(this CatalogBuildOutcome outcome) => outcome switch
    {
        RanksMisaligned misaligned => misaligned,
        Catalog => throw new InvalidOperationException("Expected misaligned ranks, got a catalog."),
    };

    public static Document AssertFound(this DocumentLookup lookup) => lookup switch
    {
        Document document => document,
        NotFound => throw new InvalidOperationException("Expected a document, got NotFound."),
        CatalogNotReady => throw new InvalidOperationException("Expected a document, got CatalogNotReady."),
    };

    public static PagedList<Document> AssertListed(this DocumentListing listing) => listing switch
    {
        PagedList<Document> page => page,
        CatalogNotReady => throw new InvalidOperationException("Expected a page, got CatalogNotReady."),
    };
}

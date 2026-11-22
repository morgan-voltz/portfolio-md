using Portfolio.Application.Tests.TestData;
using static Portfolio.Application.Tests.TestData.TestDocuments;

namespace Portfolio.Application.Tests;

/// <summary>
/// Reading a document by its slug (ADR-021, ADR-026 point 3): the catalog
/// answers as the plugin's detail route does.
/// </summary>
public sealed class CatalogFindTests
{
    [Fact]
    public void A_document_is_found_under_its_own_slug()
    {
        var momocad = TranslatedProject("momocad");
        var catalog = CatalogOfProjects(Ranks(momocad));

        Assert.Same(momocad.Fr, catalog.Find(CollectionKind.Project, "momocad", Language.Fr));
        Assert.Same(momocad.De, catalog.Find(CollectionKind.Project, "momocad-de", Language.De));
    }

    [Theory]
    [InlineData("momocad-de", Language.Fr, "momocad")] // the plugin in production: GET /projects/momocad-de?language=fr
    [InlineData("momocad", Language.De, "momocad-de")]
    [InlineData("momocad-en", Language.De, "momocad-de")]
    [InlineData("momocad-de", Language.En, "momocad-en")]
    public void A_slug_of_one_language_finds_the_document_served_in_another(string slug, Language language, string expected)
    {
        var catalog = CatalogOfProjects(Ranks(TranslatedProject("momocad")));

        var found = catalog.Find(CollectionKind.Project, slug, language);

        Assert.NotNull(found);
        Assert.Equal(expected, found.Slug);
        Assert.Equal(language, found.RequestedLanguage);
        Assert.False(found.IsFallback);
    }

    [Fact]
    public void A_missing_translation_is_served_in_the_language_the_plugin_falls_back_to()
    {
        // A French sheet without translation: the German and English lists serve it (de -> en -> fr).
        var alone = Untranslated(Project("alone", Language.Fr, Group(fr: "alone")));
        // A sheet in French and English only: the German list serves the English one (de -> en).
        var pairGroup = Group(fr: "pair", en: "pair-en");
        var pair = (
            Fr: Project("pair", Language.Fr, pairGroup),
            De: Project("pair-en", Language.En, pairGroup, servedIn: Language.De),
            En: Project("pair-en", Language.En, pairGroup));
        var catalog = CatalogOfProjects(Ranks(alone, pair));

        var aloneInGerman = catalog.Find(CollectionKind.Project, "alone", Language.De);
        var pairInGerman = catalog.Find(CollectionKind.Project, "pair", Language.De);

        Assert.Same(alone.De, aloneInGerman);
        Assert.True(aloneInGerman!.IsFallback);
        Assert.Same(pair.De, pairInGerman);
        Assert.Equal(Language.En, pairInGerman!.Lang);
    }

    [Fact]
    public void A_page_is_found_under_its_slug_in_any_language()
    {
        // A page has a slug of its own in each language (ADR-020).
        var group = Group("cv", "lebenslauf", "resume");
        var cv = (Fr: Page("cv", Language.Fr, group), De: Page("lebenslauf", Language.De, group), En: Page("resume", Language.En, group));
        var catalog = Catalog.Build(LanguageLists.Empty, LanguageLists.Empty, Ranks(cv)).AssertBuilt();

        // The plugin in production: GET /pages/lebenslauf?language=en serves the English résumé.
        Assert.Same(cv.En, catalog.Find(CollectionKind.Page, "lebenslauf", Language.En));
        Assert.Same(cv.Fr, catalog.Find(CollectionKind.Page, "resume", Language.Fr));
    }

    [Fact]
    public void A_slug_that_only_a_translation_names_still_leads_to_its_group()
    {
        // The front builds its language switcher from `translations`: every slug the catalog
        // hands out there must lead somewhere in the same catalog (ADR-026, point 3, indexes
        // the slugs of translations as well as a document's own). Here the lists were read
        // while the German sheet was being renamed: the French sheet, read first, still names
        // the old German slug, and the German list, read after, serves the sheet under its new one.
        var french = Project("momocad", Language.Fr, Group("momocad", "momocad-alt", "momocad-en"));
        var german = Project("momocad-de", Language.De, Group("momocad", "momocad-de", "momocad-en"));
        var english = Project("momocad-en", Language.En, Group("momocad", "momocad-de", "momocad-en"));
        var catalog = CatalogOfProjects(Ranks((french, german, english)));

        Assert.Same(german, catalog.Find(CollectionKind.Project, "momocad-alt", Language.De));
        Assert.Same(french, catalog.Find(CollectionKind.Project, "momocad-alt", Language.Fr));
    }

    [Fact]
    public void An_unknown_slug_is_not_found()
    {
        var catalog = CatalogOfProjects(Ranks(TranslatedProject("momocad")));

        Assert.Null(catalog.Find(CollectionKind.Project, "atlas", Language.Fr));
    }

    [Fact]
    public void A_slug_is_looked_for_in_its_collection_only()
    {
        var catalog = CatalogOfProjects(Ranks(TranslatedProject("momocad")));

        Assert.Null(catalog.Find(CollectionKind.Article, "momocad", Language.Fr));
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public void The_oldest_of_two_French_duplicates_keeps_the_slugs_they_share(bool olderListedFirst)
    {
        // Two French sheets share the translation key "dup", an anomaly the plugin
        // reports: "dup" (post 100) and its copy "dup-copy" (post 101). At both ranks,
        // the German and English lists serve the group's German and English sheets,
        // whose translations name the older French sheet, the plugin's winner.
        var group = Group("dup", "dup-de", "dup-en");
        var german = Project("dup-de", Language.De, group, wordPressId: 200);
        var english = Project("dup-en", Language.En, group, wordPressId: 300);
        var older = (Fr: Project("dup", Language.Fr, group, wordPressId: 100), De: german, En: english);
        var newer = (
            Fr: Project("dup-copy", Language.Fr, Group("dup-copy", "dup-de", "dup-en"), wordPressId: 101, translationKey: "dup"),
            De: german,
            En: english);
        var catalog = CatalogOfProjects(olderListedFirst ? Ranks(older, newer) : Ranks(newer, older));

        // The slugs both ranks claim lead French readers to the older sheet, in either order.
        Assert.Same(older.Fr, catalog.Find(CollectionKind.Project, "dup", Language.Fr));
        Assert.Same(older.Fr, catalog.Find(CollectionKind.Project, "dup-de", Language.Fr));
        Assert.Same(older.Fr, catalog.Find(CollectionKind.Project, "dup-en", Language.Fr));
        // The copy keeps its own slug, as the plugin serves the document a reader names...
        Assert.Same(newer.Fr, catalog.Find(CollectionKind.Project, "dup-copy", Language.Fr));
        // ...and that slug leads German readers to the group's German sheet.
        Assert.Same(german, catalog.Find(CollectionKind.Project, "dup-copy", Language.De));
    }
}

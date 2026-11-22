using Portfolio.Application.Documents;
using Portfolio.TestSupport;
using static Portfolio.TestSupport.TestDocuments;

namespace Portfolio.Application.Tests;

/// <summary>Building a catalog from the lists of the plugin (ADR-026, point 2).</summary>
public sealed class CatalogBuildTests
{
    [Fact]
    public void Three_lists_that_line_up_rank_by_rank_build_a_catalog()
    {
        var momocad = TranslatedProject("momocad");
        var atlas = TranslatedProject("atlas");

        var catalog = Catalog.Build(LanguageLists.Empty, Ranks(momocad, atlas), LanguageLists.Empty).AssertBuilt();

        Assert.Same(atlas.De, catalog.Find(CollectionKind.Project, "atlas", Language.De));
    }

    [Fact]
    public void Collections_without_any_document_build_a_catalog()
    {
        // The articles of the site today.
        var catalog = Catalog.Build(LanguageLists.Empty, LanguageLists.Empty, LanguageLists.Empty).AssertBuilt();

        Assert.Equal(0, catalog.List(CollectionKind.Article, new CatalogQuery()).Total);
    }

    [Fact]
    public void Lists_of_different_lengths_are_refused()
    {
        var momocad = TranslatedProject("momocad");
        var atlas = TranslatedProject("atlas");
        var projects = new LanguageLists([momocad.Fr, atlas.Fr], [momocad.De, atlas.De], [momocad.En]);

        var misaligned = Catalog.Build(LanguageLists.Empty, projects, LanguageLists.Empty).AssertMisaligned();

        Assert.Equal(CollectionKind.Project, misaligned.Collection);
        Assert.Contains("length", misaligned.Reason);
    }

    [Fact]
    public void A_rank_that_does_not_carry_the_same_translation_key_in_every_list_is_refused()
    {
        // The German list holds the two groups in the other order, as if a sheet had
        // been published between the reading of the French list and that of the German one.
        var momocad = TranslatedProject("momocad");
        var atlas = TranslatedProject("atlas");
        var projects = new LanguageLists([momocad.Fr, atlas.Fr], [atlas.De, momocad.De], [momocad.En, atlas.En]);

        var misaligned = Catalog.Build(LanguageLists.Empty, projects, LanguageLists.Empty).AssertMisaligned();

        Assert.Equal(CollectionKind.Project, misaligned.Collection);
        Assert.Contains("rank 0", misaligned.Reason);
    }

    [Fact]
    public void The_collection_that_does_not_line_up_is_named()
    {
        var cv = Page("cv", Language.Fr, Group("cv"));
        var pages = new LanguageLists([cv], [], []);

        var misaligned = Catalog.Build(LanguageLists.Empty, LanguageLists.Empty, pages).AssertMisaligned();

        Assert.Equal(CollectionKind.Page, misaligned.Collection);
    }

    [Fact]
    public void A_catalog_does_not_change_when_the_lists_it_was_built_from_do()
    {
        var momocad = TranslatedProject("momocad");
        var atlas = TranslatedProject("atlas");
        Document[] french = [momocad.Fr];
        var catalog = CatalogOfProjects(new LanguageLists(french, [momocad.De], [momocad.En]));

        french[0] = atlas.Fr;

        Assert.Same(momocad.Fr, Assert.Single(catalog.List(CollectionKind.Project, new CatalogQuery()).Items));
    }
}

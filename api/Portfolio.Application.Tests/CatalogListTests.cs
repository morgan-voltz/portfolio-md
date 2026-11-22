using Portfolio.Application.Documents;
using Portfolio.Application.Tests.TestData;
using static Portfolio.Application.Tests.TestData.TestDocuments;

namespace Portfolio.Application.Tests;

/// <summary>
/// Reading a page of a collection (ADR-021, ADR-026 points 3 and 5): the
/// plugin's order, filters on the French document, pages in the language
/// asked.
/// </summary>
public sealed class CatalogListTests
{
    private static readonly Tag DotNet = new("dotnet", ".NET");
    private static readonly Tag Rust = new("rust", "Rust");
    private static readonly Status Stable = new(ProjectStatus.Stable, "Stable");
    private static readonly Status Ongoing = new(ProjectStatus.EnCours, "En cours");

    [Fact]
    public void A_list_keeps_the_order_of_the_plugin_and_serves_the_language_asked()
    {
        var momocad = TranslatedProject("momocad");
        var atlas = TranslatedProject("atlas");
        var vefta = TranslatedProject("vefta");
        var catalog = CatalogOfProjects(Ranks(momocad, atlas, vefta));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(Language.De));

        Assert.Equal<Document>([momocad.De, atlas.De, vefta.De], page.Items);
        Assert.Equal(3, page.Total);
    }

    [Fact]
    public void A_page_holds_at_most_the_page_size_and_its_totals_count_the_whole_collection()
    {
        var ranks = Enumerable.Range(1, 14).Select(number => TranslatedProject($"project-{number}")).ToArray();
        var catalog = CatalogOfProjects(Ranks(ranks));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(page: 2));

        Assert.Equal<Document>([.. ranks[10..].Select(rank => rank.Fr)], page.Items);
        Assert.Equal(2, page.Page);
        Assert.Equal(10, page.PerPage);
        Assert.Equal(14, page.Total);
        Assert.Equal(2, page.TotalPages);
    }

    [Fact]
    public void A_page_beyond_the_last_is_empty_with_the_real_totals()
    {
        // Where the plugin answers 400, a deliberate difference (ADR-026, point 5).
        var ranks = Enumerable.Range(1, 14).Select(number => TranslatedProject($"project-{number}")).ToArray();
        var catalog = CatalogOfProjects(Ranks(ranks));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(page: 3));

        Assert.Empty(page.Items);
        Assert.Equal(14, page.Total);
        Assert.Equal(2, page.TotalPages);
    }

    [Fact]
    public void A_page_too_far_to_count_its_offset_is_empty_rather_than_the_first()
    {
        // (int.MaxValue - 1) * 100 overflows an int: computed as one, the offset turns
        // negative and the page would start from the first document.
        var catalog = CatalogOfProjects(Ranks(TranslatedProject("momocad")));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(page: int.MaxValue, perPage: 100));

        Assert.Empty(page.Items);
        Assert.Equal(1, page.Total);
    }

    [Fact]
    public void An_empty_collection_has_no_page()
    {
        var catalog = CatalogOfProjects(LanguageLists.Empty);

        var page = catalog.List(CollectionKind.Project, new CatalogQuery());

        Assert.Empty(page.Items);
        Assert.Equal(0, page.Total);
        Assert.Equal(0, page.TotalPages);
    }

    [Fact]
    public void The_tag_filter_looks_at_the_French_document_of_each_rank()
    {
        // At the first rank only the French sheet carries the tag, at the second only
        // the German one: a German reader gets the first, as from the plugin.
        var firstGroup = Group("first", "first-de", "first-en");
        var first = (
            Fr: Project("first", Language.Fr, firstGroup, tags: [DotNet]),
            De: Project("first-de", Language.De, firstGroup),
            En: Project("first-en", Language.En, firstGroup));
        var secondGroup = Group("second", "second-de", "second-en");
        var second = (
            Fr: Project("second", Language.Fr, secondGroup),
            De: Project("second-de", Language.De, secondGroup, tags: [DotNet]),
            En: Project("second-en", Language.En, secondGroup));
        var catalog = CatalogOfProjects(Ranks(first, second));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(Language.De, tag: "dotnet"));

        Assert.Same(first.De, Assert.Single(page.Items));
        Assert.Equal(1, page.Total);
    }

    [Fact]
    public void The_status_filter_keeps_the_projects_in_that_state()
    {
        var stable = Project("stable", Language.Fr, Group("stable"), status: Stable);
        var ongoing = Project("ongoing", Language.Fr, Group("ongoing"), status: Ongoing);
        var withoutStatus = Project("without-status", Language.Fr, Group("without-status"));
        var catalog = CatalogOfProjects(Ranks(Untranslated(stable), Untranslated(ongoing), Untranslated(withoutStatus)));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(status: ProjectStatus.EnCours));

        Assert.Equal("ongoing", Assert.Single(page.Items).Slug);
    }

    [Fact]
    public void The_competency_filter_takes_the_slug_of_a_competency_not_that_of_its_referential()
    {
        // The plugin also accepts the slug of a referential; the API does not (ADR-026, point 5).
        var uml = new Skill("e04-diagramme-de-classes-uml", "4.0.2/E04", "Modéliser en UML", Official: false);
        var momocad = Project("momocad", Language.Fr, Group("momocad"), skills: [uml]);
        var catalog = CatalogOfProjects(Ranks(Untranslated(momocad)));

        Assert.Single(catalog.List(CollectionKind.Project, new CatalogQuery(skill: "e04-diagramme-de-classes-uml")).Items);
        Assert.Empty(catalog.List(CollectionKind.Project, new CatalogQuery(skill: "4-0-2")).Items);
    }

    [Fact]
    public void The_filters_add_up()
    {
        var wanted = Project("wanted", Language.Fr, Group("wanted"), tags: [DotNet], status: Stable);
        var otherState = Project("other-state", Language.Fr, Group("other-state"), tags: [DotNet], status: Ongoing);
        var otherTag = Project("other-tag", Language.Fr, Group("other-tag"), tags: [Rust], status: Stable);
        var catalog = CatalogOfProjects(Ranks(Untranslated(wanted), Untranslated(otherState), Untranslated(otherTag)));

        var page = catalog.List(CollectionKind.Project, new CatalogQuery(tag: "dotnet", status: ProjectStatus.Stable));

        Assert.Equal("wanted", Assert.Single(page.Items).Slug);
    }

    [Fact]
    public void A_filter_an_article_cannot_carry_matches_no_article()
    {
        var article = Article("first-article", Language.Fr, Group("first-article"), tags: [DotNet]);
        var catalog = Catalog.Build(Ranks(Untranslated(article)), LanguageLists.Empty, LanguageLists.Empty).AssertBuilt();

        Assert.Single(catalog.List(CollectionKind.Article, new CatalogQuery(tag: "dotnet")).Items);
        Assert.Empty(catalog.List(CollectionKind.Article, new CatalogQuery(status: ProjectStatus.Stable)).Items);
        Assert.Empty(catalog.List(CollectionKind.Article, new CatalogQuery(skill: "e04-diagramme-de-classes-uml")).Items);
    }

    [Fact]
    public void A_tag_filter_matches_no_page()
    {
        var cv = Page("cv", Language.Fr, Group("cv"));
        var catalog = Catalog.Build(LanguageLists.Empty, LanguageLists.Empty, Ranks(Untranslated(cv))).AssertBuilt();

        Assert.Empty(catalog.List(CollectionKind.Page, new CatalogQuery(tag: "dotnet")).Items);
    }
}

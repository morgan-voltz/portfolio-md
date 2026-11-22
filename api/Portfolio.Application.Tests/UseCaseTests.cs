using Portfolio.TestSupport;
using Portfolio.Application.UseCases;
using static Portfolio.TestSupport.TestDocuments;

namespace Portfolio.Application.Tests;

/// <summary>The two use cases read the current catalog of the store and nothing else.</summary>
public sealed class UseCaseTests
{
    [Fact]
    public void Before_the_first_catalog_reading_a_document_answers_that_the_catalog_is_not_ready()
    {
        var getDocument = new GetDocumentBySlug(new CatalogStore());

        Assert.True(getDocument.Execute(CollectionKind.Project, "momocad", Language.Fr) is CatalogNotReady);
    }

    [Fact]
    public void Before_the_first_catalog_reading_a_page_answers_that_the_catalog_is_not_ready()
    {
        var listDocuments = new ListDocuments(new CatalogStore());

        Assert.True(listDocuments.Execute(CollectionKind.Project, new CatalogQuery()) is CatalogNotReady);
    }

    [Fact]
    public void A_known_slug_gives_the_document_of_the_current_catalog()
    {
        var momocad = TranslatedProject("momocad");
        var store = new CatalogStore();
        store.Replace(Edition(CatalogOfProjects(Ranks(momocad))));

        var found = new GetDocumentBySlug(store).Execute(CollectionKind.Project, "momocad-de", Language.Fr).AssertFound();

        Assert.Same(momocad.Fr, found);
    }

    [Fact]
    public void An_unknown_slug_is_not_found()
    {
        var store = new CatalogStore();
        store.Replace(Edition(CatalogOfProjects(Ranks(TranslatedProject("momocad")))));

        Assert.True(new GetDocumentBySlug(store).Execute(CollectionKind.Project, "atlas", Language.Fr) is NotFound);
    }

    [Fact]
    public void Reading_a_document_follows_the_catalog_of_the_store_from_one_call_to_the_next()
    {
        // The HTTP layer will build a use case once, possibly before the first catalog exists:
        // the use case must read the store at each call, never keep the catalog it saw first.
        var momocad = TranslatedProject("momocad");
        var store = new CatalogStore();
        var getDocument = new GetDocumentBySlug(store);

        store.Replace(Edition(CatalogOfProjects(Ranks(momocad))));
        var first = getDocument.Execute(CollectionKind.Project, "momocad", Language.Fr);
        store.Replace(Edition(CatalogOfProjects(Ranks(TranslatedProject("atlas")))));
        var second = getDocument.Execute(CollectionKind.Project, "momocad", Language.Fr);

        Assert.Same(momocad.Fr, first.AssertFound());
        Assert.True(second is NotFound);
    }

    [Fact]
    public void Reading_a_page_follows_the_catalog_of_the_store_from_one_call_to_the_next()
    {
        var previous = TranslatedProject("momocad");
        var latest = TranslatedProject("atlas");
        var store = new CatalogStore();
        var listDocuments = new ListDocuments(store);

        store.Replace(Edition(CatalogOfProjects(Ranks(previous))));
        var first = listDocuments.Execute(CollectionKind.Project, new CatalogQuery(Language.En));
        store.Replace(Edition(CatalogOfProjects(Ranks(latest))));
        var second = listDocuments.Execute(CollectionKind.Project, new CatalogQuery(Language.En));

        Assert.Same(previous.En, Assert.Single(first.AssertListed().Items));
        Assert.Same(latest.En, Assert.Single(second.AssertListed().Items));
    }
}

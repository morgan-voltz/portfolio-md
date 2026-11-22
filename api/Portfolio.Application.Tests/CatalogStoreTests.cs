using static Portfolio.TestSupport.TestDocuments;

namespace Portfolio.Application.Tests;

public sealed class CatalogStoreTests
{
    [Fact]
    public void A_store_holds_no_catalog_until_one_is_given()
    {
        var store = new CatalogStore();

        Assert.Null(store.Edition);
        Assert.Null(store.Current);
    }

    [Fact]
    public void A_store_holds_the_last_edition_it_was_given_and_serves_its_catalog()
    {
        var store = new CatalogStore();
        var first = Edition(EmptyCatalog());
        var second = Edition(EmptyCatalog());

        store.Replace(first);
        store.Replace(second);

        Assert.Same(second, store.Edition);
        Assert.Same(second.Catalog, store.Current);
    }

    [Fact]
    public void A_store_cannot_be_emptied_again()
    {
        var store = new CatalogStore();

        Assert.Throws<ArgumentNullException>(() => store.Replace(null!));
    }
}

using static Portfolio.Application.Tests.TestData.TestDocuments;

namespace Portfolio.Application.Tests;

public sealed class CatalogStoreTests
{
    [Fact]
    public void A_store_holds_no_catalog_until_one_is_given()
    {
        Assert.Null(new CatalogStore().Current);
    }

    [Fact]
    public void A_store_holds_the_last_catalog_it_was_given()
    {
        var store = new CatalogStore();
        var first = CatalogOfProjects(LanguageLists.Empty);
        var second = CatalogOfProjects(LanguageLists.Empty);

        store.Replace(first);
        store.Replace(second);

        Assert.Same(second, store.Current);
    }

    [Fact]
    public void A_store_cannot_be_emptied_again()
    {
        var store = new CatalogStore();

        Assert.Throws<ArgumentNullException>(() => store.Replace(null!));
    }
}

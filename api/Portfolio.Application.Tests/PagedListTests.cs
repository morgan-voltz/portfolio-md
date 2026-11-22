namespace Portfolio.Application.Tests;

public sealed class PagedListTests
{
    [Theory]
    [InlineData(0, 10, 0)]
    [InlineData(1, 10, 1)]
    [InlineData(10, 10, 1)]
    [InlineData(11, 10, 2)]
    [InlineData(14, 10, 2)]
    [InlineData(101, 100, 2)]
    public void The_number_of_pages_is_the_total_divided_by_the_page_size_rounded_up(int total, int perPage, int totalPages)
    {
        // 0 for an empty collection, like the plugin's CatalogPage.
        var page = new PagedList<string>([], Page: 1, PerPage: perPage, Total: total);

        Assert.Equal(totalPages, page.TotalPages);
    }
}

namespace Portfolio.Application.Tests;

/// <summary>The rules of a query, those of the plugin's own <c>CatalogQuery</c>.</summary>
public sealed class CatalogQueryTests
{
    [Fact]
    public void A_query_asks_by_default_for_the_first_ten_documents_in_French_without_filter()
    {
        var query = new CatalogQuery();

        Assert.Equal(Language.Fr, query.Language);
        Assert.Equal(1, query.Page);
        Assert.Equal(10, query.PerPage);
        Assert.Null(query.Tag);
        Assert.Null(query.Status);
        Assert.Null(query.Skill);
    }

    [Theory]
    [InlineData(0)]
    [InlineData(-1)]
    public void A_page_before_the_first_is_refused(int page)
    {
        var refused = Assert.Throws<ArgumentOutOfRangeException>(() => new CatalogQuery(page: page));

        Assert.Equal("page", refused.ParamName);
    }

    [Theory]
    [InlineData(0)]
    [InlineData(101)]
    public void A_page_size_outside_one_to_a_hundred_is_refused(int perPage)
    {
        var refused = Assert.Throws<ArgumentOutOfRangeException>(() => new CatalogQuery(perPage: perPage));

        Assert.Equal("perPage", refused.ParamName);
    }

    [Theory]
    [InlineData(1)]
    [InlineData(100)]
    public void A_page_size_of_one_or_a_hundred_is_accepted(int perPage)
    {
        Assert.Equal(perPage, new CatalogQuery(perPage: perPage).PerPage);
    }

    [Fact]
    public void A_tag_that_is_not_a_slug_is_refused()
    {
        var refused = Assert.Throws<ArgumentException>(() => new CatalogQuery(tag: "Dot Net"));

        Assert.Equal("tag", refused.ParamName);
    }

    [Fact]
    public void A_competency_that_is_not_a_slug_is_refused()
    {
        var refused = Assert.Throws<ArgumentException>(() => new CatalogQuery(skill: "4.0.2/E04"));

        Assert.Equal("skill", refused.ParamName);
    }
}

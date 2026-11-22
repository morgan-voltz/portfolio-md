namespace Portfolio.Application.Tests;

/// <summary>The shape of a slug, the plugin's rule (<c>Patterns::SLUG</c>).</summary>
public sealed class SlugsTests
{
    [Theory]
    [InlineData("momocad")]
    [InlineData("carbon-fr")]
    [InlineData("4-0-2")]
    [InlineData("e04-diagramme-de-classes-uml")]
    public void Lowercase_words_joined_by_single_hyphens_are_slugs(string candidate)
    {
        Assert.True(Slugs.IsValid(candidate));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("MomoCad")]
    [InlineData("momo cad")]
    [InlineData("-momocad")]
    [InlineData("momocad-")]
    [InlineData("momo--cad")]
    [InlineData("momocad\n")]
    [InlineData("élève")]
    public void Anything_else_is_not(string? candidate)
    {
        // "momocad\n": the plugin's pattern ends with $/D, the very end of the
        // text, which a plain $ would not be in .NET.
        Assert.False(Slugs.IsValid(candidate));
    }
}

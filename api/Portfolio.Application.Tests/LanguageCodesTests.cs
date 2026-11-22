namespace Portfolio.Application.Tests;

/// <summary>The codes of the languages, the plugin's own (ADR-021).</summary>
public sealed class LanguageCodesTests
{
    [Theory]
    [InlineData(Language.Fr, "fr")]
    [InlineData(Language.De, "de")]
    [InlineData(Language.En, "en")]
    public void A_language_and_its_code_lead_to_each_other(Language language, string code)
    {
        Assert.Equal(code, language.Code());
        Assert.Equal(language, LanguageCodes.FromCode(code));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("FR")]
    [InlineData(" fr")]
    [InlineData("it")]
    public void Anything_else_is_no_language(string? code)
    {
        // "FR": the plugin answers 400 to language=FR, the API will do the same.
        Assert.Null(LanguageCodes.FromCode(code));
    }
}

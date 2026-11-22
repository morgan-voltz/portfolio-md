namespace Portfolio.Application;

/// <summary>
/// The code of each language of the site, as the plugin writes it in its
/// answers and reads it in its <c>language</c> parameter: <c>fr</c>,
/// <c>de</c>, <c>en</c> (ADR-021).
/// </summary>
/// <remarks>
/// In the core, because both sides of the API need the same three codes: the
/// client of the plugin (lot 3) writes them in its requests and reads them in
/// the answers, and the HTTP routes (lot 5) will read <c>?language=</c> and
/// write <c>lang</c>. Lowercase only, like the plugin, which answers 400 to
/// <c>language=FR</c>.
/// </remarks>
public static class LanguageCodes
{
    /// <summary>The code of <paramref name="language"/>.</summary>
    public static string Code(this Language language) => language switch
    {
        Language.Fr => "fr",
        Language.De => "de",
        Language.En => "en",
        _ => throw new ArgumentOutOfRangeException(nameof(language), language, "Not a language of the site."),
    };

    /// <summary>The language whose code is <paramref name="code"/>, or <see langword="null"/> when none has it.</summary>
    public static Language? FromCode(string? code) => code switch
    {
        "fr" => Language.Fr,
        "de" => Language.De,
        "en" => Language.En,
        _ => null,
    };
}

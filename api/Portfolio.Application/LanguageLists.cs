using Portfolio.Application.Documents;

namespace Portfolio.Application;

/// <summary>
/// The three lists of one collection, as the plugin answers
/// <c>GET /{collection}?language=fr</c>, <c>de</c> and <c>en</c>: each one
/// complete and in the plugin's order (newest first).
/// </summary>
/// <remarks>
/// The plugin anchors the three on the French documents (ADR-021): one entry
/// per French document, at the same rank in every list, each list serving at
/// that rank the member of the group in its own language, or the nearest one
/// along the fallback chain when the translation is missing. So the three
/// lists have the same length and the same translation key at each rank,
/// which <see cref="Catalog.Build"/> checks.
/// </remarks>
public sealed record LanguageLists(IReadOnlyList<Document> Fr, IReadOnlyList<Document> De, IReadOnlyList<Document> En)
{
    /// <summary>A collection without any document, like the articles of the site today.</summary>
    public static LanguageLists Empty { get; } = new([], [], []);

    /// <summary>The list of one language.</summary>
    public IReadOnlyList<Document> In(Language language) => language switch
    {
        Language.Fr => Fr,
        Language.De => De,
        Language.En => En,
        _ => throw new ArgumentOutOfRangeException(nameof(language), language, "Not a language of the site."),
    };
}

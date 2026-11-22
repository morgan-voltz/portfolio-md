namespace Portfolio.Application.Ports;

/// <summary>
/// One line of the plugin's <c>/sitemap</c>: a published document in its own
/// language, never a fallback (ADR-021).
/// </summary>
/// <remarks>
/// <see cref="Updated"/> is not a reliable time of change: for most documents
/// it is the date of their front matter at midnight. The refresh of lot 5
/// compares whole lines, never these instants (ADR-026, context).
/// </remarks>
public sealed record SitemapLine(CollectionKind Collection, string Slug, Language Lang, string TranslationKey, DateTimeOffset Updated);

/// <summary>Every published document of the site, in every language, one line each.</summary>
/// <remarks>
/// The plugin groups its lines by collection (<c>{"articles": [...], "projects": [...], "pages": [...]}</c>);
/// the client of lot 3 puts them in one list, each line keeping its collection.
/// </remarks>
public sealed record Sitemap(IReadOnlyList<SitemapLine> Lines);

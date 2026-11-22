using Portfolio.Application.Documents;

namespace Portfolio.Application.Ports;

/// <summary>
/// What the catalog needs from the WordPress plugin (ADR-026, points 2 and 7).
/// The core says what it needs; lot 3 implements it over HTTP, and the refresh
/// of lot 5 calls it.
/// </summary>
public interface IWordPressClient
{
    /// <summary>The plugin's <c>/sitemap</c>.</summary>
    Task<Fetch<Sitemap>> GetSitemapAsync(CancellationToken cancellationToken);

    /// <summary>
    /// The complete list of a collection served in a language, in the plugin's
    /// order: the client reads every page of it, a hundred documents at a time.
    /// </summary>
    Task<Fetch<IReadOnlyList<Document>>> ListAsync(CollectionKind collection, Language language, CancellationToken cancellationToken);
}

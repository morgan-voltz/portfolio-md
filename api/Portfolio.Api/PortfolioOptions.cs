namespace Portfolio.Api;

/// <summary>
/// The settings of the API, the section <c>Portfolio</c> of the configuration.
/// In production they come from the environment, the double underscore
/// separating the sections: <c>PORTFOLIO__WORDPRESSBASEURL</c>,
/// <c>PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS</c> (ADR-023, point 5;
/// <c>deploy/.env.example</c>).
/// </summary>
/// <remarks>
/// Lot 5 checks only the two periods of the refresh as the API starts
/// (Program.cs); lot 7 validates the others (ADR-026, point 11).
/// </remarks>
public sealed class PortfolioOptions
{
    public const string Section = "Portfolio";

    /// <summary>The root of the plugin's routes, ending with a slash: <c>https://morganvoltz.dev/wp-json/portfolio/v1/</c>.</summary>
    public string WordPressBaseUrl { get; set; } = "";

    public CacheOptions Cache { get; set; } = new();

    /// <summary>
    /// <see cref="WordPressBaseUrl"/> as an address, or an error that names the
    /// variable to set. The client of the plugin checks the final slash.
    /// </summary>
    public Uri PluginRoot() =>
        Uri.TryCreate(WordPressBaseUrl, UriKind.Absolute, out var root) && (root.Scheme == Uri.UriSchemeHttps || root.Scheme == Uri.UriSchemeHttp)
            ? root
            : throw new InvalidOperationException(
                "PORTFOLIO__WORDPRESSBASEURL must be the http or https address of the plugin's routes, "
                + $"such as https://morganvoltz.dev/wp-json/portfolio/v1/; it is \"{WordPressBaseUrl}\".");
}

/// <summary>
/// The refresh of the catalog (ADR-026, point 2). The section keeps the name
/// <c>Cache</c> that ADR-023 gave its variables, though the API now holds a
/// catalog.
/// </summary>
public sealed class CacheOptions
{
    /// <summary>
    /// The period of the refresh once a catalog exists, in seconds, from 1 to
    /// 86400 (<c>PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS</c>): each tick reads
    /// the sitemap.
    /// </summary>
    public int SitemapRefreshSeconds { get; set; } = 60;

    /// <summary>
    /// The age past which the catalog is reloaded whatever the sitemap says, in
    /// minutes, at least 1 (<c>PORTFOLIO__CACHE__FULLREFRESHMINUTES</c>).
    /// </summary>
    public int FullRefreshMinutes { get; set; } = 360;
}

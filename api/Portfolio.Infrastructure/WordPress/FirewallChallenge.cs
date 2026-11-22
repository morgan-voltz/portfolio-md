namespace Portfolio.Infrastructure.WordPress;

/// <summary>
/// The page the host's firewall answers instead of the site, for a minute or
/// two, to a client it suspects (Tiger Protect at o2switch: an HTML page
/// titled "Security check" that posts to <c>/o2s-cgi/security-challenge</c>).
/// </summary>
/// <remarks>
/// The same rule and the same words as the content sync
/// (<c>.github/scripts/sync-content.py</c>, function <c>challenge</c>), so
/// that the two logs name it alike, in one line rather than with its markup
/// (ADR-023, ADR-026 point 2).
/// </remarks>
internal static class FirewallChallenge
{
    public const string Description = "the host's firewall answered a JavaScript challenge page instead of the site (Tiger Protect)";

    /// <summary>Whether <paramref name="body"/>, an answer that is not JSON, is that page.</summary>
    public static bool IsIn(string body) =>
        body.Contains("<html", StringComparison.OrdinalIgnoreCase)
        && body.Contains("security-challenge", StringComparison.OrdinalIgnoreCase);
}

using Portfolio.Application.Ports;

namespace Portfolio.Application;

/// <summary>
/// The catalog the API serves, with what the refresh needs to judge it: the
/// sitemap it was built from, and when it was built (ADR-026, point 2).
/// </summary>
/// <remarks>
/// The three travel together because they are written together:
/// <see cref="CatalogStore"/> swaps them in one write, so that the refresh
/// never compares a new sitemap with a catalog built from another one.
/// </remarks>
public sealed record CatalogEdition(Catalog Catalog, Sitemap Sitemap, DateTimeOffset BuiltAt);

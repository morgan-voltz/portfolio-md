using Portfolio.Application.Ports;

namespace Portfolio.Application.UseCases;

/// <summary>
/// The sitemap has the same lines as the one the current catalog was built
/// from, and that catalog is younger than the full reload period: nothing to
/// do (ADR-026, point 2).
/// </summary>
public sealed record Unchanged;

/// <summary>
/// A document left out of the catalog because a rendering failed: its own, or
/// that of another document of its rank, since the three lists of a collection
/// must stay aligned (see <see cref="RefreshCatalog"/>). The reason says which.
/// </summary>
public sealed record DiscardedDocument(CollectionKind Collection, string Slug, Language Lang, string Reason);

/// <summary>
/// A new catalog replaced the previous one. It carries what the log must say
/// (ADR-026, points 2 and 3), since the core has no logger: the documents
/// discarded because a rendering failed, and the other lines of the sitemap
/// that lead to no document of the new catalog.
/// </summary>
public sealed record Reloaded(IReadOnlyList<DiscardedDocument> Discarded, IReadOnlyList<SitemapLine> Unmatched);

/// <summary>
/// What one tick of <see cref="RefreshCatalog"/> did: nothing, a new catalog,
/// or nothing because the plugin could not be read, in which case the catalog
/// stays as it was (ADR-026, points 2 and 6).
/// </summary>
public union RefreshOutcome(Unchanged, Reloaded, SourceUnavailable);

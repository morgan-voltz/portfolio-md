namespace Portfolio.Application;

/// <summary>
/// The three lists of a collection do not line up rank by rank: they differ in
/// length, or a rank does not carry the same translation key in all three.
/// </summary>
/// <remarks>
/// Since the plugin anchors the three lists on the French documents, this only
/// happens when the content changed while the lists were being read; the
/// refresh then keeps the catalog it has and tries again later (ADR-026,
/// point 2).
/// </remarks>
public sealed record RanksMisaligned(CollectionKind Collection, string Reason);

/// <summary>What <see cref="Catalog.Build"/> gives: a catalog, or why it could not build one.</summary>
public union CatalogBuildOutcome(Catalog, RanksMisaligned);

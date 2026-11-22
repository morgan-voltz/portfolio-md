namespace Portfolio.Application;

/// <summary>
/// The one changing piece of the API: the current edition of the catalog,
/// <see langword="null"/> until the first one is built (ADR-026, points 1 and 6).
/// </summary>
/// <remarks>
/// <para>
/// The refresh replaces the edition in a single write, and a request reads it
/// once, then works on that catalog to the end. <see cref="Volatile"/> makes
/// the write visible to the threads that serve the requests, without a lock:
/// an edition never changes once built, so a reader gets the old one or the
/// new one, never half of each.
/// </para>
/// <para>
/// Nothing empties the store: once there is a catalog, there always is one,
/// which is why <c>/health/ready</c> never closes again (ADR-026, point 4).
/// </para>
/// </remarks>
public sealed class CatalogStore
{
    private CatalogEdition? _edition;

    /// <summary>The catalog the requests read.</summary>
    public Catalog? Current => Edition?.Catalog;

    /// <summary>The catalog with its sitemap and the time it was built, read in one go: what the refresh compares.</summary>
    public CatalogEdition? Edition => Volatile.Read(ref _edition);

    public void Replace(CatalogEdition edition)
    {
        ArgumentNullException.ThrowIfNull(edition);
        Volatile.Write(ref _edition, edition);
    }
}

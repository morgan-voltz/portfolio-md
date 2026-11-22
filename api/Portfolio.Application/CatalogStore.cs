namespace Portfolio.Application;

/// <summary>
/// The one changing piece of the API: the current catalog,
/// <see langword="null"/> until the first one is built (ADR-026, points 1 and 6).
/// </summary>
/// <remarks>
/// The refresh replaces the catalog in a single write, and a request reads it
/// once, then works on that catalog to the end. <see cref="Volatile"/> makes the
/// write visible to the threads that serve the requests, without a lock: a
/// catalog never changes once built, so a reader gets the old one or the new
/// one, never half of each.
/// </remarks>
public sealed class CatalogStore
{
    private Catalog? _current;

    public Catalog? Current => Volatile.Read(ref _current);

    public void Replace(Catalog catalog)
    {
        ArgumentNullException.ThrowIfNull(catalog);
        Volatile.Write(ref _current, catalog);
    }
}

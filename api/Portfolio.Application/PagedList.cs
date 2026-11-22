namespace Portfolio.Application;

/// <summary>
/// One page of a collection, with the size of the whole collection: enough for
/// a reader to paginate without asking again.
/// </summary>
/// <remarks>
/// Built by the catalog from a <see cref="CatalogQuery"/>, whose page size is
/// at least 1.
/// </remarks>
public sealed record PagedList<T>(IReadOnlyList<T> Items, int Page, int PerPage, int Total)
{
    /// <summary>
    /// The number of pages of the whole collection, 0 when it is empty, like
    /// the plugin. A division rounded up, in whole numbers.
    /// </summary>
    public int TotalPages => (Total + PerPage - 1) / PerPage;
}

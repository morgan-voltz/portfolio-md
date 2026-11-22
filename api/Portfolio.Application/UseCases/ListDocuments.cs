namespace Portfolio.Application.UseCases;

/// <summary>
/// Reads one page of a collection (ADR-026, points 3 and 6): one use case for
/// the three collections, which reads the current catalog and nothing else.
/// </summary>
public sealed class ListDocuments(CatalogStore store)
{
    public DocumentListing Execute(CollectionKind collection, CatalogQuery query) =>
        store.Current is { } catalog ? catalog.List(collection, query) : new CatalogNotReady();
}

namespace Portfolio.Application.UseCases;

/// <summary>
/// Reads one document by its slug, in a language (ADR-026, points 3 and 6):
/// one use case for the three collections.
/// </summary>
/// <remarks>
/// It never calls WordPress: it reads the current catalog and nothing else.
/// </remarks>
public sealed class GetDocumentBySlug(CatalogStore store)
{
    public DocumentLookup Execute(CollectionKind collection, string slug, Language language)
    {
        if (store.Current is not { } catalog)
        {
            return new CatalogNotReady();
        }

        return catalog.Find(collection, slug, language) is { } document ? document : new NotFound();
    }
}

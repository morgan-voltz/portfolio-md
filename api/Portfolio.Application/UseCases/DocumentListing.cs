using Portfolio.Application.Documents;

namespace Portfolio.Application.UseCases;

/// <summary>What <see cref="ListDocuments"/> answers: a page, or the reason there is none.</summary>
public union DocumentListing(PagedList<Document>, CatalogNotReady);

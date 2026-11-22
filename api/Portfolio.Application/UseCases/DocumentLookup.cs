using Portfolio.Application.Documents;

namespace Portfolio.Application.UseCases;

/// <summary>No document of the catalog is known under the slug that was asked.</summary>
public sealed record NotFound;

/// <summary>
/// No catalog has been built yet: the API has not been able to read the plugin
/// since it started (ADR-026, point 4).
/// </summary>
public sealed record CatalogNotReady;

/// <summary>
/// What <see cref="GetDocumentBySlug"/> answers: the document, or why there is
/// none. A union (C# 15): a <c>switch</c> over it must handle the three cases,
/// which the compiler checks, so that the HTTP route of lot 5 cannot forget one.
/// </summary>
public union DocumentLookup(Document, NotFound, CatalogNotReady);

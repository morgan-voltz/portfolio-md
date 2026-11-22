namespace Portfolio.Application;

/// <summary>
/// One of the three collections of the site (ADR-018): articles, projects,
/// static pages.
/// </summary>
/// <remarks>
/// The use cases take it as a parameter, so that one use case serves the three
/// collections instead of a twin per collection (ADR-026, point 6). A document
/// does not carry it: its type (<see cref="Documents.Article"/>,
/// <see cref="Documents.Project"/>, <see cref="Documents.Page"/>) already says
/// which collection it belongs to.
/// </remarks>
public enum CollectionKind
{
    Article,
    Project,
    Page,
}

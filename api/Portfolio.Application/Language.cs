namespace Portfolio.Application;

/// <summary>
/// A language of the site (ADR-020): French, German, English.
/// </summary>
/// <remarks>
/// Unlike the plugin's own <c>Language</c>, this one has no fallback chain.
/// The API never decides which translation a reader gets: the plugin decides
/// it (its <c>GroupResolver</c>) before answering, and each of the lists the
/// catalog is built from already holds, for its language, the document to
/// serve. Porting that rule here would compute a second time what every
/// answer of the plugin already says.
/// </remarks>
public enum Language
{
    Fr,
    De,
    En,
}

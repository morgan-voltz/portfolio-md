namespace Portfolio.Application.Ports;

/// <summary>
/// Renders the Markdown of a document to HTML (ADR-026, point 8); lot 4
/// implements it with Markdig.
/// </summary>
public interface IMarkdownRenderer
{
    /// <param name="markdown">The source, front matter included.</param>
    /// <param name="documentLanguage">
    /// The language of the document: a link to another document that names no
    /// language points to the version in this one.
    /// </param>
    string Render(string markdown, Language documentLanguage);
}

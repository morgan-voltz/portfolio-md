using System.Text.Json.Nodes;
using Portfolio.Application;

namespace Portfolio.Infrastructure.Tests.Support;

/// <summary>
/// The Markdown of every document in the recorded lists of the plugin
/// (<c>Recorded/</c>): the very sources the API renders, 54 on 2026-11-22.
/// </summary>
internal static class RecordedMarkdown
{
    public static IReadOnlyList<RecordedDocument> All() =>
    [
        .. from collection in new[] { "articles", "projects", "pages" }
           from language in new[] { "fr", "de", "en" }
           from document in JsonNode.Parse(RecordedAnswers.Body($"{collection}-{language}"))!.AsArray()
           select new RecordedDocument(
               $"{collection}/{document!["slug"]!.GetValue<string>()} ({language})",
               document["slug"]!.GetValue<string>(),
               document["markdown"]!.GetValue<string>(),
               LanguageCodes.FromCode(document["lang"]!.GetValue<string>())!.Value),
    ];
}

/// <summary>One recorded document: its name for the test output, its slug, its Markdown, its own language.</summary>
internal sealed record RecordedDocument(string Name, string Slug, string Markdown, Language Lang)
{
    public override string ToString() => Name;
}

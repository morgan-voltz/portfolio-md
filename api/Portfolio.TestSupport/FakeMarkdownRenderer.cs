using Portfolio.Application;
using Portfolio.Application.Ports;

namespace Portfolio.TestSupport;

/// <summary>
/// A renderer written by hand (ADR-026, point 10): its HTML says which source
/// it rendered with which language, it throws on the sources it is told to,
/// and it remembers every call.
/// </summary>
public sealed class FakeMarkdownRenderer : IMarkdownRenderer
{
    private readonly Lock _lock = new();
    private readonly HashSet<string> _failing = [];
    private readonly List<(string Markdown, Language Language)> _calls = [];

    /// <summary>Every rendering asked so far, in order.</summary>
    public IReadOnlyList<(string Markdown, Language Language)> Calls
    {
        get
        {
            lock (_lock)
            {
                return [.. _calls];
            }
        }
    }

    /// <summary>What <see cref="Render"/> gives for a source and a language.</summary>
    public static string HtmlOf(string markdown, Language language) => $"<p>{language.Code()}: {markdown}</p>";

    /// <summary>Rendering this source throws from now on.</summary>
    public FakeMarkdownRenderer FailOn(string markdown)
    {
        lock (_lock)
        {
            _failing.Add(markdown);
        }

        return this;
    }

    public string Render(string markdown, Language documentLanguage)
    {
        lock (_lock)
        {
            _calls.Add((markdown, documentLanguage));
            return _failing.Contains(markdown)
                ? throw new InvalidOperationException($"the fake renderer fails on \"{markdown}\"")
                : HtmlOf(markdown, documentLanguage);
        }
    }
}

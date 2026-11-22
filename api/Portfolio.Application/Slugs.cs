using System.Text.RegularExpressions;

namespace Portfolio.Application;

/// <summary>
/// The shape of every slug of the site: lowercase letters and digits, in words
/// joined by single hyphens (<c>momocad</c>, <c>carbon-fr</c>, <c>4-0-2</c>).
/// </summary>
/// <remarks>
/// The same rule as the plugin's <c>Patterns::SLUG</c>. The plugin ends its
/// pattern with <c>$/D</c>, "the very end of the text"; .NET writes that
/// <c>\z</c>, because <c>$</c> alone would also accept a trailing line break.
/// </remarks>
public static partial class Slugs
{
    /// <summary>Whether <paramref name="candidate"/> has the shape of a slug.</summary>
    public static bool IsValid(string? candidate) => candidate is not null && Pattern().IsMatch(candidate);

    // Compiled into C# at build time by the regular expression source generator.
    [GeneratedRegex(@"^[a-z0-9]+(?:-[a-z0-9]+)*\z")]
    private static partial Regex Pattern();
}

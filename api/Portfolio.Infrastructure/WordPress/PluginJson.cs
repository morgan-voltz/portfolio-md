using System.Text.Json;

namespace Portfolio.Infrastructure.WordPress;

/// <summary>
/// How the JSON of the plugin is read (01-plugin-php.md §7.3, ADR-026 point 7):
/// snake_case keys into the records of this file, which only this assembly
/// sees; the client turns them into documents of the core.
/// </summary>
/// <remarks>
/// Every key the plugin always writes is required, the nullable ones too
/// (<c>required string? Subtitle</c>: present, possibly <c>null</c>), and a
/// <c>null</c> where the plugin promises a value is refused. A key renamed or
/// dropped by a later version of the plugin then fails the reading, instead of
/// turning silently into <see langword="null"/> for every document. A key the
/// plugin adds is ignored until the API learns it.
/// </remarks>
internal static class PluginJson
{
    public static JsonSerializerOptions Options { get; } = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        RespectNullableAnnotations = true,
        RespectRequiredConstructorParameters = true,
    };
}

/// <summary>The keys every document of every collection carries.</summary>
internal abstract record PluginDocument
{
    public required int Id { get; init; }

    /// <summary><c>article</c>, <c>project</c> or <c>page</c>.</summary>
    public required string Type { get; init; }

    public required string Slug { get; init; }

    public required string Lang { get; init; }

    public required string TranslationKey { get; init; }

    public required Dictionary<string, string> Translations { get; init; }

    public required bool IsFallback { get; init; }

    public required string RequestedLanguage { get; init; }

    public required string Title { get; init; }

    /// <summary>Written for every kind of document since ADR-027 gave pages one too.</summary>
    public required string? Subtitle { get; init; }

    /// <summary>The permalink of the document on the WordPress site.</summary>
    public required string Link { get; init; }

    public required DateTimeOffset Date { get; init; }

    public required DateTimeOffset Updated { get; init; }

    public required string? SeoDescription { get; init; }

    public required string Markdown { get; init; }
}

internal sealed record PluginPage : PluginDocument
{
    /// <summary>The heading a page shows in place of its title (ADR-027).</summary>
    public required string? Headline { get; init; }
}

internal sealed record PluginArticle : PluginDocument
{
    public required IReadOnlyList<PluginTag> Tags { get; init; }

    public required int? ReadingTime { get; init; }

    public required bool Featured { get; init; }
}

internal sealed record PluginProject : PluginDocument
{
    public required IReadOnlyList<PluginTag> Tags { get; init; }

    public required int? ReadingTime { get; init; }

    public required bool Featured { get; init; }

    public required PluginStatus? Status { get; init; }

    public required IReadOnlyList<PluginSkill> Skills { get; init; }

    public required bool IsFilRouge { get; init; }

    public required string? Role { get; init; }

    public required DateOnly? PeriodStart { get; init; }

    public required DateOnly? PeriodEnd { get; init; }

    public required string? RepoUrl { get; init; }

    public required string? DemoUrl { get; init; }

    public required string? Client { get; init; }
}

internal sealed record PluginTag(string Slug, string Name);

internal sealed record PluginSkill(string Slug, string Code, string Label, bool Official);

internal sealed record PluginStatus(string Slug, string Label);

/// <summary><c>GET /sitemap</c>: one list per collection, each present even when empty.</summary>
internal sealed record PluginSitemap(
    IReadOnlyList<PluginSitemapLine> Articles,
    IReadOnlyList<PluginSitemapLine> Projects,
    IReadOnlyList<PluginSitemapLine> Pages);

internal sealed record PluginSitemapLine(string Slug, DateTimeOffset Updated, string Lang, string TranslationKey);

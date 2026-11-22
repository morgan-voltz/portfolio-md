namespace Portfolio.Application.Documents;

/// <summary>
/// The slug of each project state, as the plugin writes it (<c>en-cours</c>,
/// <c>stable</c>, <c>archive</c>, <c>prototype</c>).
/// </summary>
/// <remarks>
/// In the core for the same reason as <see cref="LanguageCodes"/>: the client
/// of the plugin (lot 3) reads these slugs in the answers, and the HTTP routes
/// (lot 5) will read <c>?status=</c> and write <c>status.slug</c>.
/// </remarks>
public static class ProjectStatusSlugs
{
    /// <summary>The slug of <paramref name="status"/>.</summary>
    public static string Slug(this ProjectStatus status) => status switch
    {
        ProjectStatus.EnCours => "en-cours",
        ProjectStatus.Stable => "stable",
        ProjectStatus.Archive => "archive",
        ProjectStatus.Prototype => "prototype",
        _ => throw new ArgumentOutOfRangeException(nameof(status), status, "Not a project state."),
    };

    /// <summary>The state whose slug is <paramref name="slug"/>, or <see langword="null"/> when none has it.</summary>
    public static ProjectStatus? FromSlug(string? slug) => slug switch
    {
        "en-cours" => ProjectStatus.EnCours,
        "stable" => ProjectStatus.Stable,
        "archive" => ProjectStatus.Archive,
        "prototype" => ProjectStatus.Prototype,
        _ => null,
    };
}

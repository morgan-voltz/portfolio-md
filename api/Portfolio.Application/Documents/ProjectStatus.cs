namespace Portfolio.Application.Documents;

/// <summary>
/// The closed list of project states, the plugin's own (<c>en-cours</c>,
/// <c>stable</c>, <c>archive</c>, <c>prototype</c>).
/// </summary>
/// <remarks>
/// <see cref="ProjectStatusSlugs"/> translates between this type and those
/// slugs, for the client of the plugin and for the HTTP routes.
/// </remarks>
public enum ProjectStatus
{
    EnCours,
    Stable,
    Archive,
    Prototype,
}

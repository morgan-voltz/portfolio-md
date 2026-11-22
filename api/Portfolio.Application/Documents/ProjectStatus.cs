namespace Portfolio.Application.Documents;

/// <summary>
/// The closed list of project states, the plugin's own (<c>en-cours</c>,
/// <c>stable</c>, <c>archive</c>, <c>prototype</c>).
/// </summary>
/// <remarks>
/// Reading the slug of the plugin's answers into this type, and writing it
/// back in the API's, belongs to the client of the plugin (lot 3) and to the
/// HTTP routes (lot 5).
/// </remarks>
public enum ProjectStatus
{
    EnCours,
    Stable,
    Archive,
    Prototype,
}

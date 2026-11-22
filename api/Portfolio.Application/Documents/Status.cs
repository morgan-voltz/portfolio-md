namespace Portfolio.Application.Documents;

/// <summary>The state of a project, with its label in the requested language.</summary>
public sealed record Status(ProjectStatus Value, string Label);

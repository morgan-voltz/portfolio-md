namespace Portfolio.Application.Documents;

/// <summary>A technology tag, its name in the requested language (ADR-024).</summary>
public sealed record Tag(string Slug, string Name);

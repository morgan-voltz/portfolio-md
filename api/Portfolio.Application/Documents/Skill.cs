namespace Portfolio.Application.Documents;

/// <summary>
/// A competency of the curriculum a project demonstrates (ADR-011, ADR-014):
/// its code (<c>4.0.2/E04</c>), its label in the requested language, and
/// whether that label is the official wording of the referential, which a
/// translation never is (ADR-020).
/// </summary>
public sealed record Skill(string Slug, string Code, string Label, bool Official);

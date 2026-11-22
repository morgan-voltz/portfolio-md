using System.Collections.Frozen;

namespace Portfolio.Application.Documents;

/// <summary>
/// A published document, as one list of the plugin serves it: in the language
/// that list asked for (<see cref="RequestedLanguage"/>), which is not always
/// the document's own (<see cref="Lang"/>) when no translation exists.
/// </summary>
/// <remarks>
/// <para>
/// A closed hierarchy (C# 15, ADR-026 point 6): a document is an
/// <see cref="Article"/>, a <see cref="Project"/> or a <see cref="Page"/>, and
/// nothing else, because only this assembly can derive from
/// <see cref="Document"/>. A <c>switch</c> over a document that handles the
/// three cases needs no default arm: the compiler knows there is no fourth.
/// </para>
/// <para>
/// The labels a document carries (<see cref="Tag.Name"/>,
/// <see cref="Skill.Label"/>, <see cref="Status.Label"/>) are already in the
/// requested language, as the plugin wrote them: the same French post served
/// in the German list and in the English one arrives as two documents with
/// two sets of labels.
/// </para>
/// <para>
/// Properties are <c>required</c> and set by name
/// (<c>new Project { Slug = ..., Tags = ... }</c>) rather than passed to a
/// constructor, so that <see cref="Project"/> does not repeat the fourteen
/// common ones before its own. A record compares its list and dictionary
/// properties by reference, not by content: the tests check that the catalog
/// returns the very documents it was given (<c>Assert.Same</c>), never that
/// two documents are equal.
/// </para>
/// <para>
/// A document copies the lists and the dictionary it is given (the
/// <c>init</c> accessors write a read-only copy to <c>field</c>, the backing
/// field of the property): whatever the caller later does with its own
/// collections, a document, and the catalog that holds it, cannot change once
/// built. A <c>with</c> copy shares those read-only copies, which is safe.
/// </para>
/// </remarks>
public closed record class Document
{
    /// <summary>
    /// The WordPress post id. Never shown to a reader of the API (ADR-026,
    /// point 5): the catalog only uses it to break a tie between two documents
    /// of one language that share a translation key, the oldest post, the
    /// smaller id, keeping the shared slug (point 3).
    /// </summary>
    /// <remarks>
    /// Public, because a <c>required</c> member cannot be less visible than its
    /// type (error CS9032), and an optional one would let a client forget it and
    /// break ties with a silent 0. Keeping it out of the answers is the job of
    /// the DTOs of the HTTP routes (lot 5), written field by field.
    /// </remarks>
    public required int WordPressId { get; init; }

    public required string Slug { get; init; }

    /// <summary>The language the document is written in.</summary>
    public required Language Lang { get; init; }

    /// <summary>The slug of the French document of the translation group (ADR-020).</summary>
    public required string TranslationKey { get; init; }

    /// <summary>
    /// The slug of the translation group's document in each language the group
    /// is published in (ADR-021), as the plugin lists them: this document's own
    /// slug for its own language and, for the others, the oldest post when two
    /// share a language.
    /// </summary>
    public required IReadOnlyDictionary<Language, string> Translations { get; init => field = value.ToFrozenDictionary(); }

    /// <summary>The language of the list that serves this document.</summary>
    public required Language RequestedLanguage { get; init; }

    /// <summary>
    /// Whether the document stands in for a translation that does not exist
    /// (ADR-021): computed, so that it can never disagree with
    /// <see cref="Lang"/> and <see cref="RequestedLanguage"/>.
    /// </summary>
    public bool IsFallback => Lang != RequestedLanguage;

    public required string Title { get; init; }

    /// <summary>The address of the document on the WordPress site, which carries the search engine optimization.</summary>
    public required string CanonicalUrl { get; init; }

    public required DateTimeOffset Date { get; init; }

    public required DateTimeOffset Updated { get; init; }

    public string? SeoDescription { get; init; }

    /// <summary>The Markdown source, front matter included, as the plugin stores it.</summary>
    public required string Markdown { get; init; }

    /// <summary>
    /// The body rendered to HTML: empty in a document fresh from the plugin,
    /// which only serves Markdown; the refresh of the catalog renders it
    /// (lots 4 and 5).
    /// </summary>
    public string Html { get; init; } = "";
}

/// <summary>An article of the blog.</summary>
public sealed record Article : Document
{
    public required IReadOnlyList<Tag> Tags { get; init => field = [.. value]; }

    public string? Subtitle { get; init; }

    /// <summary>Minutes; absent when the front matter gives none.</summary>
    public int? ReadingTime { get; init; }

    public required bool Featured { get; init; }
}

/// <summary>
/// A project sheet. Its first four properties repeat those of
/// <see cref="Article"/>: four lines are simpler to read than a fourth type
/// between <see cref="Document"/> and the two of them.
/// </summary>
public sealed record Project : Document
{
    public required IReadOnlyList<Tag> Tags { get; init => field = [.. value]; }

    public string? Subtitle { get; init; }

    /// <summary>Minutes; absent when the front matter gives none.</summary>
    public int? ReadingTime { get; init; }

    public required bool Featured { get; init; }

    public Status? Status { get; init; }

    /// <summary>The competencies the project demonstrates (ADR-011).</summary>
    public required IReadOnlyList<Skill> Skills { get; init => field = [.. value]; }

    /// <summary>Whether this is the storyline project of the curriculum (ADR-011).</summary>
    public required bool IsFilRouge { get; init; }

    public string? Role { get; init; }

    public DateOnly? PeriodStart { get; init; }

    public DateOnly? PeriodEnd { get; init; }

    public string? RepoUrl { get; init; }

    public string? DemoUrl { get; init; }

    public string? Client { get; init; }
}

/// <summary>A static page (home, about, résumé, contact): only the common properties.</summary>
public sealed record Page : Document;

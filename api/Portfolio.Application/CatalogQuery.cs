using Portfolio.Application.Documents;

namespace Portfolio.Application;

/// <summary>
/// What a reader asks of a collection: in which language, which page, how many
/// documents per page, narrowed by which filters.
/// </summary>
/// <remarks>
/// <para>
/// The rules of the plugin's own <c>CatalogQuery</c>: a page from 1, from 1 to
/// 100 documents per page (10 by default), a tag and a competency given as
/// slugs. A status is one of <see cref="ProjectStatus"/>, so it cannot be
/// anything else. The constructor refuses any other value: the HTTP routes
/// (lot 5) turn the query string into a query, and answer 400 when it does not
/// fit.
/// </para>
/// <para>
/// A class rather than a record: a record's <c>with</c> makes a copy without
/// running the constructor, so <c>query with { Page = 0 }</c> would slip past
/// these checks. A class has no <c>with</c>, every query goes through the
/// constructor.
/// </para>
/// </remarks>
public sealed class CatalogQuery
{
    public const int DefaultPerPage = 10;

    public const int MaxPerPage = 100;

    public CatalogQuery(
        Language language = Language.Fr,
        int page = 1,
        int perPage = DefaultPerPage,
        string? tag = null,
        ProjectStatus? status = null,
        string? skill = null)
    {
        ArgumentOutOfRangeException.ThrowIfLessThan(page, 1);
        ArgumentOutOfRangeException.ThrowIfLessThan(perPage, 1);
        ArgumentOutOfRangeException.ThrowIfGreaterThan(perPage, MaxPerPage);
        if (tag is not null && !Slugs.IsValid(tag))
        {
            throw new ArgumentException($"The tag filter must be a slug, \"{tag}\" given.", nameof(tag));
        }

        if (skill is not null && !Slugs.IsValid(skill))
        {
            throw new ArgumentException($"The skill filter must be a slug, \"{skill}\" given.", nameof(skill));
        }

        Language = language;
        Page = page;
        PerPage = perPage;
        Tag = tag;
        Status = status;
        Skill = skill;
    }

    /// <summary>The language the documents are served in; never a filter.</summary>
    public Language Language { get; }

    public int Page { get; }

    public int PerPage { get; }

    /// <summary>The slug of a technology tag: articles and projects.</summary>
    public string? Tag { get; }

    /// <summary>A state: projects only.</summary>
    public ProjectStatus? Status { get; }

    /// <summary>The slug of a competency, not of a referential (ADR-026, point 5): projects only.</summary>
    public string? Skill { get; }

    /// <summary>
    /// Whether a document passes the filters. The catalog asks it of the
    /// French document of each rank, whatever the language of the query
    /// (ADR-021, ADR-026 point 3): a translation keeps the tags and
    /// competencies of its original, and the plugin filters the same way.
    /// </summary>
    /// <remarks>
    /// A filter a document cannot carry (a status or a competency asked of an
    /// article, a tag asked of a page) matches nothing. The HTTP routes never
    /// offer such a filter; should one get through, an empty list says more
    /// than a filter quietly dropped.
    /// </remarks>
    internal bool Matches(Document document)
    {
        if (Tag is not null && !TagsOf(document).Any(tag => tag.Slug == Tag))
        {
            return false;
        }

        if (Status is not null && (document as Project)?.Status?.Value != Status)
        {
            return false;
        }

        if (Skill is not null && !((document as Project)?.Skills ?? []).Any(skill => skill.Slug == Skill))
        {
            return false;
        }

        return true;
    }

    // No default arm: the hierarchy is closed, so these three cases are all of them, and a
    // fourth kind of document would stop this switch from compiling until it is handled here.
    private static IReadOnlyList<Tag> TagsOf(Document document) => document switch
    {
        Article article => article.Tags,
        Project project => project.Tags,
        Documents.Page => [], // "Page" alone would name the page number of this query
    };
}

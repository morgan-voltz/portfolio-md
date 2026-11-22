using Portfolio.Application;
using Portfolio.Application.Documents;
using Portfolio.Application.Ports;

namespace Portfolio.Infrastructure.WordPress;

/// <summary>
/// Turns the records of <see cref="PluginJson"/> into documents and sitemap
/// lines of the core, checking on the way what the catalog relies on.
/// </summary>
/// <remarks>
/// Each check guards a promise of the plugin that, broken, would corrupt the
/// catalog without any error: a document of another collection; a list served
/// in another language than the one asked, which is what a proxy that drops
/// the <c>language</c> parameter would cause (02-api-csharp.md §7); an
/// <c>is_fallback</c> that disagrees with the two languages; a language or a
/// project state the API does not know. Any of them throws
/// <see cref="UnexpectedAnswerException"/>, which the client turns into
/// <see cref="SourceUnavailable"/>.
/// </remarks>
internal static class PluginMapping
{
    public static Article ToArticle(PluginArticle plugin, Language asked)
    {
        var common = Check(plugin, "article", asked);
        return new Article
        {
            WordPressId = plugin.Id,
            Slug = plugin.Slug,
            Lang = common.Lang,
            TranslationKey = plugin.TranslationKey,
            Translations = common.Translations,
            RequestedLanguage = common.RequestedLanguage,
            Title = plugin.Title,
            CanonicalUrl = plugin.Link,
            Date = plugin.Date,
            Updated = plugin.Updated,
            SeoDescription = plugin.SeoDescription,
            Markdown = plugin.Markdown,
            Tags = [.. plugin.Tags.Select(ToTag)],
            Subtitle = plugin.Subtitle,
            ReadingTime = plugin.ReadingTime,
            Featured = plugin.Featured,
        };
    }

    public static Project ToProject(PluginProject plugin, Language asked)
    {
        var common = Check(plugin, "project", asked);
        return new Project
        {
            WordPressId = plugin.Id,
            Slug = plugin.Slug,
            Lang = common.Lang,
            TranslationKey = plugin.TranslationKey,
            Translations = common.Translations,
            RequestedLanguage = common.RequestedLanguage,
            Title = plugin.Title,
            CanonicalUrl = plugin.Link,
            Date = plugin.Date,
            Updated = plugin.Updated,
            SeoDescription = plugin.SeoDescription,
            Markdown = plugin.Markdown,
            Tags = [.. plugin.Tags.Select(ToTag)],
            Subtitle = plugin.Subtitle,
            ReadingTime = plugin.ReadingTime,
            Featured = plugin.Featured,
            Status = plugin.Status is { } status ? ToStatus(status, plugin.Slug) : null,
            Skills = [.. plugin.Skills.Select(skill => new Skill(skill.Slug, skill.Code, skill.Label, skill.Official))],
            IsFilRouge = plugin.IsFilRouge,
            Role = plugin.Role,
            PeriodStart = plugin.PeriodStart,
            PeriodEnd = plugin.PeriodEnd,
            RepoUrl = plugin.RepoUrl,
            DemoUrl = plugin.DemoUrl,
            Client = plugin.Client,
        };
    }

    public static Page ToPage(PluginPage plugin, Language asked)
    {
        var common = Check(plugin, "page", asked);
        return new Page
        {
            WordPressId = plugin.Id,
            Slug = plugin.Slug,
            Lang = common.Lang,
            TranslationKey = plugin.TranslationKey,
            Translations = common.Translations,
            RequestedLanguage = common.RequestedLanguage,
            Title = plugin.Title,
            CanonicalUrl = plugin.Link,
            Date = plugin.Date,
            Updated = plugin.Updated,
            SeoDescription = plugin.SeoDescription,
            Markdown = plugin.Markdown,
        };
    }

    public static Sitemap ToSitemap(PluginSitemap plugin) => new(
    [
        .. plugin.Articles.Select(line => ToLine(CollectionKind.Article, line)),
        .. plugin.Projects.Select(line => ToLine(CollectionKind.Project, line)),
        .. plugin.Pages.Select(line => ToLine(CollectionKind.Page, line)),
    ]);

    /// <summary>The checks every document passes, and the values of the core they give.</summary>
    private static Common Check(PluginDocument plugin, string type, Language asked)
    {
        if (plugin.Type != type)
        {
            throw new UnexpectedAnswerException($"the document \"{plugin.Slug}\" is of type \"{plugin.Type}\" in a list of type \"{type}\"");
        }

        var lang = LanguageOf(plugin.Lang, plugin.Slug);
        var requested = LanguageOf(plugin.RequestedLanguage, plugin.Slug);
        if (requested != asked)
        {
            throw new UnexpectedAnswerException(
                $"the document \"{plugin.Slug}\" is served in \"{plugin.RequestedLanguage}\" where \"{asked.Code()}\" was asked");
        }

        if (plugin.IsFallback != (lang != requested))
        {
            throw new UnexpectedAnswerException(
                $"the document \"{plugin.Slug}\" says is_fallback {plugin.IsFallback} while its language is \"{plugin.Lang}\" and the one asked \"{plugin.RequestedLanguage}\"");
        }

        var translations = new Dictionary<Language, string>();
        foreach (var (code, slug) in plugin.Translations)
        {
            translations[LanguageOf(code, plugin.Slug)] = slug;
        }

        return new Common(lang, requested, translations);
    }

    private static Language LanguageOf(string code, string slug) =>
        LanguageCodes.FromCode(code)
            ?? throw new UnexpectedAnswerException($"the document \"{slug}\" names the unknown language \"{code}\"");

    private static Status ToStatus(PluginStatus status, string slug) =>
        new(
            ProjectStatusSlugs.FromSlug(status.Slug)
                ?? throw new UnexpectedAnswerException($"the project \"{slug}\" has the unknown state \"{status.Slug}\""),
            status.Label);

    private static Tag ToTag(PluginTag tag) => new(tag.Slug, tag.Name);

    private static SitemapLine ToLine(CollectionKind collection, PluginSitemapLine line) =>
        new(
            collection,
            line.Slug,
            LanguageCodes.FromCode(line.Lang)
                ?? throw new UnexpectedAnswerException($"the sitemap line \"{line.Slug}\" names the unknown language \"{line.Lang}\""),
            line.TranslationKey,
            line.Updated);

    private readonly record struct Common(Language Lang, Language RequestedLanguage, IReadOnlyDictionary<Language, string> Translations);
}

/// <summary>An answer of the plugin that is JSON, but not the JSON its contract promises.</summary>
internal sealed class UnexpectedAnswerException(string message) : Exception(message);

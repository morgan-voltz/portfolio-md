using System.Text.RegularExpressions;
using Portfolio.Application;
using Portfolio.Infrastructure.Markdown;
using Portfolio.Infrastructure.Tests.Support;

namespace Portfolio.Infrastructure.Tests;

/// <summary>
/// The renderer on the real content: the Markdown of the 54 documents the
/// plugin served on 2026-11-22, which the API will render (Recorded/).
/// </summary>
public sealed partial class RecordedMarkdownTests
{
    private static readonly MarkdigRenderer Renderer = new(new Uri("https://morganvoltz.dev/wp-json/portfolio/v1/"));

    private static readonly IReadOnlyList<RecordedDocument> Documents = RecordedMarkdown.All();

    [Fact]
    public void The_recorded_content_has_its_54_documents()
    {
        Assert.Equal(54, Documents.Count);
    }

    [Fact]
    public void No_published_document_shows_its_front_matter_or_raw_markup()
    {
        Assert.All(Documents, document =>
        {
            var html = Renderer.Render(document.Markdown, document.Lang);

            Assert.DoesNotContain($"slug: \"{document.Slug}\"", html);
            Assert.DoesNotContain("translation_key:", html);
            Assert.DoesNotContain("<script", html, StringComparison.OrdinalIgnoreCase);
        });
    }

    [Fact]
    public void Every_link_between_published_documents_leads_to_the_route_of_the_document_it_names()
    {
        // Each link of the Markdown, in order, becomes the route of the very document it names:
        // same collection, same slug, the language it names or else the document's own.
        Assert.All(Documents, document =>
        {
            var expected = DocumentLinkInMarkdown().Matches(document.Markdown).Select(link =>
                $"/{(link.Groups["language"].Success ? link.Groups["language"].Value : document.Lang.Code())}"
                + $"/{link.Groups["collection"].Value}/{link.Groups["slug"].Value}{link.Groups["fragment"].Value}");

            var html = Renderer.Render(document.Markdown, document.Lang);

            Assert.Equal(expected, RouteOfTheFront().Matches(html).Select(route => route.Groups["route"].Value));
            Assert.DoesNotMatch(LinkToAMarkdownFile(), html);
        });
        Assert.Equal(63, Documents.Sum(document => DocumentLinkInMarkdown().Count(document.Markdown)));
    }

    [Fact]
    public void Every_site_absolute_link_of_the_content_leads_to_the_same_path_on_the_wordpress_site()
    {
        Assert.All(Documents, document =>
        {
            var expected = SiteAbsoluteLinkInMarkdown().Matches(document.Markdown).Select(link => "https://morganvoltz.dev" + link.Groups["path"].Value);

            var html = Renderer.Render(document.Markdown, document.Lang);

            Assert.Equal(expected, LinkToTheWordPressSite().Matches(html).Select(link => link.Groups["address"].Value));
        });
        Assert.Equal(6, Documents.Sum(document => SiteAbsoluteLinkInMarkdown().Count(document.Markdown)));
    }

    [Fact]
    public void No_link_or_image_of_the_published_content_loses_its_address()
    {
        // The content only uses http, https, mailto and addresses without scheme: a link without
        // href, or an image without src, would be a regression.
        Assert.All(Documents, document =>
        {
            var html = Renderer.Render(document.Markdown, document.Lang);

            Assert.DoesNotMatch(LinkWithoutAddress(), html);
            Assert.DoesNotContain("src=\"\"", html);
        });
    }

    [GeneratedRegex(@"\]\((?:\./)?\.\./(?:\.\./(?<language>[a-z]{2})/)?(?<collection>articles|projects|pages)/(?<slug>[a-z0-9-]+)\.md(?<fragment>#[^)]*)?\)")]
    private static partial Regex DocumentLinkInMarkdown();

    [GeneratedRegex(@"href=""(?<route>/(?:fr|de|en)/(?:articles|projects|pages)/[^""]*)""")]
    private static partial Regex RouteOfTheFront();

    [GeneratedRegex(@"href=""[^""]*\.md(?:#[^""]*)?""")]
    private static partial Regex LinkToAMarkdownFile();

    [GeneratedRegex(@"\]\((?<path>/(?!/)[^)\s]*)")]
    private static partial Regex SiteAbsoluteLinkInMarkdown();

    // href or src: a site-absolute image gets the origin too, like a link.
    [GeneratedRegex(@"(?:href|src)=""(?<address>https://morganvoltz\.dev[^""]*)""")]
    private static partial Regex LinkToTheWordPressSite();

    [GeneratedRegex(@"<a(?![^>]*\bhref=)[^>]*>")]
    private static partial Regex LinkWithoutAddress();
}

using System.Text.RegularExpressions;
using Markdig;
using Markdig.Extensions.AutoIdentifiers;
using Markdig.Extensions.EmphasisExtras;
using Markdig.Renderers.Html.Inlines;
using Markdig.Syntax;
using Markdig.Syntax.Inlines;
using HtmlRenderer = Markdig.Renderers.HtmlRenderer; // not the whole namespace: it has an IMarkdownRenderer of its own
using Portfolio.Application;
using Portfolio.Application.Ports;

namespace Portfolio.Infrastructure.Markdown;

/// <summary>
/// Renders the Markdown of a document to HTML with Markdig (ADR-026, point 8),
/// the way the plugin renders it for the WordPress site, with one pass over the
/// links and images that the WordPress site does not need.
/// </summary>
/// <remarks>
/// <para>
/// Named after the library it uses, like <c>WordPressClient</c> after what it
/// reads: another library would make another implementation of the port.
/// </para>
/// <para>
/// Six settings reproduce the plugin's GitHub Flavored Markdown: the front
/// matter dropped, tables, task lists, strikethrough, bare links, and raw HTML
/// neutralised, so that markup typed in the Markdown shows as text, as on the
/// site. A seventh departs from it: every heading gets an id, which the front
/// can link to. Compared with the plugin on the 54 published documents, the
/// heading ids are the only difference before the pass over the links.
/// </para>
/// <para>
/// Known differences, none of which the published content uses today: Markdig
/// does not turn a bare email address into a link, where the plugin does (the
/// content writes <c>[address](mailto:address)</c>); fenced code is not
/// highlighted, the front will do it, and the language of a block is kept as
/// written (<c>language-JS</c>), where the plugin lowercases it and drops a name
/// outside <c>[a-z0-9_+#.-]</c>; a raw HTML block, shown as text by both,
/// is wrapped in a paragraph here; tables align with <c>style</c> rather than
/// <c>align</c>; task lists carry classes, and their boxes are written
/// <c>&lt;input disabled="disabled" type="checkbox" checked="checked" /&gt;</c>
/// where the plugin writes <c>&lt;input checked="" disabled="" type="checkbox"&gt;</c>,
/// the same element for a browser.
/// </para>
/// <para>
/// One instance serves every document, from any number of threads at once: the
/// pipeline is built once and only read, and each call builds its own syntax
/// tree, writer and HTML renderer, which must never be shared.
/// </para>
/// </remarks>
public sealed partial class MarkdigRenderer : IMarkdownRenderer
{
    /// <summary>
    /// Where a link between documents leads by default: a route of the future
    /// front (phase 7), such as <c>/fr/projects/momocad</c>, not a WordPress page.
    /// </summary>
    public const string DefaultDocumentLinkTemplate = "/{language}/{collection}/{slug}";

    // Built once: building a pipeline is the costly step, and a built pipeline is only read.
    private static readonly MarkdownPipeline Pipeline = new MarkdownPipelineBuilder()
        .UseYamlFrontMatter()
        .UsePipeTables()
        .UseTaskLists()
        .UseEmphasisExtras(EmphasisExtraOptions.Strikethrough)
        .UseAutoLinks()
        .DisableHtml()
        .UseAutoIdentifiers(AutoIdentifierOptions.GitHub) // the seventh setting, the one the plugin does not have
        .Build();

    /// <summary>The only schemes a link or an image may keep, besides having no scheme at all.</summary>
    private static readonly string[] AllowedSchemes = ["http", "https", "mailto", "tel"];

    /// <summary>The characters a browser drops at both ends of an address: the C0 controls and the space.</summary>
    private static readonly char[] ControlsAndSpace = [.. Enumerable.Range(0, 0x21).Select(code => (char)code)];

    /// <summary>The mark the pass leaves on a link that lost its address, for <see cref="LinkRenderer"/>.</summary>
    private static readonly object AddressLost = new();

    private readonly string _siteOrigin;
    private readonly string _documentLinkTemplate;

    /// <param name="wordPressSite">
    /// Any address of the WordPress site: only its origin is kept
    /// (<c>https://morganvoltz.dev</c>), which a site-absolute link of the
    /// content, such as <c>/fr/projets/</c>, needs once read outside the site.
    /// </param>
    /// <param name="documentLinkTemplate">
    /// Where a link between documents leads: <c>{language}</c>, <c>{collection}</c>
    /// (<c>articles</c>, <c>projects</c> or <c>pages</c>) and <c>{slug}</c> are
    /// replaced; <c>{slug}</c> is required, or every link would lead to the same place.
    /// </param>
    public MarkdigRenderer(Uri wordPressSite, string documentLinkTemplate = DefaultDocumentLinkTemplate)
    {
        ArgumentNullException.ThrowIfNull(wordPressSite);
        if (!wordPressSite.IsAbsoluteUri || wordPressSite.Scheme is not ("https" or "http"))
        {
            throw new ArgumentException("The WordPress site must be an absolute http or https address.", nameof(wordPressSite));
        }

        ArgumentException.ThrowIfNullOrWhiteSpace(documentLinkTemplate);
        if (!documentLinkTemplate.Contains("{slug}", StringComparison.Ordinal))
        {
            throw new ArgumentException("The template of the links between documents must contain {slug}.", nameof(documentLinkTemplate));
        }

        _siteOrigin = wordPressSite.GetLeftPart(UriPartial.Authority);
        _documentLinkTemplate = documentLinkTemplate;
    }

    public string Render(string markdown, Language documentLanguage)
    {
        // Fully qualified: inside this namespace, a bare "Markdown" would name the namespace itself.
        var document = Markdig.Markdown.Parse(markdown, Pipeline);

        // The pass changes properties of each link, never the shape of the tree, which is what
        // lets it walk the tree while changing it.
        foreach (var link in document.Descendants<LinkInline>())
        {
            Rewrite(link, documentLanguage);
        }

        // Here the tree changes shape (a node replaced by another), so the list is taken first.
        foreach (var autolink in document.Descendants<AutolinkInline>().ToList())
        {
            if (!HasAllowedScheme(autolink.Url))
            {
                // Its text is its address: it becomes a link without address around that text,
                // like any other link that loses its address.
                var placeholder = new LinkInline();
                placeholder.AppendChild(new LiteralInline(autolink.Url));
                placeholder.SetData(AddressLost, true);
                autolink.ReplaceBy(placeholder);
            }
        }

        using var writer = new StringWriter();
        var renderer = new HtmlRenderer(writer); // one per call: shared between two threads, it mixes their output
        Pipeline.Setup(renderer); // without it, tables and task lists lose their extensions, silently
        renderer.ObjectRenderers.Replace<LinkInlineRenderer>(new LinkRenderer());
        renderer.Render(document);
        return writer.ToString();
    }

    /// <summary>
    /// The single pass over a link or an image (ADR-026, point 8): the first
    /// branch that applies wins, in this order.
    /// </summary>
    private void Rewrite(LinkInline link, Language documentLanguage)
    {
        var url = link.Url ?? "";

        // 1. An address that starts with // names another site, without its scheme: an external
        //    link like any other. Tried before 3, which would otherwise put our origin in front.
        //    A browser would read \\ or \/ the same way, but Markdig writes a backslash as %5C
        //    in an attribute, which a browser does not take for a slash: the tests pin it.
        if (url.StartsWith("//", StringComparison.Ordinal))
        {
            return;
        }

        // 2. A link to another document of the content becomes a route of the front. Links only,
        //    as in the plugin: the source of an image never names a document.
        if (!link.IsImage && DocumentRoute(url, documentLanguage) is { } route)
        {
            link.Url = route;
            return;
        }

        // 3. A site-absolute address of the content (/fr/projets/) names a page of the WordPress
        //    site, which the front does not serve: it gets the site's origin.
        if (url.StartsWith('/'))
        {
            link.Url = _siteOrigin + url;
            return;
        }

        // 4. A scheme outside the list makes the link lose its address. A link keeps its text
        //    and becomes an a element without href, which a browser does not treat as a link,
        //    as the plugin renders it; an image keeps its alternative text and an empty source.
        if (!HasAllowedScheme(url))
        {
            link.Url = "";
            link.SetData(AddressLost, true);
        }

        // 5. Anything else (an allowed scheme, an anchor, a relative file) is left as written.
    }

    /// <summary>
    /// The route of the front for a link to another document, or
    /// <see langword="null"/> when <paramref name="url"/> is not one.
    /// </summary>
    /// <remarks>
    /// The forms of the content (content/README.md), and only them, like the
    /// plugin's <c>RelativeLinks</c>: <c>../projects/momocad.md</c> for a
    /// document of the same language, <c>../../de/projects/momocad.md</c> for
    /// another, with an optional <c>./</c> in front and an optional fragment.
    /// A language the site does not have, a query string, capitals or a slug
    /// out of shape leave the link as written, as the plugin does: a mistake
    /// stays visible instead of turning into a plausible but wrong address.
    /// <c>{collection}</c> takes the folder the link names: the folders of the
    /// content and the collections of the API's routes (ADR-026, point 5) share
    /// the names <c>articles</c>, <c>projects</c> and <c>pages</c>, and renaming
    /// one would mean renaming the other here.
    /// </remarks>
    private string? DocumentRoute(string url, Language documentLanguage)
    {
        var match = DocumentLink().Match(url);
        if (!match.Success)
        {
            return null;
        }

        var named = match.Groups["language"];
        Language? language = named.Success ? LanguageCodes.FromCode(named.Value) : documentLanguage;
        if (language is null)
        {
            return null;
        }

        return _documentLinkTemplate
            .Replace("{language}", language.Value.Code(), StringComparison.Ordinal)
            .Replace("{collection}", match.Groups["collection"].Value, StringComparison.Ordinal)
            .Replace("{slug}", match.Groups["slug"].Value, StringComparison.Ordinal)
            + match.Groups["fragment"].Value;
    }

    /// <summary>
    /// Whether an address keeps its place in the HTML: it has no scheme, or one
    /// of <see cref="AllowedSchemes"/>, in any case.
    /// </summary>
    /// <remarks>
    /// The scheme is read the way a browser reads it (the WHATWG URL standard),
    /// on a copy of the address: controls and spaces dropped at both ends, tabs
    /// and line breaks dropped anywhere. Markdig has already decoded the
    /// entities, so <c>java&amp;#x0A;script:</c> arrives with a real line
    /// break, which a browser would remove before reading <c>javascript:</c>.
    /// What is not a valid scheme before the first colon (<c>#x:y</c>,
    /// <c>1http:</c>) is no scheme at all, for the browser as here.
    /// </remarks>
    private static bool HasAllowedScheme(string? url)
    {
        var normalized = (url ?? "").Trim(ControlsAndSpace).Replace("\t", "").Replace("\n", "").Replace("\r", "");
        var colon = normalized.IndexOf(':');
        if (colon < 0)
        {
            return true;
        }

        var scheme = normalized[..colon];
        return !Uri.CheckSchemeName(scheme) || AllowedSchemes.Contains(scheme, StringComparer.OrdinalIgnoreCase);
    }

    // The slug follows the pattern of every slug of the site (Slugs), written out because a
    // regular expression cannot call it.
    [GeneratedRegex(@"^(?:\./)?\.\./(?:\.\./(?<language>[a-z]{2})/)?(?<collection>articles|projects|pages)/(?<slug>[a-z0-9]+(?:-[a-z0-9]+)*)\.md(?<fragment>#.*)?\z")]
    private static partial Regex DocumentLink();

    /// <summary>
    /// Markdig's renderer of links, except for a link that lost its address:
    /// an <c>a</c> element without <c>href</c>, which a browser treats as a
    /// placeholder and not as a link. <c>href=""</c> would be a link to the
    /// current page, which reloads it when clicked.
    /// </summary>
    private sealed class LinkRenderer : LinkInlineRenderer
    {
        protected override void Write(HtmlRenderer renderer, LinkInline link)
        {
            if (link.IsImage || link.GetData(AddressLost) is not true)
            {
                base.Write(renderer, link);
                return;
            }

            renderer.Write("<a");
            renderer.WriteAttributes(link);
            if (!string.IsNullOrEmpty(link.Title))
            {
                renderer.Write(" title=\"");
                renderer.WriteEscape(link.Title);
                renderer.Write("\"");
            }

            renderer.Write(">");
            renderer.WriteChildren(link);
            renderer.Write("</a>");
        }
    }
}

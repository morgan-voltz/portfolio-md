using Portfolio.Application;
using Portfolio.Infrastructure.Markdown;

namespace Portfolio.Infrastructure.Tests;

/// <summary>
/// The rendering of a document's Markdown (ADR-026, point 8): the settings
/// shared with the plugin, the ids of the headings, and the single pass over
/// the links and the images.
/// </summary>
public sealed class MarkdigRendererTests
{
    /// <summary>A renderer built, as the API will build it, from the address of the plugin's routes.</summary>
    private static readonly MarkdigRenderer Renderer = new(new Uri("https://morganvoltz.dev/wp-json/portfolio/v1/"));

    [Fact]
    public void A_link_to_a_document_of_the_same_language_leads_to_its_route_on_the_front()
    {
        Assert.Equal("<p><a href=\"/fr/pages/cv\">CV</a></p>\n", Renderer.Render("[CV](../pages/cv.md)", Language.Fr));
    }

    [Fact]
    public void A_link_that_names_another_language_leads_to_the_document_in_that_language()
    {
        Assert.Equal(
            "<p><a href=\"/de/projects/momocad\">MoMoCAD</a></p>\n",
            Renderer.Render("[MoMoCAD](../../de/projects/momocad.md)", Language.Fr));
    }

    [Theory]
    [InlineData("./../projects/momocad.md", "/en/projects/momocad")]
    [InlineData("../projects/momocad.md#decisions", "/en/projects/momocad#decisions")]
    [InlineData("./../projects/momocad.md#decisions", "/en/projects/momocad#decisions")]
    public void A_leading_dot_slash_and_a_fragment_are_accepted_as_by_the_plugin(string url, string route)
    {
        Assert.Equal($"<p><a href=\"{route}\">MoMoCAD</a></p>\n", Renderer.Render($"[MoMoCAD]({url})", Language.En));
    }

    [Theory]
    [InlineData("../../it/projects/momocad.md")] // a language the site does not have
    [InlineData("../projects/momocad.md?debug=1")] // a query string
    [InlineData("../projects/momocad.MD")] // capitals
    [InlineData("../projects/Momo_Cad.md")] // a slug out of shape
    [InlineData("../docs/architecture.md")] // a folder that is not a collection
    [InlineData("../../../fr/projects/momocad.md")] // one level too many
    public void A_link_not_written_the_way_the_content_writes_them_is_left_as_written(string url)
    {
        // As in the plugin: a mistake stays visible, instead of turning into a plausible but wrong address.
        Assert.Equal($"<p><a href=\"{url}\">x</a></p>\n", Renderer.Render($"[x]({url})", Language.Fr));
    }

    [Fact]
    public void An_image_never_leads_to_a_route_of_the_front()
    {
        // The plugin rewrites the href of a link, never the src of an image.
        Assert.Equal("<p><img src=\"../projects/momocad.md\" alt=\"M\" /></p>\n", Renderer.Render("![M](../projects/momocad.md)", Language.Fr));
    }

    [Fact]
    public void A_site_absolute_link_or_image_gets_the_origin_of_the_wordpress_site()
    {
        Assert.Equal(
            "<p><a href=\"https://morganvoltz.dev/fr/projets/\">Projets</a> <img src=\"https://morganvoltz.dev/wp-content/uploads/logo.png\" alt=\"Logo\" /></p>\n",
            Renderer.Render("[Projets](/fr/projets/) ![Logo](/wp-content/uploads/logo.png)", Language.Fr));
    }

    [Fact]
    public void A_protocol_relative_link_is_an_external_link_left_as_written()
    {
        // Not a site-absolute link: our origin in front would give https://morganvoltz.dev//example.com/x.
        Assert.Equal("<p><a href=\"//example.com/x\">E</a></p>\n", Renderer.Render("[E](//example.com/x)", Language.Fr));
    }

    [Theory]
    [InlineData("&#92;&#92;evil.example", "%5C%5Cevil.example")]
    [InlineData("&#92;/evil.example", "%5C/evil.example")]
    [InlineData("/&#92;evil.example", "https://morganvoltz.dev/%5Cevil.example")]
    public void A_backslash_never_turns_an_address_into_one_on_another_site(string destination, string href)
    {
        // A browser reads \\, \/ and /\ at the start of an address like //, another site. Markdig
        // writes a backslash as %5C, which a browser does not take for a slash: these addresses
        // stay on the site. Should a new version of Markdig stop doing so, this test says it.
        Assert.Equal($"<p><a href=\"{href}\">x</a></p>\n", Renderer.Render($"[x]({destination})", Language.Fr));
    }

    [Theory]
    [InlineData("javascript:alert(1)")]
    [InlineData("JavaScript:alert(1)")]
    [InlineData("vbscript:msgbox(1)")]
    [InlineData("data:text/html,hello")]
    [InlineData("file:///etc/passwd")]
    [InlineData("ftp://example.com/x")] // harmless, and kept by the plugin, but outside the list of ADR-026
    public void A_link_with_a_scheme_outside_the_list_loses_its_address_and_keeps_its_text(string url)
    {
        // An a element without href: a browser does not treat it as a link, as in the plugin.
        // href="" would be a link to the current page, which reloads it when clicked.
        Assert.Equal("<p><a>click</a></p>\n", Renderer.Render($"[click]({url})", Language.Fr));
    }

    [Fact]
    public void An_image_with_a_scheme_outside_the_list_loses_its_source_and_keeps_its_alternative_text()
    {
        Assert.Equal(
            "<p><img src=\"\" alt=\"logo\" /> <img src=\"\" alt=\"pixel\" /></p>\n",
            Renderer.Render("![logo](javascript:alert(1)) ![pixel](data:image/png;base64,AAAA)", Language.Fr));
    }

    [Theory]
    [InlineData("<java&#x0A;script:alert(1)>")] // a line break, which a browser removes before reading the scheme
    [InlineData("<java&#9;script:alert(1)>")] // a tab, likewise
    [InlineData("<&#1;javascript:alert(1)>")] // a control character in front, which a browser drops
    public void A_scheme_disguised_by_a_control_character_is_still_recognised(string destination)
    {
        // Markdig decodes the entities before the pass: the address arrives with the real character.
        Assert.Equal("<p><a>x</a></p>\n", Renderer.Render($"[x]({destination})", Language.Fr));
    }

    [Fact]
    public void Allowed_schemes_and_addresses_without_a_scheme_are_left_as_written()
    {
        Assert.Equal(
            "<p><a href=\"HTTP://example.com\">a</a> <a href=\"https://github.com/morgan-voltz\">b</a> "
            + "<a href=\"mailto:contact@morganvoltz.dev\">c</a> <a href=\"tel:+33600000000\">d</a> "
            + "<a href=\"#section\">e</a> <a href=\"../assets/schema.svg\">f</a></p>\n",
            Renderer.Render(
                "[a](HTTP://example.com) [b](https://github.com/morgan-voltz) [c](mailto:contact@morganvoltz.dev) "
                + "[d](tel:+33600000000) [e](#section) [f](../assets/schema.svg)",
                Language.Fr));
    }

    [Fact]
    public void An_autolink_with_a_scheme_outside_the_list_loses_its_address_and_keeps_its_text()
    {
        // Its text is its address, which stays visible inside a link without address. Two of them,
        // because the pass replaces nodes of the tree it walks.
        Assert.Equal(
            "<p><a>javascript:alert(1)</a> et <a>vbscript:msgbox(1)</a></p>\n",
            Renderer.Render("<javascript:alert(1)> et <vbscript:msgbox(1)>", Language.Fr));
    }

    [Theory]
    [InlineData("#warning:read-this")] // an anchor whose fragment holds a colon
    [InlineData("1http:ok")] // a scheme cannot start with a digit
    [InlineData("./notes:draft.md")] // a relative path: the colon comes after a slash
    public void What_comes_before_a_colon_is_no_scheme_unless_it_has_the_shape_of_one(string url)
    {
        // A browser reads these as addresses without a scheme, which the list keeps.
        Assert.Equal($"<p><a href=\"{url}\">x</a></p>\n", Renderer.Render($"[x]({url})", Language.Fr));
    }

    [Fact]
    public void An_empty_address_written_by_the_author_stays_as_the_plugin_renders_it()
    {
        // Only the links the pass neutralises lose their href attribute.
        Assert.Equal("<p><a href=\"\">x</a></p>\n", Renderer.Render("[x]()", Language.Fr));
    }

    [Fact]
    public void Autolinks_bare_links_and_email_autolinks_with_an_allowed_scheme_stay_links()
    {
        Assert.Equal(
            "<p><a href=\"https://example.com\">https://example.com</a> "
            + "<a href=\"mailto:contact@morganvoltz.dev\">contact@morganvoltz.dev</a> "
            + "<a href=\"https://github.com\">https://github.com</a></p>\n",
            Renderer.Render("<https://example.com> <contact@morganvoltz.dev> https://github.com", Language.Fr));
    }

    [Fact]
    public void A_reference_link_goes_through_the_same_pass()
    {
        Assert.Equal(
            "<p><a href=\"/de/projects/momocad\">a</a> <a>b</a></p>\n",
            Renderer.Render("[a][doc] [b][bad]\n\n[doc]: ../projects/momocad.md\n[bad]: javascript:alert(1)", Language.De));
    }

    [Fact]
    public void A_title_cannot_break_out_of_its_attribute_even_on_a_link_that_lost_its_address()
    {
        Assert.Equal(
            "<p><a href=\"https://example.com\" title=\"a&quot;b&lt;c&gt;\">x</a> <a title=\"a&quot;b&lt;c&gt;\">y</a></p>\n",
            Renderer.Render("[x](https://example.com \"a\\\"b<c>\") [y](javascript:alert(1) \"a\\\"b<c>\")", Language.Fr));
    }

    [Fact]
    public void Every_heading_gets_an_id_unique_in_its_document()
    {
        Assert.Equal(
            "<h2 id=\"résumé\">Résumé</h2>\n<h2 id=\"résumé-1\">Résumé</h2>\n<h2 id=\"c-et-net\">C# et .NET</h2>\n"
            + "<h2 id=\"voir-momocad\">Voir <a href=\"/fr/projects/momocad\">MoMoCAD</a></h2>\n",
            Renderer.Render("## Résumé\n\n## Résumé\n\n## C# et .NET\n\n## Voir [MoMoCAD](../projects/momocad.md)", Language.Fr));
    }

    [Fact]
    public void The_front_matter_is_dropped()
    {
        Assert.Equal("<p>Corps.</p>\n", Renderer.Render("---\ntitle: \"MoMoCAD\"\nslug: \"momocad\"\n---\n\nCorps.", Language.Fr));
    }

    [Fact]
    public void Raw_html_typed_in_the_markdown_shows_as_text()
    {
        // Like the plugin (html_input: escape); a raw HTML block is wrapped in a paragraph here, there it is not.
        Assert.Equal(
            "<p>Avant &lt;b&gt;gras&lt;/b&gt;.</p>\n<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>\n<p>&lt;img src=x onerror=alert(1)&gt;</p>\n",
            Renderer.Render("Avant <b>gras</b>.\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>", Language.Fr));
    }

    [Fact]
    public void Tables_task_lists_and_strikethrough_keep_their_markup()
    {
        var html = Renderer.Render("- [x] fait\n- [ ] à faire\n\n| a | b |\n|:--|--:|\n| 1 | 2 |\n\n~~barré~~", Language.Fr);

        Assert.Contains("<li class=\"task-list-item\"><input disabled=\"disabled\" type=\"checkbox\" checked=\"checked\" /> fait</li>", html);
        Assert.Contains("<th style=\"text-align: left;\">a</th>", html);
        Assert.Contains("<td style=\"text-align: right;\">2</td>", html);
        Assert.Contains("<p><del>barré</del></p>", html);
    }

    [Fact]
    public void Fenced_code_keeps_its_language_as_written_and_is_not_highlighted()
    {
        // The plugin highlights code on the server; for the API, the front will (ADR-026, point 8).
        // The language is kept as written, where the plugin would write language-js.
        Assert.Equal(
            "<pre><code class=\"language-php\">&lt;?php echo 1;\n</code></pre>\n<pre><code class=\"language-JS\">let x = 1;\n</code></pre>\n",
            Renderer.Render("```php\n<?php echo 1;\n```\n\n```JS\nlet x = 1;\n```", Language.Fr));
    }

    [Fact]
    public void The_template_of_the_links_between_documents_can_be_changed()
    {
        var renderer = new MarkdigRenderer(new Uri("https://morganvoltz.dev"), "/content/{collection}/{slug}?lang={language}");

        Assert.Equal("<p><a href=\"/content/pages/cv?lang=fr\">CV</a></p>\n", renderer.Render("[CV](../pages/cv.md)", Language.Fr));
    }

    [Theory]
    [InlineData("wp-json/portfolio/v1/", "/{language}/{collection}/{slug}")] // relative (with a leading slash, Linux would read a file path)
    [InlineData("ftp://morganvoltz.dev/", "/{language}/{collection}/{slug}")] // not http
    [InlineData("https://morganvoltz.dev/", "/{language}/{collection}")] // no {slug}: every link would lead to the same place
    [InlineData("https://morganvoltz.dev/", " ")]
    public void The_renderer_refuses_a_site_or_a_template_it_cannot_use(string site, string template)
    {
        Assert.ThrowsAny<ArgumentException>(() => new MarkdigRenderer(new Uri(site, UriKind.RelativeOrAbsolute), template));
    }

    [Fact]
    public void One_renderer_serves_many_threads_at_once()
    {
        // The refresh may render documents in parallel: a renderer shared between two calls would mix their output.
        const string Markdown = "## Titre\n\n[CV](../pages/cv.md) [P](/fr/projets/) [x](javascript:alert(1))\n\n- [x] fait\n\n| a |\n|---|\n| 1 |";
        var expected = Renderer.Render(Markdown, Language.Fr);

        var results = new string[64];
        Parallel.For(0, results.Length, index => results[index] = Renderer.Render(Markdown, Language.Fr));

        Assert.All(results, html => Assert.Equal(expected, html));
    }
}

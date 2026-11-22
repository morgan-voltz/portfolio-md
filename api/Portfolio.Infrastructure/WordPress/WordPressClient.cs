using System.Diagnostics.CodeAnalysis;
using System.Globalization;
using System.Text;
using System.Text.Json;
using Portfolio.Application;
using Portfolio.Application.Documents;
using Portfolio.Application.Ports;

namespace Portfolio.Infrastructure.WordPress;

/// <summary>
/// Reads the public routes of the WordPress plugin over HTTP (ADR-026,
/// points 2 and 7): the sitemap, and the complete list of a collection in a
/// language.
/// </summary>
/// <remarks>
/// <para>
/// A typed client: the HTTP layer gives it an <see cref="HttpClient"/> whose
/// base address is the root of the plugin's routes,
/// <c>https://morganvoltz.dev/wp-json/portfolio/v1/</c>, without any language
/// prefix (lot 5 registers it). The client keeps its own timeout of twenty
/// seconds: the registration should leave <see cref="HttpClient.Timeout"/> at
/// its default of a hundred seconds, since a shorter one would cut a request
/// first. It asks with <c>language</c>, <c>page</c> and <c>per_page</c> only,
/// never Polylang's <c>lang</c> nor an empty <c>language=</c>
/// (02-api-csharp.md §7), and introduces itself with <see cref="UserAgent"/>.
/// </para>
/// <para>
/// It never throws for a failure of the plugin: no answer, no answer within
/// twenty seconds, a status other than success, a body that is not JSON (the
/// host's firewall page among them, recognised and named), JSON that breaks
/// the plugin's contract, a list that changes while it is read. Each gives
/// <see cref="SourceUnavailable"/> with a reason for the log, and the refresh
/// (lot 5) keeps the catalog it has. Only the cancellation the caller asks
/// for, when the API stops, is thrown. No retry here: the loop of the refresh
/// is the retry (ADR-026, point 7).
/// </para>
/// </remarks>
public sealed class WordPressClient : IWordPressClient
{
    /// <summary>How the client introduces itself, like the content sync does (<c>portfolio-md-sync/1.0</c>).</summary>
    public const string UserAgent = "portfolio-md-api/1.0 (+https://github.com/morgan-voltz/portfolio-md)";

    /// <summary>The largest page the plugin serves: a hundred documents (its <c>CatalogQuery::MAX_PER_PAGE</c>).</summary>
    public const int PageSize = 100;

    /// <summary>How long one request may take, answer read in full, before it counts as no answer (ADR-026, point 2).</summary>
    public static readonly TimeSpan DefaultTimeout = TimeSpan.FromSeconds(20);

    private readonly HttpClient _http;
    private readonly TimeSpan _timeout;

    public WordPressClient(HttpClient http)
        : this(http, DefaultTimeout)
    {
    }

    /// <summary>For the tests, which cannot wait twenty seconds for a timeout.</summary>
    internal WordPressClient(HttpClient http, TimeSpan timeout)
    {
        ArgumentNullException.ThrowIfNull(http);
        if (http.BaseAddress is not { IsAbsoluteUri: true } baseAddress || !baseAddress.AbsolutePath.EndsWith('/'))
        {
            // Without the final slash, a relative "projects" would replace "v1" instead of following it.
            throw new ArgumentException(
                "The HttpClient of the plugin needs, as its base address, the absolute root of the plugin's routes "
                + "ending with a slash, such as https://morganvoltz.dev/wp-json/portfolio/v1/.",
                nameof(http));
        }

        _http = http;
        _timeout = timeout;
    }

    // A switch expression, not a switch statement: only the expression makes the compiler
    // check that both cases of the union are handled.
    public async Task<Fetch<Sitemap>> GetSitemapAsync(CancellationToken cancellationToken) =>
        await GetAsync("sitemap", cancellationToken) switch
        {
            SourceUnavailable unavailable => unavailable,
            PluginAnswer answer => Read("sitemap", () => PluginMapping.ToSitemap(Deserialize<PluginSitemap>(answer.Body))),
        };

    public Task<Fetch<IReadOnlyList<Document>>> ListAsync(CollectionKind collection, Language language, CancellationToken cancellationToken) =>
        collection switch
        {
            CollectionKind.Article => ListAsync<PluginArticle>("articles", language, PluginMapping.ToArticle, cancellationToken),
            CollectionKind.Project => ListAsync<PluginProject>("projects", language, PluginMapping.ToProject, cancellationToken),
            CollectionKind.Page => ListAsync<PluginPage>("pages", language, PluginMapping.ToPage, cancellationToken),
            _ => throw new ArgumentOutOfRangeException(nameof(collection), collection, "Not a collection of the site."),
        };

    /// <summary>
    /// Every page of a list, a hundred documents at a time, in the plugin's
    /// order. The total that the first page announces must hold until the
    /// last, and the documents read must add up to it: otherwise the content
    /// changed during the reading, and the list could miss or repeat a
    /// document.
    /// </summary>
    private async Task<Fetch<IReadOnlyList<Document>>> ListAsync<TPlugin>(
        string path,
        Language language,
        Func<TPlugin, Language, Document> map,
        CancellationToken cancellationToken)
        where TPlugin : PluginDocument
    {
        var documents = new List<Document>();
        int? total = null;
        var totalPages = 1;
        var request = "";
        for (var page = 1; page <= totalPages; page++)
        {
            request = $"{path}?language={language.Code()}&page={page}&per_page={PageSize}";
            if (!Answered(await GetAsync(request, cancellationToken), out var answer, out var unavailable))
            {
                return unavailable;
            }

            try
            {
                var announced = answer.Total ?? throw new UnexpectedAnswerException("no X-WP-Total header");
                if (total is null)
                {
                    total = announced;
                    totalPages = answer.TotalPages ?? throw new UnexpectedAnswerException("no X-WP-TotalPages header");

                    // A total of pages that the total of documents does not explain would make the
                    // loop ask for pages that cannot exist, as many as the header says.
                    var expected = (announced + PageSize - 1) / PageSize;
                    if (totalPages != expected)
                    {
                        throw new UnexpectedAnswerException(
                            $"X-WP-TotalPages is {totalPages} where X-WP-Total {announced} makes {expected} pages of {PageSize}");
                    }
                }
                else if (announced != total)
                {
                    return new SourceUnavailable(
                        $"the list changed while it was being read: X-WP-Total went from {total} to {announced} (GET {request})");
                }

                documents.AddRange(Deserialize<List<TPlugin>>(answer.Body).Select(plugin => map(plugin, language)));
            }
            catch (Exception exception) when (exception is JsonException or UnexpectedAnswerException)
            {
                return Unexpected(request, exception);
            }
        }

        if (documents.Count != total)
        {
            return new SourceUnavailable(
                $"the list changed while it was being read: {documents.Count} documents read where X-WP-Total announced {total} (GET {request})");
        }

        IReadOnlyList<Document> complete = documents;
        return complete;
    }

    /// <summary>
    /// Splits a fetch into its two cases, for the loop of <see cref="ListAsync{TPlugin}"/>,
    /// which must return from the middle of a page. Two switch expressions rather than a
    /// switch statement: only an expression makes the compiler check that every case of the
    /// union is handled, should <see cref="Fetch{T}"/> ever gain a third one.
    /// </summary>
    private static bool Answered(
        Fetch<PluginAnswer> fetched,
        [NotNullWhen(true)] out PluginAnswer? answer,
        [NotNullWhen(false)] out SourceUnavailable? unavailable)
    {
        answer = fetched switch
        {
            PluginAnswer received => received,
            SourceUnavailable => null,
        };
        unavailable = fetched switch
        {
            SourceUnavailable failed => failed,
            PluginAnswer => null,
        };
        return answer is not null;
    }

    /// <summary>
    /// One GET, its answer read in full, or the reason there is none to use.
    /// <paramref name="request"/> is relative to the base address.
    /// </summary>
    private async Task<Fetch<PluginAnswer>> GetAsync(string request, CancellationToken cancellationToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(_timeout);
        using var message = new HttpRequestMessage(HttpMethod.Get, request);
        message.Headers.UserAgent.ParseAdd(UserAgent);
        message.Headers.Accept.ParseAdd("application/json");

        try
        {
            using var response = await _http.SendAsync(message, HttpCompletionOption.ResponseContentRead, timeout.Token);

            // UTF-8 whatever the charset of the content type says: JSON is UTF-8 by definition
            // (RFC 8259). ReadAsStringAsync would trust that charset, and throw on a name it does
            // not know, or garble "façon" into "faÃ§on" on a wrong one. A byte order mark, which
            // JSON cannot start with, is dropped.
            var body = Encoding.UTF8.GetString(await response.Content.ReadAsByteArrayAsync(timeout.Token)).TrimStart('\uFEFF');
            var status = (int)response.StatusCode;
            var mediaType = response.Content.Headers.ContentType?.MediaType;
            var isJson = string.Equals(mediaType, "application/json", StringComparison.OrdinalIgnoreCase);

            if (!isJson || !response.IsSuccessStatusCode)
            {
                if (!isJson && FirewallChallenge.IsIn(body))
                {
                    return new SourceUnavailable($"{FirewallChallenge.Description}, HTTP {status} (GET {request})");
                }

                return new SourceUnavailable(response.IsSuccessStatusCode
                    ? $"the plugin answered {mediaType ?? "without a content type"} instead of JSON, HTTP {status} (GET {request})"
                    : $"the plugin answered HTTP {status}{ErrorCodeIn(body, isJson)} (GET {request})");
            }

            return new PluginAnswer(body, IntegerHeader(response, "X-WP-Total"), IntegerHeader(response, "X-WP-TotalPages"));
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            // No answer in time: our own timeout fired, or else the HttpClient's, if it was set
            // shorter. The invariant culture writes "0.5 s" on any server, where the French one
            // would write "0,5 s".
            var limit = timeout.IsCancellationRequested ? _timeout : _http.Timeout;
            return new SourceUnavailable(string.Create(
                CultureInfo.InvariantCulture,
                $"no answer from the plugin within {limit.TotalSeconds:0.###} s (GET {request})"));
        }
        catch (Exception exception) when (exception is HttpRequestException or IOException)
        {
            return new SourceUnavailable($"no answer from the plugin (GET {request}): {exception.Message}");
        }
    }

    /// <summary>What <paramref name="read"/> makes of an answer, or why the answer breaks the plugin's contract.</summary>
    private static Fetch<T> Read<T>(string request, Func<T> read)
    {
        try
        {
            return read();
        }
        catch (Exception exception) when (exception is JsonException or UnexpectedAnswerException)
        {
            return Unexpected(request, exception);
        }
    }

    private static T Deserialize<T>(string body) =>
        JsonSerializer.Deserialize<T>(body, PluginJson.Options) ?? throw new UnexpectedAnswerException("the answer is null");

    private static SourceUnavailable Unexpected(string request, Exception exception) =>
        new($"unexpected answer from the plugin (GET {request}): {exception.Message}");

    /// <summary>The WordPress error code of an error answer, such as <c> (rest_post_invalid_page_number)</c>, or nothing.</summary>
    private static string ErrorCodeIn(string body, bool isJson)
    {
        if (!isJson)
        {
            return "";
        }

        try
        {
            using var document = JsonDocument.Parse(body);
            return document.RootElement.ValueKind == JsonValueKind.Object
                && document.RootElement.TryGetProperty("code", out var code)
                && code.ValueKind == JsonValueKind.String
                ? $" ({code.GetString()})"
                : "";
        }
        catch (JsonException)
        {
            return "";
        }
    }

    private static int? IntegerHeader(HttpResponseMessage response, string name) =>
        response.Headers.TryGetValues(name, out var values) && int.TryParse(values.FirstOrDefault(), out var value) ? value : null;

    /// <summary>A successful JSON answer: its body, and the totals of a list.</summary>
    private sealed record PluginAnswer(string Body, int? Total, int? TotalPages);
}

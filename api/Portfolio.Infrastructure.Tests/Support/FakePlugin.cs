using Portfolio.Infrastructure.WordPress;

namespace Portfolio.Infrastructure.Tests.Support;

/// <summary>
/// The plugin, played by an <see cref="HttpMessageHandler"/> written by hand
/// (ADR-026, point 10): it answers each request with the function it is given
/// and remembers the requests, so that a test can check what the client asked.
/// </summary>
internal sealed class FakePlugin(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> answer) : HttpMessageHandler
{
    /// <summary>The root of the plugin's routes in production, the base address of the client.</summary>
    public static readonly Uri Root = new("https://morganvoltz.dev/wp-json/portfolio/v1/");

    /// <summary>Each recorded answer, under the request that recorded it, relative to <see cref="Root"/>.</summary>
    private static readonly Dictionary<string, string> Recordings = RecordedRequests();

    private readonly List<SeenRequest> _requests = [];

    public IReadOnlyList<SeenRequest> Requests => _requests;

    /// <summary>A plugin that answers synchronously.</summary>
    public static FakePlugin Answering(Func<HttpRequestMessage, HttpResponseMessage> answer) =>
        new((request, _) => Task.FromResult(answer(request)));

    /// <summary>
    /// The plugin of 2026-11-22: the recorded answer of each recorded request.
    /// Any other request fails the test, which shows a client asking something
    /// it should not.
    /// </summary>
    public static FakePlugin Recorded() => Answering(request =>
    {
        var relative = Root.MakeRelativeUri(request.RequestUri!).ToString();
        return Recordings.TryGetValue(relative, out var name)
            ? RecordedAnswers.Load(name)
            : throw new InvalidOperationException($"No recorded answer for GET {relative}.");
    });

    /// <summary>A client of this plugin, with the timeout of production unless the test needs a shorter one.</summary>
    public WordPressClient Client(TimeSpan? timeout = null) =>
        new(new HttpClient(this) { BaseAddress = Root }, timeout ?? WordPressClient.DefaultTimeout);

    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        _requests.Add(new SeenRequest(request.Method, request.RequestUri!, request.Headers.UserAgent.ToString(), request.Headers.Accept.ToString()));
        return answer(request, cancellationToken);
    }

    private static Dictionary<string, string> RecordedRequests()
    {
        var recordings = new Dictionary<string, string> { ["sitemap"] = "sitemap" };
        foreach (var collection in new[] { "articles", "projects", "pages" })
        {
            foreach (var language in new[] { "fr", "de", "en" })
            {
                recordings[$"{collection}?language={language}&page=1&per_page=100"] = $"{collection}-{language}";
            }
        }

        return recordings;
    }
}

/// <summary>What the plugin saw of a request.</summary>
internal sealed record SeenRequest(HttpMethod Method, Uri Uri, string UserAgent, string Accept);

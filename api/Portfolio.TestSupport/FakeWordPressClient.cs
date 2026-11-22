using Portfolio.Application;
using Portfolio.Application.Documents;
using Portfolio.Application.Ports;

namespace Portfolio.TestSupport;

/// <summary>
/// The plugin in memory, written by hand (ADR-026, point 10): it serves the
/// site it is given, fails where it is told to, and remembers what it was
/// asked. Until it is given a site, it is unavailable.
/// </summary>
/// <remarks>
/// A test changes it between two ticks of the background service, which reads
/// it from another thread: every member takes the same lock.
/// </remarks>
public sealed class FakeWordPressClient : IWordPressClient
{
    private readonly Lock _lock = new();
    private readonly List<string> _calls = [];
    private readonly TaskCompletionSource _hanging = new(TaskCreationOptions.RunContinuationsAsynchronously);
    private readonly Dictionary<(CollectionKind, Language), SourceUnavailable> _failingLists = [];
    private Sitemap _sitemap = new([]);
    private Dictionary<CollectionKind, LanguageLists> _lists = [];
    private SourceUnavailable? _sitemapFailure = new("the fake plugin serves no site yet");
    private Exception? _crash;
    private bool _hang;

    /// <summary>Completes once a call hangs, after <see cref="HangUntilCancelled"/>.</summary>
    public Task Hanging => _hanging.Task;

    /// <summary>Every call so far, in order: <c>sitemap</c>, or <c>Project/De</c> for a list.</summary>
    public IReadOnlyList<string> Calls
    {
        get
        {
            lock (_lock)
            {
                return [.. _calls];
            }
        }
    }

    /// <summary>
    /// Serves this site from now on: the sitemap and the three lists of each
    /// collection, empty when not given. Clears the failures set before.
    /// </summary>
    public FakeWordPressClient Serve(Sitemap sitemap, LanguageLists? articles = null, LanguageLists? projects = null, LanguageLists? pages = null)
    {
        lock (_lock)
        {
            _sitemap = sitemap;
            _lists = new()
            {
                [CollectionKind.Article] = articles ?? LanguageLists.Empty,
                [CollectionKind.Project] = projects ?? LanguageLists.Empty,
                [CollectionKind.Page] = pages ?? LanguageLists.Empty,
            };
            _sitemapFailure = null;
            _failingLists.Clear();
            _crash = null;
        }

        return this;
    }

    /// <summary>The sitemap is unavailable from now on, as when the host's firewall answers.</summary>
    public FakeWordPressClient FailSitemap(string reason)
    {
        lock (_lock)
        {
            _sitemapFailure = new SourceUnavailable(reason);
        }

        return this;
    }

    /// <summary>This list is unavailable from now on.</summary>
    public FakeWordPressClient FailList(CollectionKind collection, Language language, string reason)
    {
        lock (_lock)
        {
            _failingLists[(collection, language)] = new SourceUnavailable(reason);
        }

        return this;
    }

    /// <summary>
    /// Every call throws this from now on: not a failure of the plugin, which
    /// the real client reports without throwing, but a bug somewhere.
    /// </summary>
    public FakeWordPressClient Crash(Exception exception)
    {
        lock (_lock)
        {
            _crash = exception;
        }

        return this;
    }

    /// <summary>
    /// Every call waits from now on until its caller cancels it, as a plugin
    /// that never answers while the API stops.
    /// </summary>
    public FakeWordPressClient HangUntilCancelled()
    {
        lock (_lock)
        {
            _hang = true;
        }

        return this;
    }

    public Task<Fetch<Sitemap>> GetSitemapAsync(CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        lock (_lock)
        {
            _calls.Add("sitemap");
            if (_crash is not null)
            {
                throw _crash;
            }

            if (_hang)
            {
                _hanging.TrySetResult();
                return HangAsync<Fetch<Sitemap>>(cancellationToken);
            }

            Fetch<Sitemap> answer = _sitemapFailure is null ? _sitemap : _sitemapFailure;
            return Task.FromResult(answer);
        }
    }

    public Task<Fetch<IReadOnlyList<Document>>> ListAsync(CollectionKind collection, Language language, CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        lock (_lock)
        {
            _calls.Add($"{collection}/{language}");
            if (_crash is not null)
            {
                throw _crash;
            }

            if (_hang)
            {
                _hanging.TrySetResult();
                return HangAsync<Fetch<IReadOnlyList<Document>>>(cancellationToken);
            }

            Fetch<IReadOnlyList<Document>> answer = _failingLists.TryGetValue((collection, language), out var failure)
                ? failure
                : Fetch(collection, language);
            return Task.FromResult(answer);
        }
    }

    private static async Task<T> HangAsync<T>(CancellationToken cancellationToken)
    {
        await Task.Delay(Timeout.InfiniteTimeSpan, cancellationToken);
        throw new InvalidOperationException("An infinite wait ended without being cancelled.");
    }

    private Fetch<IReadOnlyList<Document>> Fetch(CollectionKind collection, Language language) =>
        _lists.TryGetValue(collection, out var lists)
            ? new Fetch<IReadOnlyList<Document>>(lists.In(language))
            : new SourceUnavailable("the fake plugin serves no site yet");
}

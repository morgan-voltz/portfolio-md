using Microsoft.Extensions.Options;
using Portfolio.Application;
using Portfolio.Application.Ports;
using Portfolio.Application.UseCases;

namespace Portfolio.Api;

/// <summary>
/// Runs the refresh of the catalog in the background (ADR-026, point 2): once
/// as soon as the API has started, then every
/// <see cref="CacheOptions.SitemapRefreshSeconds"/>. Until a first catalog
/// exists, the attempts follow after 5, 15, 45, then every 60 seconds.
/// </summary>
/// <remarks>
/// <para>
/// Each tick is caught whole: on .NET 11 an exception that escapes a
/// background service stops the host (ADR-025), and the API must keep serving
/// the catalog it has.
/// </para>
/// <para>
/// The service asks for a new <see cref="RefreshCatalog"/> at each tick, from
/// a scope of its own, rather than receiving one in its constructor. The host
/// builds its background services while it starts: building the client of the
/// plugin then, with a missing or malformed address, would keep the API from
/// starting, <c>/health/live</c> included. Asked inside the tick, the same
/// mistake is a logged error at each tick, and <c>/health/ready</c> stays 503.
/// A scope per tick also suits the client, which <c>IHttpClientFactory</c>
/// makes to be short-lived.
/// </para>
/// </remarks>
public sealed partial class CatalogRefreshWorker(
    IServiceScopeFactory scopes,
    CatalogStore store,
    IOptions<PortfolioOptions> options,
    TimeProvider clock,
    ILogger<CatalogRefreshWorker> logger) : BackgroundService
{
    /// <summary>The waits between the attempts while no catalog exists; the last one repeats.</summary>
    private static readonly TimeSpan[] FirstCatalogWaits =
        [TimeSpan.FromSeconds(5), TimeSpan.FromSeconds(15), TimeSpan.FromSeconds(45), TimeSpan.FromSeconds(60)];

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        for (var tick = 0; !stoppingToken.IsCancellationRequested; tick++)
        {
            await TickAsync(stoppingToken);

            var wait = store.Current is null
                ? FirstCatalogWaits[Math.Min(tick, FirstCatalogWaits.Length - 1)]
                : TimeSpan.FromSeconds(options.Value.Cache.SitemapRefreshSeconds);
            try
            {
                await Task.Delay(wait, clock, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                return;
            }
        }
    }

    private async Task TickAsync(CancellationToken stoppingToken)
    {
        try
        {
            await using var scope = scopes.CreateAsyncScope();
            var refresh = scope.ServiceProvider.GetRequiredService<RefreshCatalog>();
            var fullReloadPeriod = TimeSpan.FromMinutes(options.Value.Cache.FullRefreshMinutes);
            Log(await refresh.ExecuteAsync(fullReloadPeriod, stoppingToken));
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
        {
            // The API stops: not a failure. The loop ends at its next check.
        }
        catch (Exception exception)
        {
            LogFailed(logger, exception);
        }
    }

    private void Log(RefreshOutcome outcome)
    {
        // An expression, not a statement: only an expression makes the compiler
        // check that the three cases of the union are handled.
        Action log = outcome switch
        {
            Unchanged => () => LogUnchanged(logger),
            Reloaded reloaded => () => LogReloaded(reloaded),
            SourceUnavailable unavailable => () => LogUnavailable(logger, unavailable.Reason),
        };
        log();
    }

    private void LogReloaded(Reloaded reloaded)
    {
        LogCatalogReloaded(logger, reloaded.Discarded.Count, reloaded.Unmatched.Count);
        foreach (var document in reloaded.Discarded)
        {
            LogDiscarded(logger, document.Collection, document.Slug, document.Lang.Code(), document.Reason);
        }

        foreach (var line in reloaded.Unmatched)
        {
            LogUnmatched(logger, line.Collection, line.Slug, line.Lang.Code());
        }
    }

    [LoggerMessage(Level = LogLevel.Debug, Message = "The sitemap did not change: the catalog stays as it is.")]
    private static partial void LogUnchanged(ILogger logger);

    [LoggerMessage(Level = LogLevel.Information, Message = "Catalog reloaded: {Discarded} document(s) discarded, {Unmatched} line(s) of the sitemap without a document.")]
    private static partial void LogCatalogReloaded(ILogger logger, int discarded, int unmatched);

    [LoggerMessage(Level = LogLevel.Warning, Message = "{Collection} {Slug} ({Lang}) discarded: {Reason}")]
    private static partial void LogDiscarded(ILogger logger, CollectionKind collection, string slug, string lang, string reason);

    [LoggerMessage(Level = LogLevel.Warning, Message = "The sitemap lists {Collection} {Slug} ({Lang}), which leads to no document of the catalog.")]
    private static partial void LogUnmatched(ILogger logger, CollectionKind collection, string slug, string lang);

    [LoggerMessage(Level = LogLevel.Warning, Message = "The plugin could not be read, the catalog stays as it was: {Reason}")]
    private static partial void LogUnavailable(ILogger logger, string reason);

    [LoggerMessage(Level = LogLevel.Error, Message = "The refresh of the catalog failed unexpectedly, the catalog stays as it was.")]
    private static partial void LogFailed(ILogger logger, Exception exception);
}

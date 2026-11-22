using Microsoft.Extensions.Diagnostics.HealthChecks;
using Portfolio.Application;

namespace Portfolio.Api;

/// <summary>
/// Readiness (ADR-026, point 4): unhealthy until the first complete catalog,
/// healthy for good after it, since nothing empties the store.
/// </summary>
/// <remarks>
/// "Ready" means able to answer every route, which needs the nine lists, not
/// only the sitemap: a precision of point 5 of ADR-023. A long outage of the
/// plugin does not close the route again; the age of the catalog, published as
/// a metric with lot 7, will show it.
/// </remarks>
public sealed class CatalogReadinessCheck(CatalogStore store) : IHealthCheck
{
    public const string Name = "catalog";

    public Task<HealthCheckResult> CheckHealthAsync(HealthCheckContext context, CancellationToken cancellationToken = default) =>
        Task.FromResult(store.Current is null
            ? HealthCheckResult.Unhealthy("No catalog yet: the plugin has not been read in full since the API started.")
            : HealthCheckResult.Healthy());
}

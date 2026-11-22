using System.Net;
using Microsoft.AspNetCore.Hosting;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Diagnostics.HealthChecks;
using Portfolio.Api.Tests.Support;

namespace Portfolio.Api.Tests;

/// <summary>
/// GET /health/live (ADR-023 point 5): the API is started in memory, with its
/// real composition root, and called without any network.
/// </summary>
public sealed class HealthEndpointTests(PortfolioApiFactory factory) : IClassFixture<PortfolioApiFactory>
{
    [Fact]
    public async Task The_live_route_answers_healthy_as_soon_as_the_process_runs()
    {
        using var client = factory.CreateClient();

        using var response = await client.GetAsync("/health/live", TestContext.Current.CancellationToken);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("Healthy", await response.Content.ReadAsStringAsync(TestContext.Current.CancellationToken));
    }

    [Fact]
    public async Task The_live_route_ignores_a_failing_check()
    {
        // Any failing check, such as the readiness of the catalog before the first
        // catalog: the process is alive all the same.
        using var withFailingCheck = factory.WithWebHostBuilder(host => host.ConfigureServices(services =>
            services.AddHealthChecks().AddCheck("failing", () => HealthCheckResult.Unhealthy())));
        using var client = withFailingCheck.CreateClient();

        using var response = await client.GetAsync("/health/live", TestContext.Current.CancellationToken);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }
}

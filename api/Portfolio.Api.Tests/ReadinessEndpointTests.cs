using System.Net;
using Portfolio.Api.Tests.Support;
using Portfolio.Application.Ports;

namespace Portfolio.Api.Tests;

/// <summary>
/// GET /health/ready (ADR-026, point 4), driven by the real background
/// service: the test serves a site with the fake plugin and lets the time of
/// the manual clock pass.
/// </summary>
public sealed class ReadinessEndpointTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task Ready_answers_503_until_the_first_complete_catalog_then_200_for_good()
    {
        await using var factory = new PortfolioApiFactory();
        using var client = factory.CreateClient();

        // The first tick ran as the API started; the plugin serves nothing yet.
        Assert.Equal(TimeSpan.FromSeconds(5), await factory.Clock.NextTimerAsync(Token));
        await AssertStatusAsync(client, "/health/ready", HttpStatusCode.ServiceUnavailable, "Unhealthy");
        await AssertStatusAsync(client, "/health/live", HttpStatusCode.OK, "Healthy");

        // A site without any document gives a complete catalog all the same.
        factory.Plugin.Serve(new Sitemap([]));
        factory.Clock.Advance(TimeSpan.FromSeconds(5));
        Assert.Equal(TimeSpan.FromSeconds(60), await factory.Clock.NextTimerAsync(Token));
        await AssertStatusAsync(client, "/health/ready", HttpStatusCode.OK, "Healthy");

        // The plugin fails again: the catalog stays, and so does readiness.
        factory.Plugin.FailSitemap("the host's firewall answered");
        factory.Clock.Advance(TimeSpan.FromSeconds(60));
        Assert.Equal(TimeSpan.FromSeconds(60), await factory.Clock.NextTimerAsync(Token));
        await AssertStatusAsync(client, "/health/ready", HttpStatusCode.OK, "Healthy");
    }

    private static async Task AssertStatusAsync(HttpClient client, string route, HttpStatusCode status, string body)
    {
        using var response = await client.GetAsync(route, Token);

        Assert.Equal(status, response.StatusCode);
        Assert.Equal(body, await response.Content.ReadAsStringAsync(Token));
    }
}

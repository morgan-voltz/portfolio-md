using System.Net;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Portfolio.Api.Tests.Support;
using Portfolio.Application.Ports;
using Portfolio.Application.UseCases;
using Portfolio.Infrastructure.Markdown;
using Portfolio.Infrastructure.WordPress;

namespace Portfolio.Api.Tests;

/// <summary>
/// The composition root (Program.cs) with the real client and renderer of the
/// infrastructure. Neither test lets the client send a request: one gives it no
/// address, the other leaves the background service out.
/// </summary>
public sealed class CompositionTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task Without_the_address_of_the_plugin_the_api_starts_and_each_tick_names_the_setting_to_give()
    {
        // The background service builds the client inside its tick, never while the
        // host starts: a missing setting must not take /health/live down.
        await using var factory = new PortfolioApiFactory { PluginAddress = "", RealPlugin = true };
        using var client = factory.CreateClient();

        Assert.Equal(TimeSpan.FromSeconds(5), await factory.Clock.NextTimerAsync(Token));
        using var live = await client.GetAsync("/health/live", Token);
        Assert.Equal(HttpStatusCode.OK, live.StatusCode);
        var failure = Assert.Single(factory.Logs.Of<CatalogRefreshWorker>(LogLevel.Error)).Exception;
        Assert.IsType<InvalidOperationException>(failure);
        Assert.StartsWith("PORTFOLIO__WORDPRESSBASEURL must be the http or https address of the plugin's routes", failure.Message);
    }

    [Theory]
    [InlineData("Portfolio:Cache:SitemapRefreshSeconds", "0", "PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS")]
    [InlineData("Portfolio:Cache:SitemapRefreshSeconds", "-5", "PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS")]
    [InlineData("Portfolio:Cache:SitemapRefreshSeconds", "86401", "PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS")]
    [InlineData("Portfolio:Cache:FullRefreshMinutes", "0", "PORTFOLIO__CACHE__FULLREFRESHMINUTES")]
    public async Task A_period_of_the_refresh_out_of_its_range_keeps_the_api_from_starting(string key, string value, string variable)
    {
        // Zero would make the refresh ask the plugin without pause, and a negative wait would
        // stop the API after its first catalog; a day is the longest period, well under the
        // 49 days Task.Delay accepts.
        await using var factory = new PortfolioApiFactory { Settings = { [key] = value } };

        // The host logs why it does not start, then stops. What WebApplicationFactory
        // throws then depends on timing (the validation error, or the disposed host it
        // reaches for afterwards), so the reason is read from the log.
        Assert.ThrowsAny<Exception>(factory.StartApi);

        var failure = Assert.Single(factory.Logs.Entries, entry => entry.Exception is OptionsValidationException).Exception;
        Assert.StartsWith(variable, Assert.Single(Assert.IsType<OptionsValidationException>(failure).Failures));
    }

    [Fact]
    public void The_refresh_is_built_with_the_client_and_the_renderer_of_the_infrastructure()
    {
        using var factory = new PortfolioApiFactory { RealPlugin = true, WithoutRefresh = true };
        using var scope = factory.Services.CreateScope();

        Assert.IsType<WordPressClient>(scope.ServiceProvider.GetRequiredService<IWordPressClient>());
        Assert.IsType<MarkdigRenderer>(scope.ServiceProvider.GetRequiredService<IMarkdownRenderer>());
        Assert.NotNull(scope.ServiceProvider.GetRequiredService<RefreshCatalog>());
    }
}

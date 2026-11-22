using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Portfolio.Application;
using Portfolio.Application.Ports;
using Portfolio.TestSupport;

namespace Portfolio.Api.Tests.Support;

/// <summary>
/// The API started in memory with its real composition (Program.cs), except
/// what a test must control: the plugin, played by a
/// <see cref="FakeWordPressClient"/>, and the clock, a
/// <see cref="ManualTimeProvider"/>, so that the background service waits only
/// when the test lets the time pass. No test of the API may reach the real
/// site: every one goes through this factory.
/// </summary>
/// <remarks>
/// The background service starts with the API and runs its first tick at
/// once. A test that needs a fresh API, its own store and clock, creates its
/// own factory rather than sharing one.
/// </remarks>
public sealed class PortfolioApiFactory : WebApplicationFactory<Program>
{
    /// <summary>A reserved domain (RFC 2606): an address no request can reach.</summary>
    public const string ReservedPluginAddress = "https://wordpress.test/wp-json/portfolio/v1/";

    /// <summary>The time the API starts at, in its clock.</summary>
    public static readonly DateTimeOffset Start = new(2026, 11, 22, 8, 0, 0, TimeSpan.Zero);

    public FakeWordPressClient Plugin { get; } = new();

    public ManualTimeProvider Clock { get; } = new(Start);

    public CapturedLogs Logs { get; } = new();

    /// <summary>The address of the plugin the API is given, <see cref="ReservedPluginAddress"/> unless a test says otherwise.</summary>
    public string PluginAddress { get; init; } = ReservedPluginAddress;

    /// <summary>More settings, by their key in the configuration (<c>Portfolio:Cache:SitemapRefreshSeconds</c>).</summary>
    public Dictionary<string, string> Settings { get; } = [];

    /// <summary>
    /// Keeps the real client of the plugin instead of <see cref="Plugin"/>, for
    /// the tests of the composition itself; the test then makes sure it never
    /// sends a request.
    /// </summary>
    public bool RealPlugin { get; init; }

    /// <summary>A renderer in place of Markdig, set before the API starts.</summary>
    public IMarkdownRenderer? Renderer { get; init; }

    /// <summary>Leaves the background service of the refresh out, so that the composition can be examined at rest.</summary>
    public bool WithoutRefresh { get; init; }

    public CatalogStore Store => Services.GetRequiredService<CatalogStore>();

    /// <summary>Starts the API, if it has not started yet: its first tick runs at once.</summary>
    public void StartApi() => _ = Services;

    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        builder.UseSetting("Portfolio:WordPressBaseUrl", PluginAddress);
        foreach (var (key, value) in Settings)
        {
            builder.UseSetting(key, value);
        }

        builder.ConfigureLogging(logging => logging.AddProvider(Logs));
        builder.ConfigureTestServices(services =>
        {
            services.RemoveAll<TimeProvider>();
            services.AddSingleton<TimeProvider>(Clock);
            if (!RealPlugin)
            {
                services.RemoveAll<IWordPressClient>();
                services.AddSingleton<IWordPressClient>(Plugin);
            }

            if (Renderer is not null)
            {
                services.RemoveAll<IMarkdownRenderer>();
                services.AddSingleton(Renderer);
            }

            if (WithoutRefresh)
            {
                services.Remove(services.Single(service => service.ImplementationType == typeof(CatalogRefreshWorker)));
            }
        });
    }
}

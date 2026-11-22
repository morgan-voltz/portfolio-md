using Microsoft.AspNetCore.Diagnostics.HealthChecks;
using Microsoft.Extensions.Options;
using Portfolio.Api;
using Portfolio.Application;
using Portfolio.Application.Ports;
using Portfolio.Application.UseCases;
using Portfolio.Infrastructure.Markdown;
using Portfolio.Infrastructure.WordPress;

var builder = WebApplication.CreateBuilder(args);

// The settings: the section "Portfolio", from the environment in production
// (PORTFOLIO__WORDPRESSBASEURL, PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS,
// PORTFOLIO__CACHE__FULLREFRESHMINUTES). The two periods of the refresh are
// checked as the API starts: a period of zero would make the refresh ask the
// plugin without pause, a negative one, or one beyond the 49 days Task.Delay
// accepts, would stop the API after its first catalog. Lot 7 checks the other
// settings (ADR-026, point 11).
builder.Services.AddOptions<PortfolioOptions>()
    .Bind(builder.Configuration.GetSection(PortfolioOptions.Section))
    .Validate(
        options => options.Cache.SitemapRefreshSeconds is >= 1 and <= 86_400,
        "PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS must be between 1 and 86400 seconds, a day.")
    .Validate(
        options => options.Cache.FullRefreshMinutes >= 1,
        "PORTFOLIO__CACHE__FULLREFRESHMINUTES must be at least 1 minute.")
    .ValidateOnStart();

// The catalog and its refresh (ADR-026, points 1 and 2). Nothing here reaches the
// plugin while the API starts: the background service asks for the refresh, and
// with it for the client and the renderer, at each tick.
builder.Services.AddSingleton(TimeProvider.System);
builder.Services.AddSingleton<CatalogStore>();

// A typed client of IHttpClientFactory. HttpClient.Timeout keeps its default of a
// hundred seconds: the client enforces its own twenty (lot 3).
builder.Services.AddHttpClient<IWordPressClient, WordPressClient>((services, http) =>
    http.BaseAddress = services.GetRequiredService<IOptions<PortfolioOptions>>().Value.PluginRoot());

// The renderer keeps only the origin of the address, the WordPress site (lot 4).
builder.Services.AddSingleton<IMarkdownRenderer>(services =>
    new MarkdigRenderer(services.GetRequiredService<IOptions<PortfolioOptions>>().Value.PluginRoot()));
builder.Services.AddScoped<RefreshCatalog>();
builder.Services.AddHostedService<CatalogRefreshWorker>();

builder.Services.AddHealthChecks().AddCheck<CatalogReadinessCheck>(CatalogReadinessCheck.Name);

var app = builder.Build();

// Liveness (ADR-023, point 5): the process runs and answers. The predicate runs
// no registered check, so the readiness check below can fail without making this
// route fail: Traefik and Uptime Kuma must be able to tell "the process is down"
// from "the process waits for WordPress".
app.MapHealthChecks("/health/live", new HealthCheckOptions { Predicate = _ => false });

// Readiness (ADR-026, point 4): 503 until the first complete catalog, then 200
// for good. Only the catalog's check counts, so a check added later for another
// purpose cannot close this route by surprise.
app.MapHealthChecks("/health/ready", new HealthCheckOptions { Predicate = check => check.Name == CatalogReadinessCheck.Name });

app.Run();

using Microsoft.AspNetCore.Diagnostics.HealthChecks;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddHealthChecks();

var app = builder.Build();

// Liveness (ADR-023, point 5): the process runs and answers. The predicate runs
// no registered check, so the readiness check that lot 5 adds for the catalog
// can fail without making this route fail: Traefik and Uptime Kuma must be able
// to tell "the process is down" from "the process waits for WordPress".
app.MapHealthChecks("/health/live", new HealthCheckOptions { Predicate = _ => false });

app.Run();

# api/ — C# ASP.NET Core API `Portfolio.Api`

This folder hosts the .NET solution that serves the published content of the WordPress plugin to the React frontend. The API keeps an in-memory catalog of that content, reloaded by a background service when the plugin's sitemap changes, so that a request never waits for WordPress (ADR-026). It targets .NET 11 and C# 15 (ADR-025) and uses ASP.NET Core Minimal APIs.

The solution follows Clean Architecture, in three projects:

- `Portfolio.Api` — the HTTP surface and the composition root (`Program.cs`), the only project that knows the two others
- `Portfolio.Application` — the core: the documents, the in-memory catalog, the ports and the use cases; it references no package and no other project, which a test checks
- `Portfolio.Infrastructure` — the implementations of the ports: the client of the plugin and the Markdown renderer

**Current status**: lot 5 of 8 (ADR-026, point 11), delivered in two pull requests. Lot 1 brought the solution, its continuous integration (`.github/workflows/api-ci.yml`), `GET /health/live`, the test of the dependency rule and the container image. Lot 2 brought the core: the documents, the in-memory catalog and the two read use cases, `GetDocumentBySlug` and `ListDocuments`. Lot 3 brought the client of the plugin, `WordPressClient`, tested against answers recorded from the plugin in production. Lot 4 brought the Markdown renderer, `MarkdigRenderer`. Lot 5a brings the refresh of the catalog: a background service, `CatalogRefreshWorker`, runs `RefreshCatalog`, which reads the plugin, renders each document and replaces the catalog, and `GET /health/ready` answers 200 from the first complete catalog. The public routes of the three collections come with lot 5b.

## Layout

```
api/
├── Portfolio.slnx                  the solution (the XML format, default since the .NET 10 SDK)
├── global.json                     SDK 11.0.100, tests on Microsoft.Testing.Platform
├── Directory.Build.props           settings of every project: target, nullable, warnings as errors
├── Directory.Packages.props        one version per package for the whole solution
├── Portfolio.Api/                  Program.cs, the options, the background refresh, /health/ready
├── Portfolio.Application/          the core: Documents/, the catalog, Ports/, UseCases/
├── Portfolio.Infrastructure/       WordPress/, the client of the plugin; Markdown/, the renderer (Markdig)
├── Portfolio.Api.Tests/            the API started in memory (WebApplicationFactory)
├── Portfolio.Application.Tests/    the core, and the dependency rule
├── Portfolio.Infrastructure.Tests/ the client and the renderer, against recorded answers of the plugin (Recorded/)
├── Portfolio.TestSupport/          what the tests share: doubles written by hand, test documents
└── deploy/                         container image and production compose (ADR-023)
```

## Commands

From `api/`, with the .NET 11 SDK:

```bash
dotnet build Portfolio.slnx                         # warnings are errors
dotnet test --solution Portfolio.slnx               # xUnit v3 on Microsoft.Testing.Platform
dotnet format Portfolio.slnx --verify-no-changes    # the formatting check of the CI
PORTFOLIO__WORDPRESSBASEURL=https://morganvoltz.dev/wp-json/portfolio/v1/ \
  dotnet run --project Portfolio.Api                # http://localhost:5000/health/ready
```

Run that way, the API reads the plugin in production: its sitemap every minute, the nine lists when the sitemap changes. Without `PORTFOLIO__WORDPRESSBASEURL` it starts all the same, logs an error naming the variable at each tick, and `/health/ready` stays 503.

The container image, as production builds it:

```bash
podman build -f deploy/Dockerfile -t portfolio-api:dev .
podman run --rm -p 127.0.0.1:8080:8080 \
  -e PORTFOLIO__WORDPRESSBASEURL=https://morganvoltz.dev/wp-json/portfolio/v1/ \
  portfolio-api:dev                                 # http://localhost:8080/health/ready
```

## Documentation

The architecture is described in [`../docs/architecture/02-api-csharp.md`](../docs/architecture/02-api-csharp.md) and decided in ADR-003, ADR-023, ADR-025 and ADR-026 ([`../docs/architecture/99-decisions.md`](../docs/architecture/99-decisions.md)). `02-api-csharp.md` describes the code in its sections 3 to 6, 9.2, 10 and 11, and in the paragraphs of section 8 on health and configuration; its section 7 and the rest of section 8 still describe the former design until lots 5b and 7 rewrite them. Where it and ADR-026 disagree, ADR-026 holds.

# api/ — C# ASP.NET Core API `Portfolio.Api`

This folder hosts the .NET solution that serves the published content of the WordPress plugin to the React frontend. The API keeps an in-memory catalog of that content, reloaded by a background service when the plugin's sitemap changes, so that a request never waits for WordPress (ADR-026). It targets .NET 11 and C# 15 (ADR-025) and uses ASP.NET Core Minimal APIs.

The solution follows Clean Architecture, in three projects:

- `Portfolio.Api` — the HTTP surface and the composition root (`Program.cs`), the only project that knows the two others
- `Portfolio.Application` — the core: the documents, the in-memory catalog, the ports and the use cases; it references no package and no other project, which a test checks
- `Portfolio.Infrastructure` — the implementations of the ports: the client of the plugin and the Markdown renderer

**Current status**: lot 2 of 8 (ADR-026, point 11). Lot 1 brought the solution, its continuous integration (`.github/workflows/api-ci.yml`), `GET /health/live`, the test of the dependency rule and the container image. Lot 2 brings the core: the documents, the in-memory catalog and the two read use cases, `GetDocumentBySlug` and `ListDocuments`. The client of the plugin, the Markdown rendering and the public routes come with lots 3 to 5.

## Layout

```
api/
├── Portfolio.slnx                  the solution (the XML format, default since the .NET 10 SDK)
├── global.json                     SDK 11.0.100, tests on Microsoft.Testing.Platform
├── Directory.Build.props           settings of every project: target, nullable, warnings as errors
├── Directory.Packages.props        one version per package for the whole solution
├── Portfolio.Api/
├── Portfolio.Application/          the core: Documents/, the catalog, Ports/, UseCases/
├── Portfolio.Infrastructure/       empty until lot 3
├── Portfolio.Api.Tests/            the API started in memory (WebApplicationFactory)
├── Portfolio.Application.Tests/    the core, and the dependency rule
└── deploy/                         container image and production compose (ADR-023)
```

`Portfolio.Infrastructure.Tests` arrives with the client of the plugin (lot 3).

## Commands

From `api/`, with the .NET 11 SDK:

```bash
dotnet build Portfolio.slnx                         # warnings are errors
dotnet test --solution Portfolio.slnx               # xUnit v3 on Microsoft.Testing.Platform
dotnet format Portfolio.slnx --verify-no-changes    # the formatting check of the CI
dotnet run --project Portfolio.Api                  # http://localhost:5000/health/live
```

The container image, as production builds it:

```bash
podman build -f deploy/Dockerfile -t portfolio-api:dev .
podman run --rm -p 127.0.0.1:8080:8080 portfolio-api:dev   # http://localhost:8080/health/live
```

## Documentation

The architecture is described in [`../docs/architecture/02-api-csharp.md`](../docs/architecture/02-api-csharp.md) and decided in ADR-003, ADR-023, ADR-025 and ADR-026 ([`../docs/architecture/99-decisions.md`](../docs/architecture/99-decisions.md)). `02-api-csharp.md` describes the code in its sections 3, 4, 9.2, 10 and 11; its sections 5 to 8 still describe the former per-key cache until lots 4, 5 and 7 rewrite them. Where it and ADR-026 disagree, ADR-026 holds.

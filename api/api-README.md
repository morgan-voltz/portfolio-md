# api/ — C# ASP.NET Core API `Portfolio.Api`

This folder will host the .NET solution that acts as a gateway between the WordPress plugin and the React frontend. The API consumes the plugin's custom REST endpoints, caches responses, renders Markdown to HTML via Markdig, and exposes its own public API to the frontend.

The solution is organized in **three projects** following Clean Architecture principles:
- `Portfolio.Api` — HTTP surface (Minimal APIs, DTOs, middleware)
- `Portfolio.Application` — pure business logic, abstractions, use cases
- `Portfolio.Infrastructure` — concrete implementations (HTTP client, cache, Markdown rendering)

**Current status**: not yet implemented. The solution will be created during Phase 6 of the project roadmap.

For complete architectural details, see [`../docs/architecture/02-api-csharp.md`](../docs/architecture/02-api-csharp.md).

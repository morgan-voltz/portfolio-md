---
title: "Atlas"
slug: "atlas-en"
type: "project"
lang: "en"
translation_key: "atlas"
date: 2026-10-26
subtitle: "A .NET platform that unifies French public company and industrial property data, locked down by tests"
seo_description: "Atlas brings French public company and industrial property data together behind a tested .NET API with a hexagonal architecture."
featured: false
status: "en-cours"
role: "Design, architecture, development and testing, solo"
period_start: 2026-05-26
period_end: ""
repo_url: "https://github.com/morgan-voltz/atlas"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [csharp, dotnet, architecture-hexagonale, cqrs, entity-framework-core, postgresql, xunit, securite, uno-platform]
skills: ["4.0.2/E06", "4.0.2/E07", "4.0.2/E08", "4.0.2/E09"]
---

Atlas is a .NET platform that I have been building on my own since the end of May 2026, to aggregate French public data on companies (the national register, RNE) and on industrial property (trademarks, patents and designs through the INPI), with a monitoring engine that layers legal notices and sector news on top. "Atlas" is a code name: the final name will be chosen at the time of the commercial launch, not before.

The backend is complete for this first scope (accounts with two-factor authentication, search for companies and industrial property titles, favourites, monitoring with alerts) and a first web client has been delivered. The business model has not yet been settled between a subscription SaaS and an annual licence, but a Premium layer is already isolated to accommodate that choice without rewriting the core of the product.

## The problem

French public data on companies and on industrial property exists, but it is scattered across heterogeneous portals: the national business register on one side, the INPI trademark and patent databases on the other, the BODACC legal notices somewhere else again. The commercial aggregators that offer a unified view remain closed and paid beyond very basic use. Seven professional profiles live with this fragmentation every day: lawyers and legal counsel, industrial property firms, compliance and KYC teams, accountants, M&A investors, competitive-intelligence teams, and developers who would like to consume this data without reinventing the integration.

The product problem is therefore to build a single entry point to these sources, with a company search, access to industrial property titles and monitoring that raises alerts rather than forcing you to recheck every source by hand. The technical problem is one of method: how do you build alone, over several months, a system whose target audience (lawyers, compliance) does not tolerate approximation, without the peer review of a team to catch drift?

## The technical decisions

**The hexagonal architecture is verified by a test project, not left to discipline.** The dependencies between the solution's 18 projects follow a single direction: `Atlas.Shared`, then `Atlas.Domain`, then `Atlas.Application` (and its `.Premium` variant), then the eight `Atlas.Infrastructure.*` projects, then `Atlas.Api`. A dedicated project, `Atlas.Architecture.Tests`, fails the CI as soon as a layer references something it must not, including the rule that a client may not talk to the infrastructure instead of the HTTP API. On a project with no second human pair of eyes, this test replaces the code review that I do not have.

**The domain refuses the implicit: errors and identifiers are values, never surprises.** Every use case goes through a `Result<T>` type: a validation failure or a violated business rule is read in the handler's return value, never in an exception, and the validations run before it through a dedicated MediatR behaviour. A SIREN follows the same logic: never a bare `string`, but a value object that checks its format with the Luhn algorithm, a malformed value being rejected at the boundary of the domain.

**Persistence goes through an ORM that the CI really exercises, and deployment through a machine that can be rebuilt with a single command.** The data lives in PostgreSQL through Entity Framework Core; the CI applies the migrations against a fresh database on every nightly run, and Hangfire schedules the background tasks there. Pre-production fits on a single VPS under docker-compose (API, database, jobs and the web client's static files), behind a Caddy reverse proxy that handles its TLS certificate by itself, with a single domain to avoid CORS.

**Security is designed for an audience that does not forgive amateurism.** Passwords hashed with Argon2id, short-lived JWTs signed with RS256 together with a rotating refresh token kept on the server side, TOTP two-factor authentication, and INPI credentials encrypted at rest with AES-256-GCM, decrypted only for the duration of a request and never logged, an absolute prohibition written into the documentation.

**Every structural decision is dated and never erased, even when it changes its mind.** 29 ADRs record the architecture choices, including the reversals: the client strategy went from Blazor WebAssembly to MAUI (mobile) and Avalonia (desktop), then to a single Uno Platform interface; with each change the previous ADR is marked "superseded" rather than rewritten. The domain itself never had to change a line to absorb these three strategies, because the clients talk to it only through the HTTP API.

**The CI checks the project against the real world, not only against itself.** In addition to the unit tests and architecture tests run on every push, a nightly workflow starts the API and replays requests against the production INPI API through Bruno collections, to detect a breaking change in the provider's contract before a user discovers it. It stayed red every night from its creation at the end of May and only got back to a stable green on 25 September 2026, yet its first useful run was precisely what spotted a change to authentication on the INPI side. The mechanism is still young, but the principle holds: confront the code with a real third-party API rather than with mocks.

## What I learned from it

That an architecture in which the domain depends on nothing but itself absorbs a radical change of client strategy without forcing you to touch the core of the product: the three client technologies tried in four months never required touching `Atlas.Domain` or `Atlas.Application`. That rigour has a cost in speed (maintaining architecture tests, a CI that queries a real third-party API at night, keeping 29 ADRs up to date) which inevitably shows in the pace of deliveries, but that it is the price to pay for an audience that does not tolerate the approximate. And that documenting a decision that has been taken, even when it is not yet carried out everywhere, spares you from reconstructing it from memory months later.

A clarification, because the repository is public and `git log` makes it verifiable: I do not type every line alone in the strict sense. I practise agentic development with Claude as a pair-programming partner, under constraints that I set myself; I have a tool dedicated to this way of working in the repository, `Atlas.DevEye`. Most of the commits on the main branch credit Claude as co-author; I remain the only human contributor, the author of the architecture decisions and the final reviewer. To say "solo" without saying this would be incomplete.

What I would do differently: along the way, the project's trajectory drifted from a model conceived as open source to a proprietary commercial model, without my updating the licence and the README at the same speed. The `LICENSE` file itself carries the note "to be replaced before public release", when the publication has already happened. A gap to be fixed before any further external visibility.

## In figures

- 18 production projects in `src/` and 11 test projects in `tests/` (29 in total), layered hexagonal architecture
- About 47,000 lines of C# application code, about 11,000 lines of tests
- 472 xUnit tests (453 `[Fact]` + 19 `[Theory]`), plus a project dedicated to the architecture rules
- 74 endpoints exposed by the API (Minimal APIs)
- 29 ADRs, 76 feature sheets
- 221 commits on the main branch, from the end of May to the end of September 2026; 151 (≈ 68%) credit Claude as co-author, and I remain the sole human contributor to the repository

## Links

- Repository: public, on GitHub (`github.com/morgan-voltz/atlas`). No tagged release yet.
- Demo: no public demo for now; the planned user documentation site has not yet been deployed.
- Documentation: 29 ADRs and the numbered feature sheets live in the repository (`docs/`).

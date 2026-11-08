---
title: "Vefta"
slug: "vefta-en"
type: "project"
lang: "en"
translation_key: "vefta"
date: 2026-10-26
subtitle: "A personal productivity suite written in Rust from end to end, built to work offline and never to monetise the user's data"
seo_description: "Vefta, a privacy-first, offline-first productivity suite in Rust (Axum, Dioxus, PostgreSQL), built solo for Kovelt SAS."
featured: true
status: "en-cours"
role: "Founder and sole developer, Kovelt SAS"
period_start: 2026-06-02
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, axum, dioxus, postgresql, webassembly, architecture-hexagonale, offline-first, privacy-first, ci-cd, sync]
skills: ["4.0.2/E09"]
---

Vefta is the personal productivity suite I am building for Kovelt SAS, the company I founded and where I am the sole developer. Until July 2026 it was called Chronos. The starting idea: a single tool that holds a person's calendar, tasks, contacts, notes, mail and messaging, that works without a connection and whose data never leaves the user's control.

The native foundation and two-way synchronisation with Google Calendar have been delivered since June 2026 (MVP1). The mail cell is being tested by alpha testers. Unified messaging is designed and documented, but not yet built. The professional back office for organisations (Teams) follows the same principle, but its construction remains explicitly frozen behind a measurable economic trigger: that an independent user prefers Vefta to Google and pays the subscription. On-device AI is treated differently: it is a long-term workstream already under way, with fine-tuning of the small local language model in progress. It is free because it runs on the user's own device at almost zero marginal cost, unlike cloud AI, which will remain paid.

## The problem

The starting observation is a concrete one: a freelance graphic designer who always has five tabs open (calendar, mail, notes, a translator and invoicing) and whose tools know nothing about one another. Her address book knows nothing of her appointment history, her calendar knows nothing of her mail, and the whole lot lives with vendors whose business model rests on exploiting her data.

Three concrete frictions follow. Fragmentation: the same information about the same person is scattered across silos that do not talk to each other. Dependence: the dominant suites impose their own account as the user's identity, make leaving costly and fund their free tier with targeted advertising. No local grounding: no major player really models France, with public holidays by zone, school holidays by académie, the tax calendar, and administrative services such as Ameli or the CAF.

Vefta answers each of these frictions with a technical stance rather than a commercial one: a single application rather than a set of walled-off tools, a client that works without the cloud and exportable data rather than a captive account, and French public calendars layered in natively rather than ignored.

## The technical decisions

**Rust from end to end, from the server to the three client targets.** The backend is written in Axum with PostgreSQL; the client, in Dioxus, compiles to WebAssembly for the web and to native binaries for desktop and mobile. The domain and application layers form a single body of code shared between the server and the three client targets: a business rule exists once, not once per platform. That is what lets me carry the three targets in parallel without tripling the work, a point that matters when you are alone on the project.

**The hexagonal dependency rule is enforced by a blocking CI gate, not just by the folder structure.** The pure domain is split into crates by layer, and beyond what the compiler already enforces, a blocking CI gate analyses the `cargo metadata` graph and runs dedicated clippy lints to reject any dependency that would break the direction of the arrows. On a long-running project that I carry alone, I prefer an automatic wall to a discipline I would have to keep reminding myself of.

**The product is split into fault-isolated cells rather than into horizontal layers.** Cœur Suite, AI, Plugin-host and Mail are four independent macro-units, each testable on its own, and none reads another's database: each cell defines its own degraded mode. The promise "if the AI goes down, the calendar keeps working" is a consequence of this topology, not an intention that would have to be checked by hand at every change.

**Synchronisation shows conflicts rather than silently overwriting them.** A generic synchronisation core, based on ETags and on external identifiers carried by sync links, resolves conflicts using last-write-wins but makes them visible rather than letting them disappear. This loop was first proven in its entirety on the narrowest possible case, two-way synchronisation with Google Calendar, delivered as MVP1 in June 2026, before being extended to the other sources.

**The infrastructure is self-hosted and containerised rather than delegated to a PaaS.** The repository runs on a GitLab instance of my own (git.kovelt.fr); production runs on Hetzner Cloud behind a Caddy reverse proxy that handles TLS automatically, in Docker/Podman containers; CI/CD runs for now on the development machine, with a layer cache through cargo-chef, until a dedicated build VPS is available for the beta. It is the same logic as the one applied to the user's data: control the hosting chain from end to end rather than entrust it entirely to a third party.

**Every structural decision is recorded in a journal that is never rewritten.** More than forty-eight ADRs document the decisions with their context and alternatives, and a correction is always added as a new dated entry rather than replacing the old one. Above this journal, a Constitution fixes the product's identity and a handful of non-negotiable refusals, including never imposing Google as a login identifier. The renaming of Chronos to Vefta, in July 2026, went through the same channel: a full prior-art search revealed a near-homophony with Kronos, a player in workforce management, and a name that had become too narrow for a suite that goes well beyond the calendar. The corresponding ADR documents the decision rather than simply renaming a repository.

## What I learned from it

That the discipline that lasts is the one the tooling enforces, not the one you promise yourself: the CI gate on the architecture and the immutable ADR journal play, when working solo, the role that peer review would play in a team. That a thin end-to-end slice, proving the whole chain on the simplest case before widening it, costs less to correct than a system built in breadth before being proven in depth: that is why Google synchronisation was proven only on the calendar before being extended elsewhere. And that deliberately freezing the construction of an already designed workstream behind a measurable trigger, as with the professional Teams back office, avoids design debt without giving up on having thought it through.

## In figures

- More than 48 immutable ADRs (Architecture Decision Records)
- 4 independent macro cells: Cœur Suite, AI, Plugin-host, Mail
- MVP1 delivered in June 2026: native foundation + two-way Google synchronisation
- Renaming of Chronos to Vefta decided in July 2026, with a full prior-art search
- 2 ROCm toolchains compared for fine-tuning the on-device SLM (Mistral 7B, QLoRA)

## Links

- Repository: private, hosted on Kovelt's self-hosted GitLab. The code is proprietary at launch; a deferred opening of the client code is prepared but not triggered.
- Demo: no public demonstration; the product is being tested in a private alpha by a few users.

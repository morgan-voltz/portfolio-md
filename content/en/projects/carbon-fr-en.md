---
title: "carbon-fr"
slug: "carbon-fr-en"
type: "project"
lang: "en"
translation_key: "carbon-fr"
date: 2026-10-26
subtitle: "An API that measures the carbon intensity of French electricity without ever settling the debates it informs"
seo_description: "carbon-fr is an open source Rust API that measures the carbon intensity of French electricity, listed as a data reuse on data.gouv.fr."
featured: true
status: "stable"
role: "Product design, architecture, backend and frontend development, infrastructure and governance, solo"
period_start: 2026-06-14
period_end: ""
repo_url: "https://github.com/Kovelt/carbon-fr"
demo_url: "https://carbon-fr.kovelt.fr/dashboard"
client: ""
is_fil_rouge: false
tags: [rust, postgresql, architecture-hexagonale, typescript, axum, machine-learning, open-data, docker, self-hosted, openapi]
skills: ["4.0.1/FR-09", "4.0.2/E09"]
---

carbon-fr is an open source civic API that exposes the carbon intensity of French electricity in real time, nationally and for the twelve regions, with a public dashboard. It is the French equivalent of the British carbonintensity.org.uk, a service that did not exist in France even though the open data it needs — RTE, ADEME, ENTSO-E — is among the best in Europe. Dual MIT / Apache-2.0 licence, listed as a reuse on data.gouv.fr.

As of 26 October 2026, the project is at version 0.11.0, stable: 30 documented endpoints, a public dashboard, a published TypeScript SDK. I design, develop and host it on my own, and I keep developing it.

## The problem

For several years the United Kingdom has had carbonintensity.org.uk, a national carbon intensity API that has become the reference infrastructure for *carbon-aware computing*: shifting computation to the hours when electricity is least carbon-intensive. France had nothing comparable. RTE publishes excellent raw data, éCO2mix, through the ODRÉ platform — but no stable, documented, regionalised civic API made it usable by developers or integrators.

The use cases, meanwhile, kept multiplying without an answer. The responsible digital movement (Boavizta, GreenIT.fr) wants to schedule its computation in low-carbon slots. The hydrogen sector needs to know, hour by hour, whether grid electricity makes an electrolyser eligible under the RFNBO or low-carbon regulatory frameworks. More broadly, nobody could easily access the breakdown of the electricity price or properly sourced references for the cost of generation.

So the problem was not a lack of data but a lack of an instrument: data scattered across several producers, revised over time, methodologically ambiguous — direct emissions or life-cycle analysis? — and never served in a directly usable form. That is the gap carbon-fr fills, with one constraint set from the first commit: to remain a strictly neutral measuring instrument, one that exposes the data without ever settling the energy debates it informs.

## The technical decisions

**The architecture is hexagonal, to isolate the carbon calculation from sources that change without warning.** The domain (`core`, `eligibility`) is written in pure types with no external dependency; the adapters (ODRÉ, ENTSO-E, weather, forecast, GBDT, Postgres, axum, webhooks) plug into ports. Public data sources change format, frequency and availability without notice; the hexagonal architecture makes each source replaceable and testable in isolation. Evidence: a Cargo workspace of eleven crates and a single server binary.

**Every value carries a `vintage`, because RTE revises its own figures.** The same hour goes through three successive states — real-time, consolidated, final — with different values at each stage; a naive API would silently serve contradictory figures depending on when the request is made. Ingestion relies on an upsert with a composite key (region, timestamp, methodology), completed by a `vintage` field. A direct consequence: no ORM, persistence in native SQL through sqlx (queries checked at compile time), to keep fine control over these upserts.

**Regulatory thresholds are configuration data, never constants in the code.** The eligibility criteria (renewable share ≥ 90 % under Article 4, the surplus exception at €20/MWh, the low-carbon threshold at 64 g) come from European legal texts that evolve; they are versioned and parameterised rather than hard-coded, so that the API follows the texts without being rewritten.

**The legal granularity is not the physical granularity of the calculation.** Regulatory eligibility is assessed at the level of the national bidding zone, whereas carbon-fr calculates at regional level: the API therefore never presents a region as "eligible" as if that had a regulatory meaning of its own — it is only a finer display grid than the one that counts legally.

**No version of the forecasting model goes into production without passing a formal backtest gate.** RTE does not forecast the future: I model the forecast myself, with a climatological baseline model and a gradient boosting model (GBDT) fed by Open-Meteo weather data. This forecast feeds `/schedule` and the greenest window; changing it without control would mean basing scheduling decisions on a degraded model. Evidence: backtest gates set by ADR-0012 and ADR-0028.

**Neutrality is verified by an adversarial review; it is not simply declared.** I systematically serve the alternatives side by side rather than choose in the user's place: two carbon methodologies in parallel (`rte-direct`, `acv-ademe`), two hydrogen eligibility frameworks (RFNBO, low-carbon), LCOE as multi-source ranges. The most sensitive layers — the LCOE comparison (ADR-0024), the eligibility overlays (ADR-0026) — each have a dedicated, versioned neutrality review. The same standard applies to the code: v0.7.0 is an audit release that came out of a full adversarial review of the workspace, with nineteen confirmed defects fixed. And when the data is awkward — Brittany shows 329 g for generation when the national figure is 32 g — the answer is to document it, not to smooth it over: the region generates little, and what it generates is more carbon-intensive, which says nothing about what it consumes (ADR-0008); the consumption series `acv-ademe@2`, corrected for cross-border exchanges, completes the picture (ADR-0010).

## What I learned from it

I learned that neutrality is not a stance one puts on display but an engineering constraint one verifies: parameterising a threshold, providing two frameworks side by side, having a wording checked in an adversarial review are concrete acts, not declared intentions. I learned that documenting awkward data is better than smoothing it over: the Brittany case holds up because the explanation exists in writing before anyone needs it.

I also found that diligence about the licences of sources is an underrated skill in a solo open source project: checking what one is allowed to reuse, ruling out a source whose licence does not fit, auditing the dependencies on every CI run with `cargo-deny`. None of this is visible in the finished API, but it is one of the reasons the project can be cited as a reference on data.gouv.fr.

And that a solo project does not force you to give up team discipline: immutable ADRs and adversarial reviews play the role a peer review would have played. The v0.7.0 audit release, nineteen defects found and fixed, is the proof by result, not by intention alone.

## In figures

- Version 0.11.0 (released on 20 October 2026), stable; v0.7.0 = audit release, nineteen defects confirmed and fixed from a full adversarial review of the workspace
- 30 endpoints under `/v1`, documented in OpenAPI
- Backend in Rust (2024 edition, tokio): Cargo workspace of eleven crates and one server binary, hexagonal architecture
- PostgreSQL persistence in native SQL (sqlx), no ORM
- Geographic coverage: national + 12 regions; 3 carbon methodologies served in parallel (`rte-direct`, `acv-ademe@1`, `acv-ademe@2`)
- 2 hydrogen eligibility frameworks (RFNBO, low-carbon); 233 European electrolysers mapped (European Hydrogen Observatory)
- 35 ADRs (Architecture Decision Records) governing the project
- Dual MIT / Apache-2.0 licence; published TypeScript SDK (`@carbon-fr/sdk`)
- 100 % EU infrastructure: API on a Hetzner VPS behind Traefik, showcase site at o2switch (France); Docker image published by CI on every version tag, self-hostable (a hosted tier with API keys coexists)
- Trademark filed with the INPI; data reuse listed on data.gouv.fr

## Links

- Repository: public, on GitHub (`github.com/Kovelt/carbon-fr`), Kovelt organisation, dual MIT / Apache-2.0 licence.
- Demo: public dashboard (`carbon-fr.kovelt.fr/dashboard`), with an interactive map, regional views and a greenest-slot widget.
- SDK: `@carbon-fr/sdk`, published in TypeScript.
- Documentation: OpenAPI reference of the API (`carbon-fr-api.kovelt.fr/docs`).
- Listing: reuse listed on data.gouv.fr.

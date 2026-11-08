---
title: "GeoForge, the Godot addon"
slug: "geoforge-godot-en"
type: "project"
lang: "en"
translation_key: "geoforge-godot"
date: 2026-10-26
subtitle: "A Godot addon that computes relief, rivers and roads from real physical laws, adjustable point by point"
seo_description: "GeoForge, an open source addon for Godot 4 (C#/.NET), computes relief, rivers and roads from real physical laws and engineering standards."
featured: false
status: "prototype"
role: "Design, development, testing and documentation, solo"
period_start: 2026-03-19
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [godot, csharp, dotnet, clean-architecture, solid, design-patterns, generation-procedurale, openstreetmap, xunit, open-source]
skills: []
---

GeoForge is an open source addon that I am developing for Godot 4, which generates terrain from real geographic data or from manual sculpting in the editor. I should clarify the name straight away: another of my projects, my second-year storyline project (fil rouge), is also called GeoForge (a geometric puzzle in React and Phaser). The two share only the name; the one discussed here is the Godot addon, in C#/.NET, a personal project under the MIT licence.

As of 26 October 2026, the project is in pre-alpha: around fifteen modules are defined, the rework of the water system is under way, and the GitHub repository exists but is still empty.

## The problem

Building a credible open world in a game engine normally takes dozens of hours of manual work: modelling the relief, tracing every river, drawing every road, planting every forest. Unity has mature terrain tools (Gaia Pro, R.A.M), Unreal a native water system. Godot, a fast-growing open source engine, has no complete equivalent, particularly on the .NET/C# side.

GeoForge tackles this gap through two entry points that must converge on the same representation of the terrain: a pipeline that downloads the geographic data of a real region (OpenStreetMap for roads, watercourses, forests and buildings, an SRTM elevation model for the relief) and turns it into playable terrain, and a suite of editing tools built into the Godot editor: sculpting the relief, tracing rivers and roads, painting biomes. It has to be possible to generate a region from OSM and then refine it by hand, or the other way round, without either path being a second-class citizen.

The real problem behind these two entry points is that of setting the parameters. A tool that asks the user to set the width of a river or the slope of an embankment by hand produces a laborious and rarely credible result, because real terrain obeys laws the user does not necessarily know. How can you get a credible result by default without depriving the user of control when they need it?

## The technical decisions

**Every parameter comes from a physical law or an engineering standard, never from an arbitrary choice.** The width and depth of a watercourse follow the hydraulic geometry of Leopold and Maddock, its velocity the Manning-Strickler formula, its sinuosity the Schumm classification; slopes respect the angle of repose of the materials, road design relies on soil bearing capacity (CBR) and on the reference documents of Cerema, SETRA and IDRRIM, and the condition of a road surface on the ASTM D-6433 standard. These values are never set by hand: they are derived. The flip side: every control point exposes nullable fields, so that the user takes the controls back exactly where they want to, and nowhere else.

**The generators do not know one another: they communicate only through the data they share.** Around fifteen modules (GeoCore for the foundations, then GeoMountain, GeoWater, GeoWay, GeoForest, GeoCity, GeoOSM...) read and write a heightmap and metadata layers managed by GeoCore, without ever referencing each other directly. Generation runs as a pipeline ordered by priority: the ground, the mountains, the craters, the water, the roads, the vegetation, the cities. A contributor adds a generator without touching the existing code, following the open/closed principle. That principle is embodied in six named design patterns: Strategy for the data source (`ITerrainDataProvider`), Factory for registering the generators, Observer for chunk loading, Command for undo/redo, Pipeline for ordering, Composite for GeoWater and GeoWay.

**All the interactive editors share the same skeleton.** Whether it sculpts the relief or traces a road, an editor rests on the same framework: a type enum and its static configuration, control points with nullable overrides, computed segment analyses, Godot Resources for the definition and the network, a controller with a state machine, a build service as a pipeline, a UI panel that communicates through signals. Understanding one editor means understanding all the others: the cognitive load does not grow with the number of editing tools.

**Diagnose before rewriting: the case of the river editor.** The first version traced a river as a spline: the user drew the path and set the altitude of each point by hand. The flaw lay upstream of the code: a drawing tool applied to a physical phenomenon, whereas water is not drawn, it flows. The rework adopts a flow-source paradigm: the user places a source, the system traces the gradient descent over the relief, carves the heightmap and builds the water mesh from the result. Before rewriting, I checked what already existed: the inherited `RiverGenerator` component already contained the right gradient-descent algorithm, so the rework keeps it and replaces only the editing front end. The rework document runs to about five hundred and fifty lines: breakdown into components, pseudocode, edge cases, phasing in three stages.

**Zero warnings tolerated, documentation mandatory by construction.** The project compiles with `TreatWarningsAsErrors`: a single warning breaks the build, which in effect makes XML documentation of every public API mandatory, since an undocumented method generates one. One class per file, a shared `.editorconfig`. Continuous integration (GitHub Actions) covers multi-OS builds, tests with coverage, C# formatting, Markdown lint, a security audit of the dependencies and a release pipeline. The same discipline applies to Git: branches `main` → `develop` → feature, commits in Conventional Commits format (for example `feat(river): add flow simulation`), pull requests and squash merges every time, semantic versioning. A personal project has no assigned reviewer; it is the tooling that plays that role.

**Design comes before code.** More than twenty architecture documents were written before the first class, each following the same template: overview, user workflow, physical basis, C# data model, interface mock-up, V1/V2/V3 phasing, known limits. This template makes decisions traceable, records the build-up of my skills and turns implementation into guided execution rather than improvisation.

## What I learned from it

The river editor work taught me two lessons that I reuse elsewhere in the project. First, a tool's mental model must match the physics of the phenomenon it manipulates: a drawing tool applied to a flow produces friction and a barely credible result, however good the interface. Second, audit before rewriting: the inherited component already contained the right algorithm, and I replaced only what deserved to be replaced.

I also learned, in translating scientific publications and engineering standards into production code, that there is a difference between understanding a formula and knowing which one to simplify without betraying it. It is the most formative exercise of the project, more so than writing the code itself.

## In figures

- around fifteen independent modules (GeoCore, GeoWater, GeoWay, GeoOSM...)
- more than 20 architecture documents written before the code
- 6 named and justified design patterns (Strategy, Factory, Observer, Command, Pipeline, Composite)
- 1 rework document of about 550 lines for the river editor alone
- OpenStreetMap import pipeline in 3 stages (fetcher, parser, mapper)
- roadmap in 7 milestones
- Godot 4.3+ engine, C#/.NET 8, MIT licence

## Links

- Repository: the GitHub repository `morgan-voltz/GeoForge` exists but is empty at the time of writing (0 KB, only the project description is filled in); publication of the code is still to come.
- Demo: none for now, the project is in pre-alpha.
- Licence: MIT.

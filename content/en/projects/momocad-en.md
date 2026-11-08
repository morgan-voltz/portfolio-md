---
title: "MomoCad"
slug: "momocad-en"
type: "project"
lang: "en"
translation_key: "momocad"
date: 2026-10-26
subtitle: "A CAD-style 2D drawing program whose state is nothing but a replayed event journal"
seo_description: "MomoCad is a CAD-style 2D drawing program in C#/.NET 10, built on hexagonal architecture and event sourcing, with 275 tests at 100% coverage."
featured: true
status: "en-cours"
role: "Design, development, testing and documentation, solo"
period_start: 2026-09-04
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: true
tags: [csharp, dotnet, architecture-hexagonale, event-sourcing, xunit, plantuml, ddd, clipper2, mcp, avalonia]
skills: ["4.0.2/E00", "4.0.2/E01", "4.0.2/E02", "4.0.2/E03", "4.0.2/E04", "4.0.2/E05", "4.0.2/E06", "4.0.2/O02"]
---

MomoCad is my storyline project (fil rouge) for the second year of the UHA 4.0.2 programme. The brief asks for an application and an API that manage geometric shapes in object-oriented programming: creating and deleting shapes, composing groups of them, computing their mathematical properties, and letting a language model query the data. The name comes from "Morgan" and AutoCAD, the tool that inspired the angle I chose.

As of 26 October 2026, the first delivery, the domain core, is written and fully covered by tests. The following deliveries, the API and its database, the workstation, the synchronisation and the chatbot, are specified and waiting their turn.

## The problem

The brief is designed for a drawing application or a tangram-style game served by a website. I wanted to make something else of it: a small 2D drawing program that behaves like a CAD tool. Shapes are assembled by union and subtraction, and the composite shape that results remembers the shapes it comes from. That is what makes the project interesting to build, because a composition is no longer a simple list of objects but a history of operations.

Two departures from the brief were discussed and approved with my supervisor: a desktop application rather than a website, and local operation first, with synchronisation to a server. The hosted API and database are still owed, as in the brief, and so is the chatbot.

The real technical problem is then this: how do you represent a drawing so that undo, history and synchronisation between several workstations are not features bolted on afterwards, but natural consequences of the model?

## The technical decisions

**Nothing is stored as state, everything is journal.** Every action of the person drawing becomes an event appended to a journal that only grows. The displayed state is a replay of that journal from the beginning. The method that applies an event is the same whether one acts or replays: there can therefore be no gap between what you see after drawing and what you get by rebuilding the drawing from scratch. A test replays the same journal twice and compares the results. History, undo and synchronisation become reads of the journal, not separate mechanisms.

**The hexagonal architecture is guaranteed by the compiler, not by a convention.** The domain core project references no external package, and the methods that modify a shape or a drawing are internal to the project: only the command handlers and the replay can call them, and the tests reach them through an explicit visibility grant. A host, whether console, API or graphical interface, physically cannot modify a drawing by bypassing a command. The dependency graph between the ten projects of the solution was laid down on the first day, before the adapters existed.

**Invariants are written in positive logic.** To reject an invalid radius, the code tests "is not strictly positive" rather than "is less than or equal to zero". The difference matters for a value that is not a number: every comparison with it is false, and the negative form would have let the radius through. The tests check the edge cases with non-numeric and infinite values.

**The geometry is computed by the domain, not delegated.** The area of a polygon comes from the shoelace formula, a circle is discretised into one hundred and twenty-eight segments with a measured relative error, and the coordinates are recentred before computation to keep floating-point precision far from the origin. The Clipper2 library will only be used for the union and subtraction of outlines, behind a port of the domain. While preparing this adapter, I found that the library's official documentation gave a wrong argument order for one of its functions, checked directly against the binary, and I recorded it before writing a single line.

**The tests are reviewed through mutation.** Deliberately breaking the code and watching whether a test fails led me to bracket each tolerance with two tests, one that rejects a value a thousand times smaller and one that accepts the limit value, and to check the content of error messages rather than just their type. The 100% coverage is measured by tooling, not asserted in a README.

**Documentation comes before code.** Nine PlantUML diagrams were written before the classes, then split into four views because a single sheet was no longer readable. The project's vocabulary is fixed in a ubiquitous language, and the documentation itself went through a cross-audit: one hundred and fifty-one raw findings, seventy-seven confirmed by independent verifiers, eighteen scoping decisions settled.

## What I learned from it

That a model chosen for a simple reason, keeping a record of a drawing's operations, gives me for free features I would otherwise have cobbled together one by one. That the strongest safeguards are the ones the compiler imposes, not the ones we promise ourselves to respect. That testing also means trying to make your own tests lie. And that a solo project copes very well with team discipline, independent reviews included, provided you formalise it.

What I would do differently: merge the working branch into the main branch earlier and commit the documentation as I go, rather than leaving one hundred and ninety-five pages outside the repository.

## Correspondence with the storyline steps

| Step of the brief | In MomoCad |
|---|---|
| 0. Specify the MVP in UML | Backlog, wireframe, pseudo-code for the area of a composite shape, validated user story |
| 1. Classes | `Rectangle`, `Triangle`, `Cercle`, instantiated in the console host |
| 2. Encapsulation | Public accessors, internal modifiers callable only by a command or the replay |
| 3. Inheritance | Abstract `Forme`, polymorphism demonstrated by a single loop and tested |
| 4. UML | Nine PlantUML diagrams, four class views |
| 5. Composition and polymorphism | `Dessin` aggregates the shapes and sums areas and perimeters, tested up to one hundred shapes |
| 6. Tests | 275 tests, 100% of the lines and branches of the core |
| 7. API | Delivery 2, to come |
| 8. ORM | Delivery 2, to come, EF Core with SQLite on the workstation and PostgreSQL on the server |
| 9. Deployment | Delivery 2, to come |
| 10. MCP server and chatbot | Delivery 5, to come |
| 11. Front end | Delivery 3, to come, Avalonia desktop application, approved departure |
| Optional: design patterns | Composite prepared, Command half written, Adapter implemented for the in-memory journal |
| Optional: personal | Local first and synchronisation between workstations, deliveries 3 and 4 |

## In figures

- 10 projects in the solution, 36 types and 147 members in the core
- 1,038 lines of application code, 4,385 lines of tests
- 275 tests, 100% of lines and branches covered
- 9 PlantUML diagrams, 2,300 lines of design documentation, a 195-page reference manual
- 18 commits, September 2026

## Links

- Repository: UHA 4.0 internal GitLab, not public. A read-only copy will be published with the following deliveries.
- Official brief of the 4.0.2 storyline project: transcribed in the project documentation.

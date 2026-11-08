---
title: "AUTOFIN Control"
slug: "autofin-control-en"
type: "project"
lang: "en"
translation_key: "autofin-control"
date: 2026-10-26
subtitle: "Reading heterogeneous PDF accounts by geometry rather than by AI, for a financial control that has to stay local"
seo_description: "AUTOFIN Control extracts and checks accounting data from PDFs for the financial control of associations funded by m2A, built in .NET 10."
featured: true
status: "stable"
role: "Lead developer, team of 5"
period_start: 2026-01-09
period_end: 2026-05-31
repo_url: ""
demo_url: ""
client: "m2A (Mulhouse Alsace Agglomération)"
is_fil_rouge: false
tags: [csharp, dotnet, wpf, mvvm, clean-architecture, pdf-extraction, sqlite, excel, xunit, msix]
skills: ["4.0.1/PM-01", "4.0.1/PM-02", "4.0.1/PM-08", "4.0.1/FR-01", "4.0.1/FR-15"]
---

AUTOFIN Control is the client project of my first year on UHA 4.0: a desktop application for the financial control department of m2A, the Mulhouse conurbation. I built it with a team of five students between January and May 2026, and I was the lead developer: about 38% of the 393 commits, the architecture, the extraction pipeline, and a good share of the team's conventions and documentation.

The software was delivered to the client in three signed MSIX versions.

## The problem

Every year, the financial controllers at m2A check the accounts of the associations that the conurbation subsidises. Each association sends a balance sheet and an income statement as PDFs, produced by its own chartered accountant, so there are as many layouts as there are accounting firms. Until then, the work meant re-keying the amounts for the current and the previous financial year by hand into a standardised Excel tracking template: a long, repetitive task and, above all, a source of keying errors on figures where an error is not acceptable.

AUTOFIN Control automates this chain without taking the controller out of it: import the PDF, extract the accounting lines from it, have them checked and corrected visually, aggregate them, attach them to the right categories of the template, then export. One constraint was there from the start and weighed on every choice that followed: the associations' financial data must never leave the controller's workstation. No server, no cloud, no network call: a local SQLite database and processing on the machine, because this is public money and data belonging to third-party associations.

The real technical problem was not reading a PDF but reading it reliably despite layouts we do not control, with a team of five people of very different levels, for a client who was actually going to use it.

## The technical decisions

**The extraction reads the geometry of the PDF, not its raw text, and uses no AI at all.** Two other approaches existed before this one, a mode going through a local language model and a bridge to a Python script, and both were then removed from the project together, `LlmExtractionService` and `RegexGeneratorService` included, along with their NuGet dependencies. What remains is a five-phase pipeline that works only on the X/Y coordinates of the words: `WordRowGrouper` groups the words into rows, `RowCellParser` splits each row into cells, `ColumnPositionDetector` finds the numeric columns by clustering, `NetColumnIdentifier` tells the Net N and Net N-1 columns apart from the Gross and depreciation columns, and `AccountingLineExtractor` builds the final lines. The reason: in a financial control tool, the same PDF must always give the same result, and must stay explainable to someone who did not write the code, which a language model does not guarantee.

**The core of the domain depends on nothing, and you can check it by opening the project file.** `Autofin.Core` references no NuGet package, only models and service interfaces. `Autofin.Infrastructure` implements them (SQLite, ClosedXML, PDF extraction, mapping), and `Autofin.Client` assembles everything through a single dependency injection entry point. With five people of uneven levels in the same repository, this separation is what lets someone touch the extraction without risking breaking the export.

**An extracted line is attached to an accounting category by score, not by the first keyword found.** Labels change from one accounting firm to the next: different abbreviations, accents and capitalisation for the same concept. A simple "first keyword that matches" rule would have put too many lines in the wrong category. The engine normalises the label, adds up positive keywords, subtracts negative keywords that disqualify a category, and adds a bonus if an external configuration already recognises that label for that client. Sixty-three dedicated tests cover the eleven categories of the income statement and the twenty categories of the balance sheet. A second, more recent building block (tickets AC-343/AC-344), `ExcelCategoryReader` and `ExcelCategoryRepository`, reads the categories directly from the Excel workbook the controller imports, and is eventually meant to replace these hard-coded lists, which will survive only as a safety net.

**The Excel export pipeline was entirely redesigned along the way so that it never overwrites a formula in the client's workbook again.** The first version recreated part of the file at each export, at the risk of losing formulas already in place, including between sheets. Ticket AC-395 changed the contract: a new service, `ExcelInsertionExportService`, inserts only the columns for the current financial year, captures and then rewrites the existing formulas, and shifts the history to the right without touching it. The change is recorded in a dated decision log: 131 tests out of 131 pass after the switch.

**Technical debt was measured by audits commissioned from an AI tool, then verified and applied, not discovered by chance.** A global audit as early as February, revisited in March, then an audit per layer in April, with tickets ranked by impact and by effort. One of them put a number on a regression nobody had seen coming: a file of the aggregation page, supposed to stay around three hundred lines, had 922. The fix moved the business logic into the associated ViewModel and brought the file back down to 478 lines, nearly halved. It was moved more than eliminated: that ViewModel itself exceeds 900 lines, two neighbouring files never got the same treatment, and the file has already started growing again. The lesson is not that I solved the problem, but that I gave myself the means to see it come back.

## What I learned from it

That leading a project for a real client changes the nature of the requirement: an error in an amount is not a bug like any other, and the discipline of tests, audits and documentation stops being a box to tick and becomes the condition for a financial controller to trust the tool. That documenting for a team of five people of uneven levels is a job in its own right: a teaching-oriented onboarding guide and a glossary for developers coming from the web, who look for routes where there are ViewModels, are not a nicety: they determine who can actually contribute.

That my Git discipline was built over the course of the project rather than existing from the first commit: the first weeks carry vague messages and commits that touch several subjects at once, the later ones prefixes tied to a specific ticket. It is not just a change of vocabulary: it is what makes a history readable by someone other than yourself.

What I would do differently: settle the contract of the Excel export against the real need before writing the first 900 lines, rather than having to rethink it once the problem of overwritten formulas was noticed. Deal with a defect flagged by an internal audit the same day rather than letting it resurface in the next audit. And split my work into more targeted tasks rather than moving forward on several fronts at once, to meet deadlines more easily.

## In figures

- 5 students in the team, 393 commits in total, of which about 150 (38%) are mine
- about 26,500 lines of C# and XAML, plus auxiliary Python scripts, in the project's most advanced state
- 117 documented tests (54 on extraction, 63 on mapping), 131 out of 131 after the rework of the Excel export
- 13 PlantUML diagrams, about twenty technical documents (architecture per layer, onboarding, audits, client file)
- 3 versions delivered as signed MSIX, from January to May 2026

## Links

- Repository: internal GitLab of the UHA 4.0 programme, not public (access restricted to students and the teaching team).
- No online demo: Windows desktop application for internal use, with no web version.
- Documentation: technical file delivered to the client in February 2026, kept with about twenty architecture and audit documents in the project's repository.

---
title: "chronos-penpot-tools"
slug: "chronos-penpot-tools-en"
type: "project"
lang: "en"
translation_key: "chronos-penpot-tools"
date: 2026-10-26
subtitle: "A Penpot plugin that turns a mock-up into a Dioxus (Rust) component, through a conversion core compiled to WebAssembly"
seo_description: "chronos-penpot-tools, a Penpot plugin that generates Dioxus (Rust) components from a mock-up through a conversion core compiled to WebAssembly."
featured: false
status: "prototype"
role: "Design, development and documentation, solo"
period_start: 2026-02-01
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, webassembly, dioxus, penpot, typescript, vite, design-to-code, wasm-bindgen]
skills: []
---

chronos-penpot-tools is a plugin for Penpot, the open source alternative to Figma, that generates a Dioxus (RSX) component in Rust from a mock-up selected in the editor. I built it to provide tooling for my own product: when I wrote v0.1, that product was still called Chronos; it became Vefta in July 2026. The plugin itself keeps its original name for now; renaming it has not been done yet.

The project is private and remains a prototype. v0.1, delivered in February 2026, covers shapes, text and flexible layouts; a versioned roadmap runs up to a v0.5. The MIT licence is in place from the start, but publication as open source for the Rust and Dioxus community is planned only for v1.0.

## The problem

Dioxus is a cross-platform Rust framework for user interfaces — desktop, web, mobile — whose rendering syntax is called RSX. Penpot is an interface design tool, the open equivalent of Figma. Between the two there is no bridge: going from a Penpot mock-up to a Dioxus component is done by hand, screen by screen, reading through every layer to work out the structure and the styles.

For Chronos, the cross-platform application in Rust and Dioxus that I am developing separately, this manual translation came up again with every new screen. I built the bridge myself: a Penpot plugin that reads the user's selection and generates the corresponding Dioxus RSX component, ready to paste into the code. A "Figma-to-code", but for an open source ecosystem and a target, Rust, that no tool of this kind covers.

The real problem is not reading a Penpot JSON file: it is getting from a tree of layers designed for a visual editor to code that a Rust developer will want to keep as it is, without locking the translation into a step that would rule out any future extension to other targets or other ways of styling.

## The technical decisions

**All the conversion logic lives in a Rust crate compiled to WebAssembly, never in the plugin's TypeScript.** The plugin runs in Penpot's sandboxed iframe, but the translation of a tree of layers into a Dioxus component is a separate crate, `penpot-to-dioxus`, called from the TypeScript through `wasm-bindgen`. There are two reasons for this split: the complex logic stays in a typed language, testable with `cargo test` independently of the browser, and the crate is designed to be reusable outside the plugin, as a CLI or directly in the tooling of Chronos/Vefta. The crate is built as both `cdylib`, for the WebAssembly, and `rlib`, for the tests, and is compiled with `opt-level = "s"`, LTO and strip to keep the WASM binary small.

**The converter goes through an intermediate representation rather than translating the Penpot JSON into RSX directly.** The crate is structured like a small compiler in six modules: `parser.rs` deserialises the Penpot JSON, through serde, into a typed `PenpotNode` tree — fills, strokes, effects, flex and grid layouts, text, component information; `transform.rs` converts that tree into the `DioxusNode` intermediate representation, with heuristics such as choosing between `h1` to `h4`, `p` or `span` according to the font size of a piece of text; `rsx_builder.rs` produces the final RSX code, indented and readable; `styles.rs` translates the visual properties into CSS — hexadecimal colours to rgba, gradients, alignments, grid tracks; `svg_extract.rs`, planned for v0.4, will detect the shapes to be exported as SVG; `lib.rs` is the crate's entry point, which exposes the function `#[wasm_bindgen] fn convert(json, options)` called from the TypeScript. Going through this intermediate representation rather than a direct translation makes the generator extensible to new targets and new styling modes, and makes it testable module by module.

**The TypeScript plugin is split into two worlds that communicate only by messages.** Penpot's Plugin API imposes this separation: `plugin.ts` is the only file with access to the `penpot` API, it reads the selection and serialises the design tree; `main.ts` is the iframe's user interface, which initialises the WebAssembly module and displays the preview of the generated code with copy and download; `wasm-bridge.ts` loads that module asynchronously. This boundary limits the surface that touches the user's actual document, and the plugin asks only for the minimal `content:read` permission: it is deliberately read-only, and cannot modify anything in the source design.

**The specification comes before the code: a mapping table and a negative scope were written before the first line of Rust.** `MAPPING.md` fixes the philosophy of the conversion — visual fidelity first, idiomatic code that can be edited by hand, no raw HTML dump, semantic naming derived from the layers — and a versioned roadmap runs from v0.1 (shapes, text, flex) to v0.5 (CSS classes and pattern detection), by way of Penpot components, typed props, grids, gradients and shadows, then SVG export. The negative scope is explicitly stated: no generation of business logic, no automatic responsive behaviour in v1. The known limitations are documented as localised TODOs in the code, by file and function, rather than hidden.

**The MIT licence is in place from v0.1, but publication as open source is deferred to v1.0.** The tool was born of an internal need, to provide tooling for Chronos/Vefta, and remains a private repository on my personal self-hosted GitLab until the generator has reached its full roadmap. The licence is chosen from the start so as not to have to come back to it later, but I would rather deliver a tool that keeps its promises to the Rust/Dioxus community than a half-finished plugin already carrying a public name.

## What I learned from it

That building the conversion crate as a pure Rust module, verifiable with `cargo test` without a browser or an iframe, avoids debugging the translation logic and the WebAssembly plumbing at the same time: when a test in `transform.rs` fails, I already know it is not a problem of WASM loading or `postMessage`. That the step easiest to skip out of impatience is also the most expensive to skip: `MAPPING.md` and its negative scope, written before the first line of Rust, set the requirement of idiomatic RSX and the explicit refusal of the raw HTML dump as a principle of the project from the start, not as a constraint to be rediscovered as the code goes along. That choosing an intermediate representation rather than a direct translation has an immediate cost, six modules instead of a single file that does everything, but that this cost disappears as soon as you want to add a target or a styling mode, which v0.4 and v0.5 will do without touching the core already written. And that building the tool my own product needs, rather than making do with existing tools, brought me into Rust and WebAssembly through the most demanding door: understanding a complete compilation chain, from `wasm-pack` to two separate Vite entry points, before it becomes invisible.

## In figures

- Rust crate `penpot-to-dioxus`: about 1,400 lines, 6 modules, compiled to WebAssembly
- TypeScript plugin: about 500 lines, built with Vite
- 4 design documents, including `MAPPING.md`
- v0.1 (MVP) delivered in February 2026; versioned roadmap up to v0.5
- MIT licence in place from v0.1

## Links

- Repository: private, hosted on my personal self-hosted GitLab (Kovelt infrastructure). Publication as open source under the MIT licence is planned for v1.0, for the Rust and Dioxus community.
- Demo: no public demo for now.

---
title: "DevEye"
slug: "deveye-en"
type: "project"
lang: "en"
translation_key: "deveye"
date: 2026-10-26
subtitle: "An MCP server in Rust that gives an AI agent eyes and hands on a live graphical application"
seo_description: "DevEye, an MCP server in Rust with 41 tools, gives an AI agent eyes and hands on a graphical application, down to input injection on Wayland."
featured: false
status: "en-cours"
role: "Design, development, testing and audits, working solo"
period_start: 2026-06-01
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, mcp, architecture-hexagonale, wayland, kwin, cdp, dbus, dioxus, ia-agentique]
skills: ["4.0.2/E10"]
---

DevEye is an MCP (Model Context Protocol) server written in Rust that gives an AI agent (Claude Code in particular, though any MCP client will do) the ability to observe and drive a live graphical application: screenshots, clicks, typing, state inspection, log reading, conditional waits. The agent uses it like any other tool, with no human intervention.

The project is private (a personal GitLab repository, Kovelt Utilities) and has been under development since June 2026. Milestones M0 to M7 have been delivered since the end of June, and development continues in sprints that extend the tools. An open-source release is planned for version 1.0, once the tool is stable.

## The problem

An AI agent that can read and write code has neither eyes nor hands in front of a graphical application: it cannot see what its last change produced on screen, cannot click a button, cannot tell that a UI is broken. For the web, bridges already exist (Playwright and automated browsers). For native desktop applications, particularly in the Rust/Dioxus ecosystem that I use for my own products, nothing comparable existed.

I built DevEye to fill that gap, as a cross-cutting tool rather than a throwaway script for a single project: it already serves as tooling for my other products, and its target is not a single UI technology but every one I use or might use.

The technical problem that follows is to bring together, under a single abstraction, ways of driving an interface that have almost nothing in common: the DevTools protocol of a Chromium browser, a custom-built HTTP bridge embedded in the target app, the D-Bus screen capture of a Wayland compositor and, when there is neither a browser nor an embedded SDK, input injection at the level of the system itself, on Wayland, an ecosystem designed precisely to prevent that kind of injection.

## The technical decisions

**The core knows no driving technology, only ports.** `deveye-core` is a pure domain (`Snapshot`, `InteractionCommand`, `LogEvent`) without a single technical dependency, and `deveye-ports` defines twelve Rust traits (`Renderer`, `AppInspector`, `InteractionSink`, `JsEvaluator`, `LogSource`, `CommandRouter`, `WaitCondition`, `WindowManager`, `Clipboard`…) that ten adapters each implement for a different technology. Adding a new target means writing one more adapter, never touching the core or the forty-one MCP tools built on the ports: hexagonal architecture, tested on a real case and not merely stated as a principle.

**Four targeting modes choose the technology, never the agent.** The `cdp` mode talks to the Chrome DevTools Protocol for any Chromium WebView, `sdk` goes through a custom-built HTTP bridge that an app embeds in one line, `kwin-eis` injects input at the system level for apps with neither a WebView nor an SDK (the "eyes" then go through screen capture), and `auto` tries `cdp` first and switches call by call to `sdk` as soon as the first one fails. I did not want the agent driving DevEye to have to know which technology is answering on the other side.

**Input injection on Wayland goes through the official KWin EIS portal, in pure Rust, without FFI.** Wayland is designed to prevent one application from injecting input into another: no XWayland to get around, no `uinput` to open directly. The only legitimate path is the `org.kde.KWin.EIS.RemoteDesktop` portal, implemented with the `reis` crate. It is negotiated over a D-Bus connection that must stay open, or the handshake simply ends in an EOF, a protocol pitfall that I discovered by running into it. On the keyboard side, each Unicode keysym must be resolved to an evdev keycode through `xkbcommon` before it can be injected. This is the most intricate part of the project.

**The "dev tool" posture is defended in depth, not merely declared in a README.** `deveye_sdk::init()` compiles no DevEye code outside `debug_assertions`, even if the integrator forgets their `#[cfg]`; the server binds only to loopback (`127.0.0.1`, never `0.0.0.0`) and rejects any non-local `Host` header with a 403, before any route; CORS is deny-all and the exposed crates carry `#[forbid(unsafe_code)]`. The JS evaluation queue is bounded and answers 503 rather than letting memory grow if the target app is frozen, and the log buffer is capped by overwriting the oldest entries. Each mechanism addresses one specific way of leaking into production, not a generic risk.

**The tool is audited with the rigour it applies to other people's code.** A formal internal audit that I carried out after milestone M7 produced one hundred and eight prioritised findings, including blocking gates, followed by a second audit in July. The CI, on my own self-hosted GitLab runner, runs `cargo fmt --check`, `clippy` with `-D warnings` and the workspace tests, plus `cargo-deny` to audit dependencies and licences; the tests that need a real Wayland session are isolated with `#[ignore]`.

## What I learned from it

That fitting four driving technologies under a single abstraction forces you to name precisely what they have in common, a render, an input, a wait, rather than stacking them behind special cases. That wait primitives are the real key to the reliability of an agent loop: an AI agent chains actions far faster than a human, so I had to serialise the MCP calls and wait for an explicit condition rather than a fixed delay for the loop to hold up. And that designing for an agent as the end user is a discipline of its own: the forty-one tools have precise contracts and structured returns that I designed to be understood and chained by an LLM, not read by a human, a form of UX whose user is not human.

## In figures

- 15 crates, 71 files, about 13,500 lines of code
- 41 MCP tools in six families, on 12 ports and 10 adapters
- 4 driving channels: CDP, embedded SDK bridge, KWin capture, KWin EIS injection
- 65 commits between June and July 2026, milestones M0 to M7 delivered on 25 June (a figure for that phase; development has continued since in sprints that extend the tools)
- 108 findings raised by the internal audit that followed milestone M7, a second audit carried out in July
- 3 reference documents (usage, architecture, SDK wire protocol)

## Links

- Repository: personal self-hosted GitLab (Kovelt Utilities), private. An open-source release is planned for version 1.0.

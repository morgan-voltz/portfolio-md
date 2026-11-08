---
title: "xvii"
slug: "xvii-en"
type: "project"
lang: "en"
translation_key: "xvii"
date: 2026-10-26
subtitle: "A sixteen-track audio studio in Rust whose real-time thread can neither allocate nor wait"
seo_description: "XVII is a prototype sixteen-track audio studio in Rust and Dioxus: its real-time thread never allocates or locks, and the design was fixed before the code."
featured: false
status: "prototype"
role: "Architecture design and prototyping, solo"
period_start: 2026-07-18
period_end: 2026-07-18
repo_url: "https://github.com/morgan-voltz/xvii"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, dioxus, cpal, symphonia, rubato, audio-temps-reel, dsp, mcp]
skills: []
---

XVII is a sixteen-track audio studio in Rust and Dioxus, designed for annotating my metal songs: laying down a structure (verse, riff A, breakdown, solo), setting a tempo that changes along the way, and synchronising lyrics line by line and then word by word. The name comes from the Roman numeral seventeen: the seventeenth track, the one for lyrics, which is added to the sixteen audio tracks.

As of 26 October 2026, only phase 1 of the roadmap's 6 has been delivered: playing an audio file passed as an argument in a minimal window, with an adjustable master gain. The full architecture, though, was settled in writing before the first line of code.

## The problem

My songs do not follow a tidy verse-chorus structure: blast beats, half-time and double-time, breaks that never return in the same form. Common automatic annotation tools assume a regular structure and get this kind of music wrong, or worse, impose their reading without saying so. I wanted a tool that proposes an analysis when it can, but never takes control away from me: laying down a section, correcting a tempo, resynchronising a line must always remain possible, whatever the quality of the automatic detection.

The second problem is personal. I am comfortable in Rust but a complete beginner in signal processing. XVII is as much a tool I want to use as a project for learning real-time audio programming and the basics of DSP (FFT, transient detection, tempo estimation) before tackling the part that interests me most.

The real architecture problem, then, is this: how do you build an audio engine whose real-time thread tolerates no pause, while letting a human interface and, tomorrow, an AI assistant drive the same application without ever duplicating the validation rules?

## The technical decisions

**The model knows neither audio nor the interface.** The `xvii-model` crate depends only on `serde`: it is Cargo, not a convention, that forbids bringing an audio or UI type into it. Above it, `xvii-engine` and `xvii-decode` know only the model; `xvii-app` (Dioxus) and `xvii-mcp` (the future server for an AI assistant) are the only two assembly points, and they do not know each other. Both will go through the same door, `EngineHandle`, where a gain is bounded to ×1.5 and a seek to 2^60 frames: validation lives once, not once per consumer.

**The audio thread obeys six non-negotiable rules, with no exceptions.** The `cpal` callback must never allocate or free memory, never take a lock, never perform I/O, and must run in bounded time; all communication goes through a lock-free queue (`rtrb`, one producer, one consumer) and atomics. The subtlest rule: never release an `Arc` on this thread. Replacing a track sends the old one to a return queue that the interface empties at its leisure, with a last resort if it overflows: leak the memory (`mem::forget`) rather than break the rule. `xvii-engine/src/rt.rs` (486 lines) concentrates this code, covered by 12 tests that run without a sound card, since CI machines have none.

**A volume change must never be abrupt.** Applying a gain instantly produces an audible click ("zipper noise"): the waveform jumps like a staircase step. Track, mute and master gains therefore glide towards their target value through a one-pole exponential filter with a time constant of about 5 ms, short enough to react, long enough that no ear can hear it.

**All analysis data must stay editable by hand, with its provenance and its confidence.** This is decision D11: nothing produced by the future analysis module will be final; a detected section will carry a confidence and can be overwritten by a section laid down by hand, which always prevails. This is not an abstract precaution: on a passage of distorted vocals (growl, scream), speech recognition loses the phonemes and its confidence collapses, and the interface brief already provides for a visual state of "visible give-up" rather than inventing a result. I prefer a tool that says it does not know to one that lies politely.

**A critical review of the code found five real bugs before they became audible.** An extreme seek made the playhead's arithmetic panic; a device advertising up to 34 channels ("pro audio" mode) could be chosen by default although the mixer feeds only two; a quick double press on play/pause could swallow the second press; playback did not pause at the end of the file; a device error was never reported to the interface. All five were fixed in the same commit, with the seek bound brought back to a single validation point.

## What I learned from it

That documenting an architecture before writing code forces you to settle questions (which internal time unit, who is allowed to modify what) that you would otherwise put off until later, when they cost the most to fix. That real-time audio is a discipline of its own: most of the reflexes of "normal" programming (allocating, locking, performing I/O) are forbidden there, and you need a different vocabulary (lock-free queues, atomics, return queue) to do without them. And that setting a rule like D11 at design time avoids discovering after the fact that automatic detection will fail on my own music.

What I would do differently: not leave the project paused for several months right after the scaffolding, and aim for a shorter vertical slice that reaches the DSP part in the very first sessions, rather than delivering all the plumbing (workspace, decoding, minimal window) before tackling it. That is precisely the part that interested me most when I started, and it is the one I have not yet begun.

## In figures

- 6 crates in a Cargo workspace (resolver 3, edition 2024)
- 2,284 lines of Rust across 21 files, 37 files in total in the repository
- 23 tests, 12 of them on the real-time audio thread, run without a sound card
- 994 lines of documentation across 4 files: the architecture (684 lines, 16 sections, 12 decisions settled before the code), the interface brief (162 lines), the collaboration contract (100 lines), the README (48 lines)
- GitHub Actions CI on 3 operating systems (Ubuntu, Windows, macOS) from the first commit
- 10 commits, on 18 July 2026, in two sessions
- 1 phase delivered out of the 6 on the roadmap

## Links

- Repository: [github.com/morgan-voltz/xvii](https://github.com/morgan-voltz/xvii), public.
- No online demo and no packaged version: current use is `cargo run -p xvii-app -- morceau.mp3`, locally.

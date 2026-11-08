---
title: "ancestors-modding"
slug: "ancestors-modding-en"
type: "project"
lang: "en"
translation_key: "ancestors-modding"
date: 2026-10-26
subtitle: "A reverse-engineering toolkit for modding an Unreal Engine game that its studio abandoned with no SDK and no documentation"
seo_description: "Reverse engineering of an Unreal Engine game abandoned by its studio, to reconstruct its internal architecture and publish the first modding tools."
featured: false
status: "prototype"
role: "Reverse engineering, development of the two tools and documentation, solo"
period_start: 2026-03-23
period_end: 2026-03-23
repo_url: "https://github.com/morgan-voltz/ancestors-modding"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [python, tkinter, reverse-engineering, unreal-engine, modding, binary-patching, debug-symbols, open-source]
skills: []
---

ancestors-modding is a personal project, published under the MIT licence: a reverse-engineering and modding toolkit for *Ancestors: The Humankind Odyssey*, a game built on Unreal Engine 4.20 whose studio stopped developing it in 2020.

I built and published it in a single session, on 23 March 2026 (four commits in 38 minutes), and I have not been back to it since. The repository contains no game assets, only tools and documentation: eleven Markdown documents and two Python scripts.

## The problem

*Ancestors: The Humankind Odyssey* simulates human evolution over several million years. Its studio, Panache Digital Games, stopped developing it in 2020, without ever releasing an SDK or modding tools. Players who wanted to change anything in the game, such as the difficulty, an animal's behaviour or an evolution parameter, had nothing: no documented format, no editor, not even a working example.

Technically, the game stores all its data in proprietary Unreal Engine `.pak` files: not encrypted, but not documented either. A game value, an animal's maximum health for instance, is stored in a `.uasset` file whose internal format (compiled Blueprint classes, serialised properties) has no public specification.

The problem I set myself was not to write a complete parser for this format, a project in itself, but to answer a narrower question: how far can you get without one? Identify a specific value in an unknown binary file, change it without corrupting the file, and document the method so that it is still there for the next person who wants to mod the game.

## The technical decisions

**I used a debug symbols file rather than guessing the internal structure blindly.** A shipped commercial game almost never includes its debug symbols; this one does, with a 625 MB PDB file. I used it to read, rather than guess, the engine's class prefixes (`CDS`, `CDL`, `CDD`, `CS`, `GC`, `GCS`, `HE`...) and to quantify the architecture: 508 compiled Blueprint source files (`.gen.cpp`) identified, the `ClanMember` class referenced 40,570 times in the binary, `Settlement` 9,599 times, the `Spawn` and `Die` functions called 4,366 and 1,023 times respectively. That tally, recorded in my research journal, gave me a map of the system before I wrote a single line of patch.

**I patched the bytes directly rather than writing a complete parser for the `.uasset` format.** Unreal Engine's asset format is proprietary and undocumented; building a generic parser just to change a few gameplay numbers would have been out of proportion. For a value stored on its own in a compiled Blueprint class, a `FloatProperty`, it is enough to locate it at a fixed offset and overwrite it with `struct.pack_into`, keeping the file size strictly identical. The leopard's `HealthMax`, for example, is a float at offset 1140 in `VL01_CDSHealth_Leopard.uasset`; changing it from 9.0 to 1.0, then repackaging it with the external tool `repak` (version 0.2.3) into an override `_P.pak`, was my first working mod, documented end to end. I also noted the limit of the method on the very day I found it: it corrupts textures (the headers between mipmaps) and `DataTable`s (the serialisation of `FName`s), which would need a tool that truly understands the format, such as UAssetAPI, which I did not write.

**I designed the graphical tool for a player, not for myself: automatic detection and a risk-free uninstall.** `find_game_dir()` first tests whether the script is being run from the game folder itself, then five common Steam locations, then the Windows registry (`winreg`), before falling back to a manual selection. And because Unreal loads any `*_P.pak` file placed next to the game's paks to replace the files of the same name without ever touching the original, uninstalling simply means deleting it: the `uninstall_mod()` function is seven lines long, and there is nothing else to undo.

**I documented the research before the tools, in greater volume than the code itself.** With no SDK and no parser to cite, what the community lacked was not two scripts but knowing where the values are and how far they can be touched. I wrote 2,856 lines of Markdown documentation against 766 lines of Python, roughly 3.7 times as much, including a dated journal that explicitly separates what works from what does not, and a map of the game's main pak (18,105 files, 3.75 GB). The game has a second pak, `VL01E01.pak` (2.98 GB, 14,610 files, of which 14,608 are already in the first); I did not map it separately because it duplicates the first by more than 99%.

## What I learned from it

That reading the debug symbols and call counters of an unknown binary teaches more, and faster, than guessing from the contents of the files alone. That the rigour of a method is measured by how clearly you note its limits on the day you find them, not six months later from memory: that is what stopped me from recommending direct binary patching for textures or `DataTable`s, which it corrupts. And that opening a project to contributors in a README (3D artists, researchers, tool developers) does not bring any: seven months after publication, the repository history still shows only one author.

What I would do differently: my two tools do not protect the patch in the same way, and I only noticed when re-reading the code for this sheet. `patch_health.py` only compares the size of the extracted file, as a warning, not a block; `AncestorsDifficultyMod.py` only compares the old value, with a tolerance of 0.01, and raises a blocking error if it is exceeded. I would run both checks in each tool rather than keeping only one per script. I would also link `docs/VISION_AND_LORE.md` back into the navigation: it exists on disk but appears neither in the README table nor in the tree of `CONTRIBUTING.md`, which makes it invisible to anyone following the documentation as laid out.

## In figures

- 766 lines of Python: 174 for the CLI (`patch_health.py`), 592 for the GUI (`AncestorsDifficultyMod.py`), 0 tests, no CI
- 2,856 lines of Markdown documentation (11 files in `docs/` + README of 223 lines + CONTRIBUTING of 103 lines), roughly 3.7 times the volume of code
- 4 commits, a single author, in 38 minutes on 23 March 2026 (02:03–02:41); 0 Git tags
- 1 PDB file of 625 MB analysed, 508 compiled Blueprint source files (`.gen.cpp`) identified
- 18,105 files in the game's main pak (3.75 GB), 14,610 in a second pak (2.98 GB)
- 14 animal species mapped (health from 3.0 to 20.0), 17 evolution branches, one shared 77-bone skeleton
- Assets extracted: 55 animated meshes, 1,082 static meshes, 3,079 animations, 5,848 textures, 1,730 materials

## Links

- Repository: [github.com/morgan-voltz/ancestors-modding](https://github.com/morgan-voltz/ancestors-modding), public, MIT licence
- No demo: a modding tool is only of use to owners of the game
- Full documentation (getting-started guide, asset map, research journal) in the repository, under `docs/`

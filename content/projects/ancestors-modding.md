---
title: "ancestors-modding"
slug: "ancestors-modding"
type: "project"
date: 2026-10-26
subtitle: "Une boîte à outils de rétro-ingénierie pour moder un jeu Unreal Engine que son studio a abandonné sans SDK ni documentation"
seo_description: "Rétro-ingénierie d'un jeu Unreal Engine abandonné par son studio, pour reconstituer son architecture interne et publier les premiers outils de modding."
featured: false
status: "prototype"
role: "Rétro-ingénierie, développement des deux outils et documentation, en solo"
period_start: 2026-03-23
period_end: 2026-03-23
repo_url: "https://github.com/morgan-voltz/ancestors-modding"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [python, tkinter, reverse-engineering, unreal-engine, modding, binary-patching, debug-symbols, open-source]
skills: []
---

# ancestors-modding

ancestors-modding est un projet personnel, publié en licence MIT : une boîte à outils de rétro-ingénierie et de modding pour *Ancestors: The Humankind Odyssey*, un jeu sous Unreal Engine 4.20 que son studio a cessé de développer en 2020.

Je l'ai construit et publié en une seule session, le 23 mars 2026 — quatre commits en 38 minutes — et je n'y suis pas retourné depuis. Le dépôt ne contient aucun asset du jeu, seulement des outils et de la documentation : onze documents Markdown et deux scripts Python.

## Le problème

*Ancestors: The Humankind Odyssey* simule l'évolution humaine sur plusieurs millions d'années. Son studio, Panache Digital Games, en a arrêté le développement en 2020, sans jamais publier de SDK ni d'outils de modding. Les joueurs qui voulaient changer quoi que ce soit au jeu — la difficulté, le comportement d'un animal, un paramètre d'évolution — n'avaient rien : pas de format documenté, pas d'éditeur, pas même un exemple qui fonctionne.

Techniquement, le jeu range toutes ses données dans des fichiers `.pak` propriétaires d'Unreal Engine : non chiffrés, mais non documentés non plus. Une valeur de jeu, la santé maximale d'un animal par exemple, est stockée dans un fichier `.uasset` dont le format interne — classes Blueprint compilées, propriétés sérialisées — n'a pas de spécification publique.

Le problème que je me suis posé n'était pas d'écrire un parseur complet de ce format, un projet en soi, mais une question plus étroite : jusqu'où peut-on aller sans lui ? Identifier une valeur précise dans un fichier binaire inconnu, la changer sans corrompre le fichier, et documenter la méthode pour qu'elle survive au prochain qui voudra moder le jeu.

## Les décisions techniques

**J'ai exploité un fichier de symboles de débogage plutôt que de deviner la structure interne à l'aveugle.** Un jeu commercial livré n'embarque presque jamais ses symboles de debug ; celui-ci le fait, avec un fichier PDB de 625 Mo. Je m'en suis servi pour lire, plutôt que deviner, les préfixes de classes du moteur (`CDS`, `CDL`, `CDD`, `CS`, `GC`, `GCS`, `HE`...) et pour quantifier l'architecture : 508 fichiers source Blueprint compilés (`.gen.cpp`) identifiés, la classe `ClanMember` référencée 40 570 fois dans le binaire, `Settlement` 9 599 fois, les fonctions `Spawn` et `Die` appelées respectivement 4 366 et 1 023 fois. Ce comptage, consigné dans mon journal de recherche, m'a donné une carte du système avant d'écrire la moindre ligne de patch.

**J'ai patché l'octet directement plutôt que d'écrire un parseur complet du format `.uasset`.** Le format d'assets d'Unreal Engine est propriétaire et non documenté ; construire un parseur générique pour changer quelques chiffres de gameplay aurait été disproportionné. Pour une valeur stockée seule dans une classe Blueprint compilée, un `FloatProperty`, il suffit de la localiser à un offset fixe et de l'écraser avec `struct.pack_into`, en gardant la taille du fichier strictement identique. Le `HealthMax` du léopard, par exemple, est un flottant à l'offset 1140 dans `VL01_CDSHealth_Leopard.uasset` ; le faire passer de 9.0 à 1.0, puis repackager avec l'outil externe `repak` (version 0.2.3) dans un `_P.pak` d'override, a été mon premier mod fonctionnel, documenté de bout en bout. J'ai aussi noté la limite de la méthode le jour même où je l'ai trouvée : elle corrompt les textures (les en-têtes entre mipmaps) et les `DataTable` (la sérialisation des `FName`), qui demanderaient un outil qui comprend vraiment le format, comme UAssetAPI — que je n'ai pas écrit.

**J'ai conçu l'outil graphique pour un joueur, pas pour moi : détection automatique et désinstallation sans risque.** `find_game_dir()` teste d'abord si le script est lancé depuis le dossier du jeu lui-même, puis cinq emplacements Steam courants, puis le registre Windows (`winreg`), avant de se rabattre sur une sélection manuelle. Et parce qu'Unreal charge n'importe quel fichier `*_P.pak` placé à côté des paks du jeu pour remplacer les fichiers qui portent le même nom sans jamais toucher à l'original, désinstaller consiste juste à le supprimer : la fonction `uninstall_mod()` fait sept lignes, il n'y a rien d'autre à défaire.

**J'ai documenté la recherche avant les outils, avec plus de volume que le code lui-même.** Sans SDK ni parseur à citer, ce qui manquait à la communauté n'était pas deux scripts mais de savoir où sont les valeurs et jusqu'où on peut les toucher. J'ai écrit 2 856 lignes de documentation Markdown contre 766 lignes de Python, soit environ 3,7 fois plus, dont un journal daté qui sépare explicitement ce qui marche de ce qui ne marche pas, et une cartographie du pak principal du jeu (18 105 fichiers, 3,75 Go). Le jeu a un second pak, `VL01E01.pak` (2,98 Go, 14 610 fichiers, dont 14 608 déjà présents dans le premier) ; je ne l'ai pas cartographié séparément parce qu'il fait doublon à plus de 99 %.

## Ce que j'en ai appris

Que lire les symboles de debug et les compteurs d'appel d'un binaire inconnu apprend plus, et plus vite, que deviner à partir du seul contenu des fichiers. Que la rigueur d'une méthode se mesure à la clarté avec laquelle on note ses limites le jour où on les trouve, pas six mois plus tard de mémoire : c'est ce qui m'a évité de recommander le patch binaire direct pour les textures ou les `DataTable`, qu'il corrompt. Et qu'ouvrir un projet à des contributeurs dans un README — artistes 3D, chercheurs, développeurs d'outils — n'en fait pas venir : sept mois après la publication, l'historique du dépôt ne montre toujours qu'un seul auteur.

Ce que je ferais autrement : mes deux outils ne protègent pas le patch de la même façon, et je ne l'ai remarqué qu'en relisant le code pour cette fiche. `patch_health.py` compare seulement la taille du fichier extrait, en avertissement, pas en blocage ; `AncestorsDifficultyMod.py` compare seulement l'ancienne valeur, avec une tolérance de 0.01, et lève une erreur bloquante si elle est dépassée. Je recouperais les deux contrôles dans chaque outil plutôt que d'en garder un seul par script. Je recollerais aussi `docs/VISION_AND_LORE.md` à la navigation : il existe sur le disque mais n'apparaît ni dans le tableau du README ni dans l'arborescence de `CONTRIBUTING.md`, ce qui le rend invisible à qui suit la documentation telle qu'annoncée.

## En chiffres

- 766 lignes de Python : 174 pour le CLI (`patch_health.py`), 592 pour la GUI (`AncestorsDifficultyMod.py`) — 0 test, aucune CI
- 2 856 lignes de documentation Markdown (11 fichiers dans `docs/` + README 223 lignes + CONTRIBUTING 103 lignes), environ 3,7 fois le volume de code
- 4 commits, un seul auteur, en 38 minutes le 23 mars 2026 (02h03–02h41) ; 0 tag Git
- 1 fichier PDB de 625 Mo analysé, 508 fichiers source Blueprint compilés (`.gen.cpp`) identifiés
- 18 105 fichiers dans le pak principal du jeu (3,75 Go), 14 610 dans un second pak (2,98 Go)
- 14 espèces animales cartographiées (santé de 3,0 à 20,0), 17 branches d'évolution, un squelette partagé à 77 os
- Assets extraits : 55 meshes animés, 1 082 meshes statiques, 3 079 animations, 5 848 textures, 1 730 matériaux

## Liens

- Dépôt : [github.com/morgan-voltz/ancestors-modding](https://github.com/morgan-voltz/ancestors-modding) — public, licence MIT
- Pas de démo : un outil de modding ne s'adresse qu'aux possesseurs du jeu
- Documentation complète (guide de démarrage, cartographie des assets, journal de recherche) dans le dépôt, sous `docs/`

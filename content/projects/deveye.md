---
title: "DevEye"
slug: "deveye"
type: "project"
date: 2026-10-26
subtitle: "Un serveur MCP en Rust qui donne à un agent IA des yeux et des mains sur une application graphique vivante"
seo_description: "DevEye, serveur MCP en Rust (41 outils), donne à un agent IA des yeux et des mains sur une application graphique, jusqu'à l'injection d'entrées sous Wayland."
featured: false
status: "en-cours"
role: "Conception, développement, tests et audits, en solo"
period_start: 2026-06-01
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, mcp, architecture-hexagonale, wayland, kwin, cdp, dbus, dioxus, ia-agentique]
skills: ["4.0.2/E10"]
---

DevEye est un serveur MCP (Model Context Protocol) écrit en Rust qui donne à un agent IA — Claude Code en particulier, mais tout client MCP convient — la capacité d'observer et de piloter une application graphique vivante : captures d'écran, clics, saisies, inspection d'état, lecture de logs, attentes conditionnelles. L'agent s'en sert comme de n'importe quel autre outil, sans intervention humaine.

Le projet est privé (dépôt GitLab personnel Kovelt Utilities) et se développe depuis juin 2026. Les jalons M0 à M7 sont livrés depuis fin juin, et le développement continue par sprints d'extension d'outils. Une publication en open source est prévue à la version 1.0, une fois l'outil stabilisé.

## Le problème

Un agent IA qui sait lire et écrire du code reste aveugle et manchot devant une application graphique : il ne voit pas ce que son dernier changement a produit à l'écran, ne peut pas cliquer sur un bouton, ne peut pas constater qu'une UI est cassée. Pour le web, des ponts existent déjà (Playwright et les navigateurs pilotés). Pour les applications desktop natives, en particulier dans l'écosystème Rust/Dioxus que j'utilise pour mes propres produits, rien de comparable n'existait.

J'ai construit DevEye pour combler ce vide, comme un outil transversal plutôt qu'un script jetable pour un seul projet : il sert déjà d'outillage à mes autres produits, et sa cible n'est pas une seule technologie d'UI mais toutes celles que j'utilise ou pourrais utiliser.

Le problème technique qui en découle est de faire tenir sous une seule abstraction des façons de piloter une interface qui n'ont presque rien en commun : le protocole DevTools d'un navigateur Chromium, un pont HTTP maison embarqué dans l'app cible, la capture d'écran D-Bus d'un compositeur Wayland, et, quand il n'y a ni navigateur ni SDK embarqué, l'injection d'entrées au niveau du système lui-même — sur un écosystème, Wayland, conçu justement pour empêcher ce genre d'injection.

## Les décisions techniques

**Le cœur ne connaît aucune technologie de pilotage, seulement des ports.** `deveye-core` est un domaine pur (`Snapshot`, `InteractionCommand`, `LogEvent`) sans la moindre dépendance technique, et `deveye-ports` définit douze traits Rust (`Renderer`, `AppInspector`, `InteractionSink`, `JsEvaluator`, `LogSource`, `CommandRouter`, `WaitCondition`, `WindowManager`, `Clipboard`…) que dix adapters implémentent chacun pour une technologie différente. Ajouter une nouvelle cible consiste à écrire un adapter de plus, jamais à toucher au cœur ni aux quarante-et-un outils MCP qui s'appuient sur les ports : l'architecture hexagonale, que j'ai éprouvée sur un cas réel, pas seulement posée en principe.

**Quatre modes de ciblage choisissent la technologie, jamais l'agent.** Le mode `cdp` parle au Chrome DevTools Protocol pour toute WebView Chromium, `sdk` passe par un pont HTTP maison qu'une app embarque en une ligne, `kwin-eis` injecte des entrées au niveau du système pour les apps sans WebView ni SDK (les « yeux » passent alors par la capture d'écran), et `auto` essaie `cdp` puis bascule appel par appel sur `sdk` dès que le premier échoue. Je ne voulais pas que l'agent qui pilote DevEye ait à savoir quelle technologie répond de l'autre côté.

**L'injection d'entrées sous Wayland passe par le portail officiel KWin EIS, en Rust pur, sans FFI.** Wayland est conçu pour empêcher qu'une application injecte des entrées dans une autre : pas de XWayland à contourner, pas d'`uinput` à ouvrir directement. Le seul chemin légitime est le portail `org.kde.KWin.EIS.RemoteDesktop`, implémenté avec la crate `reis`. Il est négocié par une connexion D-Bus qui doit rester ouverte sous peine d'un simple EOF au moment du handshake — un piège de protocole que j'ai découvert en m'y heurtant. Côté clavier, chaque keysym Unicode doit être résolu en keycode evdev via `xkbcommon` avant de pouvoir être injecté. C'est la partie la plus pointue du projet.

**La posture « outil de dev » est défendue en profondeur, pas seulement déclarée dans un README.** `deveye_sdk::init()` ne compile aucun code DevEye hors de `debug_assertions`, même si l'intégrateur oublie son `#[cfg]` ; le serveur ne se lie qu'en loopback (`127.0.0.1`, jamais `0.0.0.0`) et rejette en 403, avant toute route, tout en-tête `Host` qui ne serait pas local ; le CORS est en deny-all et les crates exposés portent `#[forbid(unsafe_code)]`. La file d'évaluation JS est bornée et répond 503 plutôt que de laisser la mémoire grossir si l'app cible est figée, et le buffer de logs est plafonné en écrasant les plus anciens. Chaque mécanisme répond à une façon précise de fuir en production, pas à un risque générique.

**L'outil s'audite avec la rigueur qu'il applique au code des autres.** Un audit interne formel que j'ai mené après le jalon M7 a produit cent huit constats priorisés, dont des gates bloquants, suivi d'un second audit en juillet. La CI, sur mon propre runner GitLab auto-hébergé, fait tourner `cargo fmt --check`, `clippy` en `-D warnings` et les tests du workspace, plus `cargo-deny` pour l'audit des dépendances et des licences ; les tests qui ont besoin d'une vraie session Wayland sont isolés en `#[ignore]`.

## Ce que j'en ai appris

Que faire tenir quatre technologies de pilotage sous une seule abstraction oblige à nommer précisément ce qui leur est commun — un rendu, une entrée, une attente — plutôt que de les empiler derrière des cas particuliers. Que les primitives d'attente sont le vrai cœur de la fiabilité d'une boucle d'agent : un agent IA enchaîne les actions bien plus vite qu'un humain, donc j'ai dû sérialiser les appels MCP et attendre une condition explicite plutôt qu'un délai fixe pour que la boucle tienne. Et que concevoir pour un agent comme utilisateur final est une discipline à part : les quarante-et-un outils ont des contrats précis et des retours structurés que j'ai pensés pour être compris et enchaînés par un LLM, pas lus par un humain — une forme d'UX dont l'utilisateur n'en est pas un.

## En chiffres

- 15 crates, 71 fichiers, environ 13 500 lignes de code
- 41 outils MCP répartis en six familles, sur 12 ports et 10 adapters
- 4 canaux de pilotage : CDP, pont SDK embarqué, capture KWin, injection KWin EIS
- 65 commits entre juin et juillet 2026, jalons M0 à M7 livrés le 25 juin — chiffre de cette phase, le développement continue depuis par sprints d'extension d'outils
- 108 constats relevés lors de l'audit interne qui a suivi le jalon M7, un second audit mené en juillet
- 3 documents de référence (usage, architecture, wire protocol du SDK)

## Liens

- Dépôt : GitLab personnel auto-hébergé (Kovelt Utilities), privé. Une publication en open source est prévue à la version 1.0.

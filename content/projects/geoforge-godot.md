---
title: "GeoForge, l'addon Godot"
slug: "geoforge-godot"
type: "project"
date: 2026-10-26
subtitle: "Un addon Godot qui calcule le relief, les rivières et les routes à partir de lois physiques réelles, ajustables point par point"
seo_description: "GeoForge, addon open source pour Godot 4 (C#/.NET), calcule relief, rivières et routes à partir de lois physiques et de normes d'ingénierie réelles."
featured: false
status: "prototype"
role: "Conception, développement, tests et documentation, en solo"
period_start: 2026-03-19
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [godot, csharp, dotnet, clean-architecture, solid, design-patterns, generation-procedurale, openstreetmap, xunit, open-source]
skills: []
---

GeoForge est un addon open source que je développe pour Godot 4, qui génère des terrains à partir de données géographiques réelles ou d'une sculpture manuelle dans l'éditeur. Je précise le nom tout de suite : un autre de mes projets, mon fil rouge de deuxième année, s'appelle lui aussi GeoForge (un puzzle géométrique en React et Phaser). Les deux ne partagent que le nom ; celui dont il est question ici est l'addon Godot, en C#/.NET, personnel, sous licence MIT.

Au 26 octobre 2026, le projet est en pré-alpha : une quinzaine de modules sont définis, la refonte du système d'eau est en cours, et le dépôt GitHub existe mais est encore vide.

## Le problème

Construire un monde ouvert crédible dans un moteur de jeu prend normalement des dizaines d'heures de travail manuel : modeler le relief, tracer chaque rivière, dessiner chaque route, planter chaque forêt. Unity a des outils de terrain matures (Gaia Pro, R.A.M), Unreal un système d'eau natif. Godot, moteur open source en forte croissance, n'a pas d'équivalent complet, en particulier côté .NET/C#.

GeoForge attaque ce manque par deux entrées qui doivent converger vers la même représentation du terrain : un pipeline qui télécharge les données géographiques d'une région réelle (OpenStreetMap pour les routes, cours d'eau, forêts et bâtiments, un modèle d'élévation SRTM pour le relief) et les transforme en terrain jouable, et une suite d'outils d'édition intégrés à l'éditeur Godot — sculpture du relief, traçage de rivières et de routes, peinture de biomes. Il faut pouvoir générer une région depuis OSM puis la raffiner à la main, ou l'inverse, sans que l'un des deux chemins ne soit un citoyen de seconde zone.

Le vrai problème, derrière ces deux entrées, est celui du réglage des paramètres. Un outil qui demande à l'utilisateur de fixer à la main la largeur d'une rivière ou la pente d'un talus produit un résultat laborieux et rarement crédible, parce que le terrain réel obéit à des lois qu'il ne connaît pas forcément. Comment obtenir un résultat crédible par défaut sans priver l'utilisateur du contrôle quand il en a besoin ?

## Les décisions techniques

**Chaque paramètre vient d'une loi physique ou d'une norme d'ingénierie, jamais d'un choix arbitraire.** La largeur et la profondeur d'un cours d'eau suivent la géométrie hydraulique de Leopold-Maddock, sa vitesse la formule de Manning-Strickler, sa sinuosité la classification de Schumm ; les pentes respectent l'angle de repos des matériaux, le dimensionnement d'une route s'appuie sur la portance des sols (CBR) et les référentiels du Cerema, du SETRA et de l'IDRRIM, l'état d'une chaussée sur la norme ASTM D-6433. Ces valeurs ne sont jamais réglées à la main : elles sont dérivées. La contrepartie : chaque point de contrôle expose des champs nullable, pour que l'utilisateur reprenne la main exactement là où il le veut, et nulle part ailleurs.

**Les générateurs ne se connaissent pas : ils ne communiquent que par les données qu'ils partagent.** Une quinzaine de modules (GeoCore pour les fondations, puis GeoMountain, GeoWater, GeoWay, GeoForest, GeoCity, GeoOSM...) lisent et écrivent une heightmap et des couches de métadonnées gérées par GeoCore, sans jamais se référencer directement. La génération s'exécute en pipeline ordonné par priorité : le sol, les montagnes, les cratères, l'eau, les routes, la végétation, les villes. Un contributeur ajoute un générateur sans toucher à l'existant — principe ouvert/fermé. Il s'incarne dans six design patterns nommés : Strategy pour la source de données (`ITerrainDataProvider`), Factory pour l'enregistrement des générateurs, Observer pour le chargement des chunks, Command pour l'undo/redo, Pipeline pour l'ordonnancement, Composite pour GeoWater et GeoWay.

**Tous les éditeurs interactifs partagent le même squelette.** Qu'il sculpte le relief ou trace une route, un éditeur repose sur la même ossature : un enum de type et sa configuration statique, des points de contrôle à overrides nullable, des analyses de segment calculées, des Resources Godot pour la définition et le réseau, un contrôleur à machine à états, un service de build en pipeline, un panneau d'interface qui communique par signaux. Comprendre un éditeur, c'est comprendre tous les autres : la charge cognitive n'augmente pas avec le nombre d'outils d'édition.

**Diagnostiquer avant de réécrire : le cas de l'éditeur de rivières.** La première version traçait une rivière par spline : l'utilisateur dessinait le chemin et réglait l'altitude de chaque point à la main. Le défaut était en amont du code : un outil de dessin appliqué à un phénomène physique, alors que l'eau ne se dessine pas, elle coule. La refonte adopte un paradigme de source d'écoulement — l'utilisateur pose une source, le système trace la descente par gradient sur le relief, creuse la heightmap et construit le mesh d'eau à partir du résultat. Avant de réécrire, j'ai vérifié l'existant : le composant hérité `RiverGenerator` contenait déjà le bon algorithme de descente de gradient, la refonte le conserve et ne remplace que la façade d'édition. Le document de refonte fait environ cinq cent cinquante lignes : décomposition en composants, pseudocode, cas limites, phasage en trois étapes.

**Zéro warning toléré, documentation obligatoire par construction.** Le projet compile avec `TreatWarningsAsErrors` : un simple avertissement casse le build, ce qui rend de fait obligatoire la documentation XML de toute API publique, puisqu'une méthode non documentée en génère un. Une classe par fichier, un `.editorconfig` partagé. L'intégration continue (GitHub Actions) couvre builds multi-OS, tests avec couverture, formatage C#, lint Markdown, audit de sécurité des dépendances et pipeline de release. Même discipline côté Git : branches `main` → `develop` → feature, commits au format Conventional Commits (par exemple `feat(river): add flow simulation`), pull requests et squash merge systématiques, versionnage sémantique. Un projet personnel n'a pas de relecteur attitré ; c'est l'outillage qui en tient le rôle.

**La conception précède le code.** Plus de vingt documents d'architecture ont été écrits avant la moindre classe, chacun selon un même gabarit : vue d'ensemble, workflow utilisateur, fondement physique, modèle de données C#, maquette d'interface, phasage V1/V2/V3, limites connues. Ce gabarit rend les décisions traçables, documente la montée en compétence et transforme l'implémentation en exécution guidée plutôt qu'en improvisation.

## Ce que j'en ai appris

Le chantier de l'éditeur de rivières m'a donné deux leçons que je réutilise ailleurs dans le projet. D'abord, le modèle mental d'un outil doit épouser la physique du phénomène qu'il manipule : un outil de dessin appliqué à un écoulement produit de la friction et un résultat peu crédible, quelle que soit la qualité de l'interface. Ensuite, auditer avant de réécrire : le composant hérité contenait déjà le bon algorithme, je n'ai remplacé que ce qui méritait de l'être.

J'ai aussi appris, en traduisant des publications scientifiques et des normes d'ingénierie en code de production, qu'il y a une différence entre comprendre une formule et savoir laquelle simplifier sans la trahir. C'est l'exercice le plus formateur du projet, plus que l'écriture du code elle-même.

## En chiffres

- une quinzaine de modules indépendants (GeoCore, GeoWater, GeoWay, GeoOSM...)
- plus de 20 documents d'architecture écrits avant le code
- 6 design patterns nommés et justifiés (Strategy, Factory, Observer, Command, Pipeline, Composite)
- 1 document de refonte d'environ 550 lignes pour le seul éditeur de rivières
- pipeline d'import OpenStreetMap en 3 étages (fetcher, parser, mapper)
- feuille de route en 7 jalons
- moteur Godot 4.3+, C#/.NET 8, licence MIT

## Liens

- Dépôt : le dépôt GitHub `morgan-voltz/GeoForge` existe mais est vide à ce jour (0 Ko, seule la description du projet est renseignée) ; la publication du code est à venir.
- Démo : aucune pour l'instant, le projet est en pré-alpha.
- Licence : MIT.

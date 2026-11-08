---
title: "GeoForge, das Godot-Addon"
slug: "geoforge-godot-de"
type: "project"
lang: "de"
translation_key: "geoforge-godot"
date: 2026-10-26
subtitle: "Ein Godot-Addon, das Relief, Flüsse und Strassen aus realen physikalischen Gesetzen berechnet, Punkt für Punkt anpassbar"
seo_description: "GeoForge ist ein Open-Source-Addon für Godot 4 (C#/.NET), das Relief, Flüsse und Strassen aus realen physikalischen Gesetzen und Ingenieurnormen berechnet."
featured: false
status: "prototype"
role: "Konzeption, Entwicklung, Tests und Dokumentation, allein"
period_start: 2026-03-19
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [godot, csharp, dotnet, clean-architecture, solid, design-patterns, generation-procedurale, openstreetmap, xunit, open-source]
skills: []
---

GeoForge ist ein Open-Source-Addon, das ich für Godot 4 entwickle und das Terrains aus realen geografischen Daten oder aus manueller Modellierung im Editor erzeugt. Ich stelle den Namen gleich klar: Ein anderes meiner Projekte, mein Leitprojekt (roter Faden) im zweiten Jahr, heisst ebenfalls GeoForge (ein geometrisches Puzzle in React und Phaser). Die beiden teilen nur den Namen; hier geht es um das Godot-Addon in C#/.NET, ein persönliches Projekt unter MIT-Lizenz.

Stand 26. Oktober 2026 befindet sich das Projekt in der Pre-Alpha-Phase: Rund fünfzehn Module sind definiert, der Umbau des Wassersystems ist im Gang, und das GitHub-Repository existiert, ist aber noch leer.

## Das Problem

Eine glaubwürdige offene Welt in einer Spiel-Engine zu bauen, kostet normalerweise Dutzende Stunden Handarbeit: das Relief modellieren, jeden Fluss ziehen, jede Strasse zeichnen, jeden Wald pflanzen. Unity hat ausgereifte Terrain-Werkzeuge (Gaia Pro, R.A.M), Unreal ein natives Wassersystem. Godot, eine rasch wachsende Open-Source-Engine, hat kein vollständiges Gegenstück, insbesondere nicht auf der .NET/C#-Seite.

GeoForge geht diese Lücke über zwei Eingänge an, die in derselben Darstellung des Terrains zusammenlaufen müssen: eine Pipeline, die die geografischen Daten einer realen Region herunterlädt (OpenStreetMap für Strassen, Gewässer, Wälder und Gebäude, ein SRTM-Höhenmodell für das Relief) und in ein bespielbares Terrain verwandelt, und eine in den Godot-Editor integrierte Suite von Bearbeitungswerkzeugen: Relief modellieren, Flüsse und Strassen ziehen, Biome malen. Man muss eine Region aus OSM erzeugen und dann von Hand verfeinern können, oder umgekehrt, ohne dass einer der beiden Wege ein Bürger zweiter Klasse ist.

Das eigentliche Problem hinter diesen beiden Eingängen ist das der Parametereinstellung. Ein Werkzeug, das von den Nutzern verlangt, die Breite eines Flusses oder das Gefälle einer Böschung von Hand festzulegen, liefert ein mühsames und selten glaubwürdiges Ergebnis, weil das reale Terrain Gesetzen gehorcht, die sie nicht unbedingt kennen. Wie erzielt man standardmässig ein glaubwürdiges Ergebnis, ohne den Nutzern die Kontrolle zu nehmen, wenn sie sie brauchen?

## Die technischen Entscheidungen

**Jeder Parameter stammt aus einem physikalischen Gesetz oder einer Ingenieurnorm, nie aus einer willkürlichen Wahl.** Breite und Tiefe eines Gewässers folgen der hydraulischen Geometrie von Leopold und Maddock, seine Geschwindigkeit der Formel von Manning-Strickler, seine Sinuosität der Klassifikation von Schumm; die Böschungen respektieren den Böschungswinkel der Materialien, die Dimensionierung einer Strasse stützt sich auf die Tragfähigkeit der Böden (CBR) und auf die Regelwerke von Cerema, SETRA und IDRRIM, der Zustand einer Fahrbahn auf die Norm ASTM D-6433. Diese Werte werden nie von Hand eingestellt: Sie werden abgeleitet. Die Kehrseite: Jeder Kontrollpunkt stellt Nullable-Felder bereit, damit die Nutzer genau dort wieder die Hand am Steuer haben, wo sie es wollen, und nirgendwo sonst.

**Die Generatoren kennen einander nicht: Sie kommunizieren nur über die Daten, die sie teilen.** Rund fünfzehn Module (GeoCore für die Grundlagen, dann GeoMountain, GeoWater, GeoWay, GeoForest, GeoCity, GeoOSM ...) lesen und schreiben eine Heightmap und Metadatenschichten, die von GeoCore verwaltet werden, ohne sich je direkt zu referenzieren. Die Generierung läuft als nach Priorität geordnete Pipeline: der Boden, die Berge, die Krater, das Wasser, die Strassen, die Vegetation, die Städte. Wer beiträgt, fügt einen Generator hinzu, ohne das Bestehende anzufassen: das Open/Closed-Prinzip. Es verkörpert sich in sechs benannten Design Patterns: Strategy für die Datenquelle (`ITerrainDataProvider`), Factory für die Registrierung der Generatoren, Observer für das Laden der Chunks, Command für Undo/Redo, Pipeline für die Reihenfolge, Composite für GeoWater und GeoWay.

**Alle interaktiven Editoren teilen dasselbe Grundgerüst.** Ob er das Relief modelliert oder eine Strasse zieht, ein Editor beruht auf demselben Gerüst: ein Typ-Enum und seine statische Konfiguration, Kontrollpunkte mit Nullable-Overrides, berechnete Segmentanalysen, Godot-Resources für die Definition und das Netz, ein Controller mit Zustandsautomat, ein Build-Dienst als Pipeline, ein Oberflächenpanel, das über Signale kommuniziert. Einen Editor zu verstehen heisst, alle anderen zu verstehen: Die kognitive Last wächst nicht mit der Zahl der Bearbeitungswerkzeuge.

**Erst diagnostizieren, dann neu schreiben: der Fall des Fluss-Editors.** Die erste Version zeichnete einen Fluss als Spline: Die Nutzer zeichneten den Verlauf und stellten die Höhe jedes Punktes von Hand ein. Der Fehler lag vor dem Code: ein Zeichenwerkzeug, angewandt auf ein physikalisches Phänomen, obwohl Wasser nicht gezeichnet wird, es fliesst. Der Umbau setzt auf das Paradigma der Abflussquelle: Die Nutzer setzen eine Quelle, das System verfolgt den Abstieg per Gradient über das Relief, gräbt das Flussbett in die Heightmap und baut das Wasser-Mesh aus dem Ergebnis. Bevor ich neu geschrieben habe, habe ich den Bestand geprüft: Die geerbte Komponente `RiverGenerator` enthielt bereits den richtigen Algorithmus für den Gradientenabstieg, der Umbau behält ihn bei und ersetzt nur die Bearbeitungsfassade. Das Umbaudokument umfasst etwa fünfhundertfünfzig Zeilen: Zerlegung in Komponenten, Pseudocode, Grenzfälle, Phasenplan in drei Etappen.

**Keine Warnung wird toleriert, Dokumentation ist von der Konstruktion her Pflicht.** Das Projekt wird mit `TreatWarningsAsErrors` kompiliert: Schon eine einzige Warnung bricht den Build, was die XML-Dokumentation jeder öffentlichen API faktisch zur Pflicht macht, da eine undokumentierte Methode eine Warnung erzeugt. Eine Klasse pro Datei, eine gemeinsame `.editorconfig`. Die kontinuierliche Integration (GitHub Actions) deckt Multi-OS-Builds, Tests mit Abdeckung, C#-Formatierung, Markdown-Lint, Sicherheitsaudit der Abhängigkeiten und eine Release-Pipeline ab. Dieselbe Disziplin gilt für Git: Branches `main` → `develop` → Feature, Commits im Format Conventional Commits (zum Beispiel `feat(river): add flow simulation`), durchgängig Pull Requests und Squash-Merge, semantische Versionierung. Ein persönliches Projekt hat keinen festen Reviewer; es ist das Tooling, das diese Rolle übernimmt.

**Die Konzeption geht dem Code voraus.** Über zwanzig Architekturdokumente wurden geschrieben, bevor es auch nur eine Klasse gab, jedes nach derselben Vorlage: Überblick, Nutzer-Workflow, physikalische Grundlage, C#-Datenmodell, Oberflächenentwurf, Phasenplan V1/V2/V3, bekannte Grenzen. Diese Vorlage macht die Entscheidungen nachvollziehbar, dokumentiert den Kompetenzaufbau und macht aus der Umsetzung eine geführte Ausführung statt Improvisation.

## Was ich daraus gelernt habe

Die Arbeit am Fluss-Editor hat mir zwei Lektionen erteilt, die ich an anderer Stelle im Projekt wiederverwende. Erstens: Das mentale Modell eines Werkzeugs muss zur Physik des Phänomens passen, das es bearbeitet: Ein Zeichenwerkzeug, angewandt auf eine Strömung, erzeugt Reibung und ein kaum glaubwürdiges Ergebnis, ganz gleich, wie gut die Oberfläche ist. Zweitens: prüfen, bevor man neu schreibt: Die geerbte Komponente enthielt bereits den richtigen Algorithmus, ich habe nur das ausgetauscht, was den Austausch verdiente.

Ausserdem habe ich beim Übersetzen wissenschaftlicher Publikationen und Ingenieurnormen in Produktionscode gelernt, dass es einen Unterschied gibt zwischen dem Verstehen einer Formel und dem Wissen, welche man vereinfachen kann, ohne sie zu verraten. Das ist die lehrreichste Übung des Projekts, mehr als das Schreiben des Codes selbst.

## Zahlen

- rund fünfzehn unabhängige Module (GeoCore, GeoWater, GeoWay, GeoOSM ...)
- über 20 Architekturdokumente, vor dem Code geschrieben
- 6 benannte und begründete Design Patterns (Strategy, Factory, Observer, Command, Pipeline, Composite)
- 1 Umbaudokument von etwa 550 Zeilen allein für den Fluss-Editor
- OpenStreetMap-Importpipeline in 3 Stufen (Fetcher, Parser, Mapper)
- Roadmap mit 7 Meilensteinen
- Godot-Engine 4.3+, C#/.NET 8, MIT-Lizenz

## Links

- Repository: Das GitHub-Repository `morgan-voltz/GeoForge` existiert, ist aber bis heute leer (0 KB, nur die Projektbeschreibung ist ausgefüllt); die Veröffentlichung des Codes steht noch aus.
- Demo: derzeit keine, das Projekt befindet sich in der Pre-Alpha-Phase.
- Lizenz: MIT.

---
title: "ancestors-modding"
slug: "ancestors-modding-de"
type: "project"
lang: "de"
translation_key: "ancestors-modding"
date: 2026-10-26
subtitle: "Eine Reverse-Engineering-Toolbox, um ein Unreal-Engine-Spiel zu modden, das sein Studio ohne SDK und ohne Dokumentation aufgegeben hat"
seo_description: "Reverse Engineering eines vom Studio aufgegebenen Unreal-Engine-Spiels: Rekonstruktion seiner internen Architektur und erste veröffentlichte Modding-Tools."
featured: false
status: "prototype"
role: "Reverse Engineering, Entwicklung der beiden Tools und Dokumentation, allein"
period_start: 2026-03-23
period_end: 2026-03-23
repo_url: "https://github.com/morgan-voltz/ancestors-modding"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [python, tkinter, reverse-engineering, unreal-engine, modding, binary-patching, debug-symbols, open-source]
skills: []
---

ancestors-modding ist ein persönliches Projekt unter MIT-Lizenz: eine Toolbox für Reverse Engineering und Modding von *Ancestors: The Humankind Odyssey*, einem Spiel auf Unreal Engine 4.20, dessen Entwicklung das Studio 2020 eingestellt hat.

Ich habe es in einer einzigen Sitzung gebaut und veröffentlicht, am 23. März 2026 (vier Commits in 38 Minuten), und bin seither nicht mehr darauf zurückgekommen. Das Repository enthält keinerlei Assets des Spiels, nur Tools und Dokumentation: elf Markdown-Dokumente und zwei Python-Skripte.

## Das Problem

*Ancestors: The Humankind Odyssey* simuliert die Evolution des Menschen über mehrere Millionen Jahre. Sein Studio, Panache Digital Games, hat die Entwicklung 2020 eingestellt, ohne je ein SDK oder Modding-Werkzeuge zu veröffentlichen. Spieler, die irgendetwas am Spiel ändern wollten, etwa den Schwierigkeitsgrad, das Verhalten eines Tieres oder einen Evolutionsparameter, hatten nichts in der Hand: kein dokumentiertes Format, keinen Editor, nicht einmal ein funktionierendes Beispiel.

Technisch legt das Spiel alle seine Daten in proprietären `.pak`-Dateien von Unreal Engine ab: nicht verschlüsselt, aber ebenso wenig dokumentiert. Ein Spielwert, zum Beispiel die maximale Gesundheit eines Tieres, steckt in einer `.uasset`-Datei, deren internes Format (kompilierte Blueprint-Klassen, serialisierte Eigenschaften) keine öffentliche Spezifikation hat.

Mir ging es nicht darum, einen vollständigen Parser für dieses Format zu schreiben, was ein Projekt für sich wäre, sondern um eine engere Frage: Wie weit kommt man ohne ihn? Einen bestimmten Wert in einer unbekannten Binärdatei finden, ihn ändern, ohne die Datei zu beschädigen, und die Methode so dokumentieren, dass sie für den Nächsten erhalten bleibt, der das Spiel modden will.

## Die technischen Entscheidungen

**Ich habe eine Debug-Symboldatei ausgewertet, statt die interne Struktur blind zu erraten.** Ein ausgeliefertes kommerzielles Spiel enthält fast nie seine Debug-Symbole; dieses tut es, mit einer PDB-Datei von 625 MB. Damit habe ich die Klassenpräfixe der Engine (`CDS`, `CDL`, `CDD`, `CS`, `GC`, `GCS`, `HE`...) abgelesen, statt sie zu erraten, und die Architektur beziffert: 508 kompilierte Blueprint-Quelldateien (`.gen.cpp`) identifiziert, die Klasse `ClanMember` 40 570-mal in der Binärdatei referenziert, `Settlement` 9 599-mal, die Funktionen `Spawn` und `Die` 4 366- beziehungsweise 1 023-mal aufgerufen. Diese Zählung, festgehalten in meinem Forschungsjournal, gab mir eine Karte des Systems, bevor ich auch nur eine Zeile Patch schrieb.

**Ich habe das Byte direkt gepatcht, statt einen vollständigen Parser für das `.uasset`-Format zu schreiben.** Das Asset-Format von Unreal Engine ist proprietär und nicht dokumentiert; einen generischen Parser zu bauen, nur um ein paar Gameplay-Zahlen zu ändern, wäre unverhältnismässig gewesen. Für einen Wert, der allein in einer kompilierten Blueprint-Klasse steht, eine `FloatProperty`, genügt es, ihn an einem festen Offset zu lokalisieren und mit `struct.pack_into` zu überschreiben, wobei die Dateigrösse strikt identisch bleibt. Das `HealthMax` des Leoparden ist zum Beispiel ein Float am Offset 1140 in `VL01_CDSHealth_Leopard.uasset`; es von 9.0 auf 1.0 zu setzen und dann mit dem externen Tool `repak` (Version 0.2.3) in ein Override-`_P.pak` neu zu verpacken, war mein erster funktionierender Mod, von Anfang bis Ende dokumentiert. Die Grenze der Methode habe ich noch am selben Tag festgehalten, an dem ich sie gefunden habe: Sie beschädigt Texturen (die Header zwischen den Mipmaps) und `DataTable`s (die Serialisierung der `FName`s), für die man ein Tool bräuchte, das das Format wirklich versteht, wie UAssetAPI, das ich nicht geschrieben habe.

**Ich habe das grafische Tool für einen Spieler entworfen, nicht für mich: automatische Erkennung und risikolose Deinstallation.** `find_game_dir()` prüft zuerst, ob das Skript aus dem Spielordner selbst gestartet wird, dann fünf gängige Steam-Speicherorte, dann die Windows-Registry (`winreg`), bevor es auf eine manuelle Auswahl zurückfällt. Und weil Unreal jede Datei der Form `*_P.pak` lädt, die neben den Paks des Spiels liegt, um die Dateien gleichen Namens zu ersetzen, ohne je das Original anzufassen, besteht die Deinstallation einfach darin, sie zu löschen: Die Funktion `uninstall_mod()` umfasst sieben Zeilen, es gibt nichts weiter rückgängig zu machen.

**Ich habe die Recherche vor den Tools dokumentiert, mit mehr Umfang als der Code selbst.** Ohne SDK und ohne Parser, auf die man verweisen könnte, fehlten der Community nicht zwei Skripte, sondern das Wissen, wo die Werte liegen und wie weit man sie anfassen kann. Ich habe 2 856 Zeilen Markdown-Dokumentation geschrieben gegen 766 Zeilen Python, also etwa 3,7-mal so viel, darunter ein datiertes Journal, das ausdrücklich trennt, was funktioniert und was nicht, und eine Kartierung des Haupt-Paks des Spiels (18 105 Dateien, 3,75 GB). Das Spiel hat ein zweites Pak, `VL01E01.pak` (2,98 GB, 14 610 Dateien, davon 14 608 bereits im ersten enthalten); ich habe es nicht separat kartiert, weil es zu über 99 % eine Dublette ist.

## Was ich daraus gelernt habe

Dass das Lesen der Debug-Symbole und der Aufrufzähler einer unbekannten Binärdatei mehr und schneller lehrt als das Raten allein anhand des Dateiinhalts. Dass sich die Sorgfalt einer Methode daran bemisst, wie klar man ihre Grenzen an dem Tag festhält, an dem man sie findet, und nicht sechs Monate später aus dem Gedächtnis: Das hat mir erspart, den direkten Binär-Patch für Texturen oder `DataTable`s zu empfehlen, die er beschädigt. Und dass das Öffnen eines Projekts für Mitwirkende in einem README (3D-Künstler, Forscher, Tool-Entwickler) keine anlockt: Sieben Monate nach der Veröffentlichung zeigt die Historie des Repositorys immer noch nur einen einzigen Autor.

Was ich anders machen würde: Meine beiden Tools schützen den Patch nicht auf dieselbe Weise, und ich habe es erst beim erneuten Lesen des Codes für diese Projektbeschreibung bemerkt. `patch_health.py` vergleicht nur die Grösse der extrahierten Datei, als Warnung, nicht als Sperre; `AncestorsDifficultyMod.py` vergleicht nur den alten Wert, mit einer Toleranz von 0.01, und löst bei Überschreitung einen blockierenden Fehler aus. Ich würde beide Prüfungen in jedes Tool einbauen, statt nur eine pro Skript zu behalten. Ich würde auch `docs/VISION_AND_LORE.md` wieder an die Navigation anbinden: Es liegt im Dateisystem, erscheint aber weder in der Tabelle des READMEs noch in der Verzeichnisstruktur von `CONTRIBUTING.md`, was es für jeden unsichtbar macht, der der Dokumentation folgt, wie sie angekündigt ist.

## Zahlen

- 766 Zeilen Python: 174 für das CLI (`patch_health.py`), 592 für die GUI (`AncestorsDifficultyMod.py`), 0 Tests, keine CI
- 2 856 Zeilen Markdown-Dokumentation (11 Dateien in `docs/` + README mit 223 Zeilen + CONTRIBUTING mit 103 Zeilen), etwa das 3,7-Fache des Codevolumens
- 4 Commits, ein einziger Autor, in 38 Minuten am 23. März 2026 (02:03–02:41 Uhr); 0 Git-Tags
- 1 PDB-Datei von 625 MB analysiert, 508 kompilierte Blueprint-Quelldateien (`.gen.cpp`) identifiziert
- 18 105 Dateien im Haupt-Pak des Spiels (3,75 GB), 14 610 in einem zweiten Pak (2,98 GB)
- 14 Tierarten kartiert (Gesundheit von 3,0 bis 20,0), 17 Evolutionszweige, ein gemeinsames Skelett mit 77 Knochen
- Extrahierte Assets: 55 animierte Meshes, 1 082 statische Meshes, 3 079 Animationen, 5 848 Texturen, 1 730 Materialien

## Links

- Repository: [github.com/morgan-voltz/ancestors-modding](https://github.com/morgan-voltz/ancestors-modding), öffentlich, MIT-Lizenz
- Keine Demo: Ein Modding-Tool richtet sich nur an Besitzer des Spiels
- Vollständige Dokumentation (Einstiegsanleitung, Kartierung der Assets, Forschungsjournal) im Repository unter `docs/`

---
title: "GeoForge, das Spiel"
slug: "geoforge-jeu-de"
type: "project"
lang: "de"
translation_key: "geoforge-jeu"
date: 2026-10-26
subtitle: "Ein geometrisches Puzzle auf einem Raster, bei dem allein der Server entscheidet, ob eine Platzierung gültig ist"
seo_description: "GeoForge ist ein Geometrie-Puzzle mit .NET-10-API in Clean Architecture und React-19/Phaser-3-Frontend, mein allein verteidigtes Leitprojekt UHA 4.0.2."
featured: false
status: "archive"
role: "Konzeption, Entwicklung, Tests, Containerisierung und Verteidigung, allein"
period_start: 2026-02-01
period_end: 2026-04-01
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [csharp, dotnet, clean-architecture, react, typescript, phaser, postgresql, podman, jwt, xunit]
skills: ["4.0.2/E00", "4.0.2/E01", "4.0.2/E02", "4.0.2/E03", "4.0.2/E04", "4.0.2/E06", "4.0.2/E07", "4.0.2/E08", "4.0.2/E11", "4.0.2/E12", "4.0.2/O02", "4.0.2/O03"]
---

GeoForge ist ein Web-Spiel in Form eines geometrischen Puzzles; es ist nicht zu verwechseln mit «GeoForge, das Godot-Addon», einem Modul zur Erzeugung von Terrain, das ich separat entwickelt habe und das mit diesem Projekt nur den Namen teilt. Dieses hier war mein Leitprojekt (roter Faden) im zweiten Jahr des Studiengangs UHA 4.0.2 im Studienjahr 2025–2026, von mir allein zwischen Februar und April 2026 geführt und vor der Jury verteidigt. Für 2026–2027 bin ich erneut für 4.0.2 eingeschrieben, diesmal mit MomoCad als offiziellem Leitprojekt.

## Das Problem

Die Aufgabenstellung des 4.0.2 verlangt eine objektorientierte Anwendung, die geometrische Formen verwaltet: sie erstellen, zusammensetzen und ihre Eigenschaften berechnen. Einer der vorgeschlagenen Wege ist ein Spiel nach Art eines Tangrams, das über eine Website bereitgestellt wird; diesen Weg habe ich wörtlich genommen und ein Arcade-Puzzle gegen die Uhr gebaut, in dem ein Zielmodell aus mehreren Formen in begrenzter Zeit auf einem Raster nachgebaut werden muss, mit einem Übereinstimmungswert als Punktzahl.

Weil das Spiel kompetitiv ist, mit Rangliste, Zeitlimit und Sternen, geht das technische Problem über die Geometrie selbst hinaus. Wenn der Client allein entscheidet, dass eine Platzierung gültig ist, oder die Punktzahl, die er an den Server übermittelt, selbst berechnet, hindert nichts einen Spieler daran, seine Anfrage zu manipulieren und eine unmögliche Punktzahl zu erzielen. Es brauchte also eine strikt eingehaltene Regel: Der Client zeigt eine Vorschau und führt den Spieler, aber allein die API entscheidet, und sie entscheidet auf der Grundlage eines Zustands, den sie vollständig kontrolliert, nie auf der Grundlage dessen, was der Client erreicht zu haben behauptet.

Über diesem Kern sollte das Projekt auch als kleine Plattform funktionieren: Die Level stammen nicht nur von mir, die Spieler können eigene erstellen, und jemand muss sie prüfen, bevor sie veröffentlicht werden. Dieses zweite Problem, eher redaktionell als algorithmisch, verlangte einen klaren Prüfprozess statt eines Knopfs «Veröffentlichen» ohne Filter.

## Die technischen Entscheidungen

**Der Client entscheidet weder über die Gültigkeit einer Platzierung noch über die Punktzahl: Beides wird serverseitig nach einer reproduzierbaren Methode berechnet.** Würde der Client in einem Spiel mit Zeitmessung und Rangliste über die Gültigkeit urteilen oder die Punktzahl berechnen, liesse man den Spieler sich selbst benoten, und ich wollte ein reproduzierbares, erklärbares Ergebnis statt einer Ad-hoc-Bewertung. `IsPlacementValid` berechnet die Rasterzellen, die eine Form belegt, aus einer lokalen Maske, die gemäss ihrer Rotation gedreht wird, und prüft, ob die Form über den Rasterrand hinausragt oder mit anderen kollidiert; `AddShape()` und `MoveShape()` machen jede Operation, die diese Prüfung nicht besteht, automatisch rückgängig (Rollback). Die Punktzahl stammt aus einem Prozentsatz der Übereinstimmung, der durch einen Zelle-für-Zelle-Vergleich zwischen der Konstruktion des Spielers und dem Zielmodell berechnet wird (in beiden belegt, vom richtigen Formtyp, am richtigen Ort), statt aus einer von Hand berechneten Formel; zusammen mit einem Zeitbonus speist dieser Prozentsatz die Punktzahl und deren Umrechnung in Sterne, von einem bis fünf. Der Client zeigt nur eine grüne oder rote Vorschau, eine Vorhersage, nie eine Entscheidung.

**Die Domäne kennt weder die Datenbank noch HTTP.** Ich wollte die gesamte Spiellogik testen können, ohne einen Server oder eine Datenbank zu starten, und sicherstellen, dass ein Controller keine Geschäftsregel enthalten kann, die nur er kennt. `GeoForge.Core`, also die Formen `Shape2D`, sieben konkrete Formen und die Fabrik `Shape2DFactory`, hängt von keinem anderen Projekt der Solution ab; `GeoForge.Api` (Controller, DTOs) und `GeoForge.Infrastructure` (EF Core, Migrationen) hängen von ihm ab, nie umgekehrt. Die 131 xUnit-Unit-Tests laufen allein auf diesem Projekt, ohne dass eine Datenbank oder ein Server gestartet werden müsste.

**Von Spielern erstellte Inhalte durchlaufen einen Ablauf mit mehreren Zuständen, nicht einen Knopf «Veröffentlichen».** Ein schlecht gestaltetes Level, zu leicht, zu schwer, unspielbar, verschlechtert das Erlebnis aller anderen Spieler; ungefiltert zu veröffentlichen kam nicht in Frage. Ein Level durchläuft Entwurf, Test durch seinen Autor, Einreichung, Moderation, angenommen oder abgelehnt, mit einem zugewiesenen Schwierigkeitsgrad, und dann die Veröffentlichung, alles gesteuert über ein Admin-Panel, das auch die Konten und die Systemlevel verwaltet.

**Ein Level kann von einem Algorithmus erzeugt und seine Schwierigkeit automatisch bewertet werden, ohne jedes Mal einen Menschen einzuschalten.** Jedes Level eines wachsenden Katalogs von Hand zu schreiben, skaliert nicht, und den Schwierigkeitsgrad «nach Augenmass» zu beurteilen, ergibt keine stimmige Rangfolge von einem Level zum anderen. Das Admin-Panel bietet neben dem oben beschriebenen Moderationsablauf einen prozeduralen Level-Generator und eine automatische Bewertung der Schwierigkeit, zwei Algorithmen, die über den Rahmen eines klassischen CRUD hinausgehen.

**Die Authentifizierung hält mehr aus als ein vergessenes Passwort.** Ein System aus Konten und Rangliste ist ein naheliegendes Ziel für automatisierte Anmeldeversuche, wenn nichts sie bremst. Sie beruht auf JWT-Token, mit BCrypt gehashten Passwörtern und Rollen, die die sensiblen Routen schützen; nach drei fehlgeschlagenen Anmeldungen wird ein Konto fünfzehn Minuten lang gesperrt, bevor ein neuer Versuch möglich ist.

**Phaser blieb eine abgeschottete Engine in ihrer Ecke, nicht mit dem Rest von React vermischt.** Ein Phaser-Canvas, das im Rhythmus des React-Lebenszyklus ein- und ausgehängt wird, mit Re-Renders und Navigation zwischen den Seiten, erzeugt leicht Geisterinstanzen des Spiels, die im Hintergrund weiterlaufen. Die Szenen des Spiels (`GameScene`, `ShapeRenderer`) liegen hinter einer stabilen Grenze, und der Zustand der laufenden Partie wird im Store `gameStore` der Bibliothek Zustand gespiegelt: Der Rest der Oberfläche, also der angezeigte Timer, die Schaltflächen und die modalen Dialoge, muss nie ins Innere des Canvas schauen, um zu wissen, wo die Partie steht.

## Was ich daraus gelernt habe

Dass die Regel «Der Client entscheidet nichts» sich beim ersten Controller leichter aufstellen lässt, als sie nachträglich hinzuzufügen: Weil die Platzierungsprüfung und die Punktzahl von Anfang an serverseitig lagen, musste ich nie zurückgehen, um eine Betrugslücke zu schliessen. Dass die Einbindung einer Spiel-Engine in eine Webanwendung kein Detail der Montage ist: Phaser und React haben jeweils ihre eigene Vorstellung davon, wem die Anzeige gehört, und das einzige Mittel, Konflikte zu vermeiden, ist, ausdrücklich und einmalig festzulegen, wo die Grenze verläuft, statt sie bei jedem Fehler neu zu ziehen. Dass das Dokumentieren eines Algorithmus vor der Verteidigung einen dazu zwingt zu prüfen, ob man ihn selbst wirklich versteht, und nicht nur, dass er kompiliert und die Tests besteht. Und dass ich mir einen schriftlichen Rahmen für den Einsatz von KI gesetzt habe, mit dem ausdrücklichen Ziel, alles ohne Hilfe wieder erklären und neu umsetzen zu können, hat meine Arbeitsweise stärker verändert als jede Architekturentscheidung.

## Zahlen

- 141 Commits, alle von Morgan Voltz, von Februar bis April 2026
- 97 C#-Dateien auf der API-Seite (~6 400 Zeilen ohne Migrationen), 54 TypeScript/TSX-Dateien auf der Frontend-Seite (~4 600 Zeilen)
- 136 automatisierte Tests (131 Unit-Tests mit xUnit + 5 Integrationstests), Abdeckungsbericht lässt sich erzeugen (Cobertura + ReportGenerator)
- 38 API-Anfragen in der Bruno-Collection
- 26 REST-Endpunkte auf 4 Controllern, mit Swagger dokumentiert
- 7 Formtypen, 3 Grössen, 4 Rotationen
- Rangliste der 50 besten Punktzahlen, nach Level filterbar
- Kontosperre nach 3 fehlgeschlagenen Anmeldungen, für 15 Minuten

## Links

- Repository: internes GitLab der UHA 4.0, nicht öffentlich.
- Demo: nicht veröffentlicht; die Podman-Compose-Umgebung (nginx, API, PostgreSQL) diente der Entwicklung, nicht einer dauerhaft gehosteten Instanz; die Verteidigung stützte sich auf eine eigene Mappe (Slidev-Folien, Pitch, Architekturdiagramm).
- Dokumentation: Architektur, Datenmodell, Gameplay-Algorithmen, Deployment-Leitfaden, UML-Diagramme und Verteidigungsunterlagen sind im Repository des Projekts vorhanden, aber nicht separat veröffentlicht.

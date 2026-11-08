---
title: "MomoCad"
slug: "momocad-de"
type: "project"
lang: "de"
translation_key: "momocad"
date: 2026-10-26
subtitle: "Ein 2D-Zeichenprogramm im CAD-Stil, dessen Zustand nur ein abgespieltes Ereignisjournal ist"
seo_description: "MomoCad ist das Leitprojekt UHA 4.0.2: ein 2D-Zeichenprogramm in C#/.NET 10 mit hexagonaler Architektur, Event Sourcing und 275 Tests bei 100 % Abdeckung."
featured: true
status: "en-cours"
role: "Konzeption, Entwicklung, Tests und Dokumentation, allein"
period_start: 2026-09-04
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: true
tags: [csharp, dotnet, architecture-hexagonale, event-sourcing, xunit, plantuml, ddd, clipper2, mcp, avalonia]
skills: ["4.0.2/E00", "4.0.2/E01", "4.0.2/E02", "4.0.2/E03", "4.0.2/E04", "4.0.2/E05", "4.0.2/E06", "4.0.2/O02"]
---

MomoCad ist mein Leitprojekt (roter Faden) im zweiten Jahr des Studiengangs UHA 4.0.2. Die Aufgabenstellung verlangt eine Anwendung und eine API, die geometrische Formen in objektorientierter Programmierung verwalten: Formen erstellen und löschen, Verbunde aus mehreren Formen zusammensetzen, ihre mathematischen Eigenschaften berechnen und ein Sprachmodell die Daten abfragen lassen. Der Name setzt sich aus «Morgan» und AutoCAD zusammen, dem Werkzeug, das den gewählten Ansatz inspiriert hat.

Stand 26. Oktober 2026 ist die erste Lieferung, der fachliche Kern, geschrieben und zu hundert Prozent durch Tests abgedeckt. Die folgenden Lieferungen, die API und ihre Datenbank, der Arbeitsplatz, die Synchronisation und der Chatbot, sind spezifiziert und warten auf ihre Umsetzung.

## Das Problem

Die Aufgabenstellung ist auf eine Zeichenanwendung oder ein Spiel in der Art von Tangram ausgelegt, das über eine Website bereitgestellt wird. Ich wollte daraus etwas anderes machen: ein kleines 2D-Zeichenprogramm, das sich wie ein CAD-Werkzeug verhält. Man setzt Formen durch Vereinigung und Subtraktion zusammen, und die entstehende zusammengesetzte Form behält die Erinnerung an die Formen, aus denen sie hervorgeht. Das macht das Projekt beim Bauen interessant, denn eine Komposition ist nicht mehr bloss eine Liste von Objekten, sondern eine Historie von Operationen.

Zwei Abweichungen von der Aufgabenstellung wurden mit der betreuenden Person besprochen und bestätigt: eine Desktop-Anwendung statt einer Website und ein zunächst lokaler Betrieb mit anschliessender Synchronisation auf einen Server. Die gehostete API und die Datenbank sind wie in der Aufgabenstellung weiterhin zu liefern, ebenso der Chatbot.

Das eigentliche technische Problem lautet dann: Wie stellt man eine Zeichnung so dar, dass Rückgängigmachen, Historie und Synchronisation zwischen mehreren Arbeitsplätzen keine nachträglich aufgesetzten Funktionen sind, sondern natürliche Folgen des Modells?

## Die technischen Entscheidungen

**Nichts wird als Zustand gespeichert, alles ist Journal.** Jede Aktion der zeichnenden Person wird zu einem Ereignis, das an ein Journal angehängt wird, und dieses Journal wächst nur. Der angezeigte Zustand ist ein erneutes Abspielen dieses Journals von Anfang an. Die Methode, die ein Ereignis anwendet, ist dieselbe, ob man handelt oder abspielt: Es kann also keine Abweichung geben zwischen dem, was man nach dem Zeichnen sieht, und dem, was man erhält, wenn man die Zeichnung von Grund auf neu aufbaut. Ein Test spielt dasselbe Journal zweimal ab und vergleicht die Ergebnisse. Historie, Rückgängigmachen und Synchronisation werden zu Lesezugriffen auf das Journal, nicht zu eigenen Mechanismen.

**Die hexagonale Architektur wird vom Compiler garantiert, nicht von einer Konvention.** Das Projekt des fachlichen Kerns referenziert kein externes Paket, und die Methoden, die eine Form oder eine Zeichnung verändern, sind intern im Projekt: Nur die Command-Handler und das Abspielen dürfen sie aufrufen, die Tests greifen über eine ausdrücklich gewährte Sichtbarkeit darauf zu. Ein Host, ob Konsole, API oder grafische Oberfläche, kann eine Zeichnung physisch nicht verändern, ohne einen Befehl zu durchlaufen. Der Abhängigkeitsgraph zwischen den zehn Projekten der Solution wurde gleich am ersten Tag festgelegt, bevor die Adapter existierten.

**Die Invarianten sind in positiver Logik geschrieben.** Um einen ungültigen Radius abzulehnen, prüft der Code «ist nicht strikt positiv» statt «ist kleiner oder gleich null». Der Unterschied zählt bei einem Wert, der keine Zahl ist: Jeder Vergleich mit ihm ist falsch, und die negative Form hätte den Radius durchgelassen. Die Tests prüfen die Grenzfälle mit nicht numerischen und unendlichen Werten.

**Die Geometrie wird von der Domäne selbst berechnet, nicht ausgelagert.** Die Fläche eines Polygons stammt aus der Gaussschen Trapezformel, ein Kreis wird in hundertachtundzwanzig Segmente diskretisiert, mit einem gemessenen relativen Fehler, und die Koordinaten werden vor der Berechnung neu zentriert, damit die Gleitkommagenauigkeit auch weit vom Ursprung erhalten bleibt. Die Bibliothek Clipper2 kommt nur für Vereinigung und Subtraktion von Konturen zum Einsatz, hinter einem Port der Domäne. Bei der Vorbereitung dieses Adapters habe ich festgestellt, dass die offizielle Dokumentation der Bibliothek für eine ihrer Funktionen eine falsche Reihenfolge der Argumente angab, direkt an der Binärdatei überprüft, und ich habe es festgehalten, bevor ich auch nur eine Zeile Code geschrieben hatte.

**Die Tests werden durch Mutationstests überprüft.** Den Code absichtlich zu beschädigen und zu schauen, ob ein Test fehlschlägt, hat dazu geführt, jede Toleranz durch zwei Tests einzugrenzen, einen, der einen tausendmal kleineren Wert ablehnt, und einen, der den Grenzwert akzeptiert, und den Inhalt der Fehlermeldungen zu prüfen statt nur ihren Typ. Die Abdeckung von hundert Prozent wird vom Werkzeug gemessen, nicht in einer README behauptet.

**Die Dokumentation geht dem Code voraus.** Neun PlantUML-Diagramme wurden vor den Klassen geschrieben und dann in vier Ansichten aufgeteilt, weil ein einziges Gesamtdiagramm nicht mehr lesbar war. Das Vokabular des Projekts ist in einer Ubiquitous Language festgelegt, und die Dokumentation selbst wurde einem Kreuzaudit unterzogen: einhunderteinundfünfzig rohe Befunde, siebenundsiebzig von unabhängigen Prüfern bestätigt, achtzehn Rahmenentscheidungen entschieden.

## Was ich daraus gelernt habe

Dass ein Modell, das aus einem einfachen Grund gewählt wurde, nämlich die Operationen einer Zeichnung festzuhalten, Funktionen kostenlos mitliefert, die ich sonst eine nach der anderen zusammengebastelt hätte. Dass die stabilsten Leitplanken die sind, die der Compiler erzwingt, nicht die, die man sich vornimmt einzuhalten. Dass Testen auch heisst, die eigenen Tests Lügen strafen zu wollen. Und dass ein Einzelprojekt eine Teamdisziplin sehr gut verträgt, unabhängige Reviews eingeschlossen, sofern man sie formalisiert.

Was ich anders machen würde: den Arbeits-Branch früher in den Haupt-Branch mergen und die Dokumentation laufend committen, statt hundertfünfundneunzig Seiten ausserhalb des Repositorys liegen zu lassen.

## Entsprechung zu den Etappen des roten Fadens

| Etappe der Aufgabenstellung | In MomoCad |
|---|---|
| 0. MVP in UML spezifizieren | Backlog, Wireframe, Pseudocode für die Fläche einer zusammengesetzten Form, validierte User Story |
| 1. Klassen | `Rectangle`, `Triangle`, `Cercle`, im Konsolen-Host instanziiert |
| 2. Kapselung | Öffentliche Accessoren, interne Modifikatoren, nur durch einen Befehl oder das Abspielen aufrufbar |
| 3. Vererbung | Abstrakte `Forme`, Polymorphismus durch eine einzige Schleife gezeigt und getestet |
| 4. UML | Neun PlantUML-Diagramme, vier Klassenansichten |
| 5. Komposition und Polymorphismus | `Dessin` aggregiert die Formen und summiert Flächen und Umfänge, getestet bis zu hundert Formen |
| 6. Tests | 275 Tests, 100 % der Zeilen und Zweige des Kerns |
| 7. API | Lieferung 2, folgt |
| 8. ORM | Lieferung 2, folgt, EF Core mit SQLite am Arbeitsplatz und PostgreSQL auf dem Server |
| 9. Deployment | Lieferung 2, folgt |
| 10. MCP-Server und Chatbot | Lieferung 5, folgt |
| 11. Frontend | Lieferung 3, folgt, Desktop-Anwendung mit Avalonia, bestätigte Abweichung |
| Optional: Design Patterns | Composite vorbereitet, Command zur Hälfte geschrieben, Adapter für das In-Memory-Journal umgesetzt |
| Optional: persönlich | Zuerst lokal und Synchronisation zwischen Arbeitsplätzen, Lieferungen 3 und 4 |

## In Zahlen

- 10 Projekte in der Solution, 36 Typen und 147 Member im Kern
- 1 038 Zeilen Anwendungscode, 4 385 Zeilen Tests
- 275 Tests, 100 % der Zeilen und Zweige abgedeckt
- 9 PlantUML-Diagramme, 2 300 Zeilen Konzeptdokumentation, ein Referenzhandbuch von 195 Seiten
- 18 Commits, September 2026

## Links

- Repository: internes GitLab der UHA 4.0, nicht öffentlich. Eine Lesekopie wird mit den folgenden Lieferungen veröffentlicht.
- Offizielle Aufgabenstellung des roten Fadens 4.0.2: in der Projektdokumentation transkribiert.

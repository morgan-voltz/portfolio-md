---
title: "AUTOFIN Control"
slug: "autofin-control-de"
type: "project"
lang: "de"
translation_key: "autofin-control"
date: 2026-10-26
subtitle: "Heterogene PDF-Bilanzen über Geometrie statt über KI lesen, für eine Finanzkontrolle, die lokal bleiben muss"
seo_description: "AUTOFIN Control liest und prüft Buchhaltungsdaten aus PDF-Bilanzen für die Finanzkontrolle der von m2A geförderten Vereine, entwickelt in .NET 10."
featured: true
status: "stable"
role: "Hauptentwickler, Team von 5"
period_start: 2026-01-09
period_end: 2026-05-31
repo_url: ""
demo_url: ""
client: "m2A (Mulhouse Alsace Agglomération)"
is_fil_rouge: false
tags: [csharp, dotnet, wpf, mvvm, clean-architecture, pdf-extraction, sqlite, excel, xunit, msix]
skills: ["4.0.1/PM-01", "4.0.1/PM-02", "4.0.1/PM-08", "4.0.1/FR-01", "4.0.1/FR-15"]
---

AUTOFIN Control ist das Kundenprojekt meines ersten Jahres im Studiengang UHA 4.0: eine Desktop-Anwendung für die Abteilung Finanzkontrolle von m2A, der Agglomeration Mulhouse. Entwickelt habe ich sie zwischen Januar und Mai 2026 mit einem Team von fünf Studierenden, und ich war dabei Hauptentwickler: rund 38 % der 393 Commits, die Architektur, die Extraktionspipeline sowie einen guten Teil der Konventionen und der Teamdokumentation.

Die Software wurde dem Auftraggeber in drei signierten MSIX-Versionen ausgeliefert.

## Das Problem

Die Finanzcontroller von m2A prüfen jedes Jahr die Jahresabschlüsse der Vereine, die die Agglomeration subventioniert. Jeder Verein schickt eine Bilanz und eine Erfolgsrechnung als PDF, erstellt von seinem eigenen Steuerberater oder Treuhänder, also in ebenso vielen Layouts, wie es Kanzleien gibt. Bis dahin bestand die Arbeit darin, die Beträge des laufenden und des Vorjahres von Hand in eine standardisierte Excel-Vorlage zur Nachverfolgung zu übertragen: eine langwierige, repetitive Aufgabe und vor allem eine Quelle von Eingabefehlern, und zwar bei Zahlen, bei denen ein Fehler nicht hinnehmbar ist.

AUTOFIN Control automatisiert diese Kette, ohne den Controller aus ihr zu entfernen: das PDF importieren, die Buchungszeilen daraus extrahieren, sie visuell prüfen und korrigieren lassen, aggregieren, den richtigen Kategorien der Vorlage zuordnen und schliesslich exportieren. Eine Vorgabe stand von Anfang an fest und hat jede spätere Entscheidung beeinflusst: Die Finanzdaten der Vereine dürfen den Arbeitsplatz des Controllers nie verlassen. Kein Server, keine Cloud, kein Netzwerkaufruf, sondern eine lokale SQLite-Datenbank und eine Verarbeitung auf dem Rechner, denn es geht um öffentliche Gelder und um Daten fremder Vereine.

Das eigentliche technische Problem war nicht, ein PDF zu lesen, sondern es zuverlässig zu lesen, trotz Layouts, die man nicht in der Hand hat, mit einem Team von fünf Personen sehr unterschiedlichen Niveaus, für einen Auftraggeber, der die Anwendung tatsächlich einsetzen würde.

## Die technischen Entscheidungen

**Die Extraktion liest die Geometrie des PDF, nicht seinen Rohtext, und verwendet keinerlei KI.** Vor dieser Lösung gab es zwei andere Ansätze, einen Modus über ein lokales Sprachmodell und eine Brücke zu einem Python-Skript, die dann gesamthaft aus dem Projekt entfernt wurden, einschliesslich `LlmExtractionService` und `RegexGeneratorService` sowie ihrer NuGet-Abhängigkeiten. Übrig bleibt eine Pipeline in fünf Phasen, die ausschliesslich mit den X/Y-Koordinaten der Wörter arbeitet: `WordRowGrouper` fasst die Wörter zu Zeilen zusammen, `RowCellParser` zerlegt jede Zeile in Zellen, `ColumnPositionDetector` erkennt die numerischen Spalten per Clustering, `NetColumnIdentifier` unterscheidet die Spalten Netto N und Netto N-1 von Brutto und Abschreibungen, und `AccountingLineExtractor` baut die endgültigen Zeilen. Der Grund: Bei einem Werkzeug für die Finanzkontrolle muss dasselbe PDF immer dasselbe Ergebnis liefern und für jemanden nachvollziehbar bleiben, der den Code nicht geschrieben hat, und das garantiert ein Sprachmodell nicht.

**Der Kern der Domäne hängt von nichts ab, und das lässt sich beim Öffnen der Projektdatei überprüfen.** `Autofin.Core` referenziert kein einziges NuGet-Paket, nur Modelle und Service-Schnittstellen. `Autofin.Infrastructure` implementiert sie (SQLite, ClosedXML, PDF-Extraktion, Mapping), und `Autofin.Client` setzt alles über einen einzigen Einstiegspunkt der Dependency Injection zusammen. Bei fünf Personen unterschiedlichen Niveaus im selben Repository ermöglicht diese Trennung, dass jemand die Extraktion anfasst, ohne den Export zu gefährden.

**Eine extrahierte Zeile wird einer Buchhaltungskategorie über einen Score zugeordnet, nicht über das erste gefundene Schlüsselwort.** Die Bezeichnungen ändern sich von Büro zu Büro: andere Abkürzungen, andere Akzente und Gross- und Kleinschreibung für denselben Begriff. Ein einfaches Schlüsselwort, das als erstes trifft, hätte zu viele Zeilen in die falsche Kategorie einsortiert. Die Engine normalisiert die Bezeichnung, addiert positive Schlüsselwörter, zieht negative Schlüsselwörter ab, die eine Kategorie ausschliessen, und vergibt einen Bonus, wenn eine externe Konfiguration diese Bezeichnung für diesen Kunden bereits kennt. Dreiundsechzig dedizierte Tests decken die elf Kategorien der Erfolgsrechnung und die zwanzig Kategorien der Bilanz ab. Ein zweiter, neuerer Baustein (Tickets AC-343/AC-344), `ExcelCategoryReader` und `ExcelCategoryRepository`, liest die Kategorien direkt aus der Excel-Arbeitsmappe, die der Controller importiert, und soll diese fest codierten Listen mit der Zeit ersetzen, die nur noch als Sicherheitsnetz überleben werden.

**Die Excel-Exportpipeline wurde mitten im Projekt vollständig neu konzipiert, damit nie wieder eine Formel der Kundenarbeitsmappe überschrieben wird.** Die erste Version erzeugte bei jedem Export einen Teil der Datei neu, mit dem Risiko, bereits vorhandene Formeln zu verlieren, auch solche zwischen Tabellenblättern. Das Ticket AC-395 hat den Vertrag geändert: Ein neuer Service, `ExcelInsertionExportService`, fügt nur die Spalten des laufenden Geschäftsjahres ein, erfasst die bestehenden Formeln und schreibt sie wieder zurück und verschiebt die Historie nach rechts, ohne sie anzutasten. Die Änderung ist in einem datierten Entscheidungsprotokoll festgehalten: Nach der Umstellung bestehen 131 von 131 Tests.

**Die technischen Schulden wurden nicht zufällig entdeckt, sondern durch Audits gemessen, die bei einem KI-Werkzeug in Auftrag gegeben, überprüft und umgesetzt wurden.** Ein globales Audit gleich im Februar, im März wieder aufgenommen, dann im April ein Audit pro Architekturschicht, mit nach Wirkung und Aufwand geordneten Tickets. Eines davon hat eine Regression beziffert, die niemand kommen sah: Eine Datei der Aggregationsseite, die bei rund dreihundert Zeilen bleiben sollte, zählte 922. Die Korrektur hat die Fachlogik in das zugehörige ViewModel verschoben und die Datei auf 478 Zeilen zurückgeführt, fast halbiert. Das Problem wurde damit eher verlagert als beseitigt: Dieses ViewModel überschreitet selbst 900 Zeilen, zwei benachbarte Dateien haben nie dieselbe Behandlung erhalten, und die Datei wächst bereits wieder. Die Lehre daraus ist nicht, das Problem gelöst zu haben, sondern mir die Mittel gegeben zu haben, seine Rückkehr zu sehen.

## Was ich daraus gelernt habe

Dass ein Projekt für einen echten Kunden die Art der Anforderung grundlegend verändert: Ein Fehler bei einem Betrag ist kein Bug wie jeder andere, und die Disziplin aus Tests, Audits und Dokumentation hört auf, ein Häkchen auf einer Liste zu sein, und wird zur Voraussetzung dafür, dass ein Finanzcontroller dem Werkzeug vertraut. Dass Dokumentieren für ein Team von fünf Personen unterschiedlichen Niveaus eine eigene Arbeit ist: Ein didaktischer Einführungsleitfaden und ein Glossar für Entwickler aus dem Web, die Routen suchen, wo es ViewModels gibt, sind kein Luxus, sie entscheiden darüber, wer tatsächlich mitarbeiten kann.

Dass sich meine Git-Disziplin im Lauf des Projekts entwickelt hat, statt vom ersten Commit an dazusein: Die ersten Wochen enthalten vage Nachrichten und Commits, die mehrere Themen auf einmal berühren, die späteren Präfixe, die auf ein bestimmtes Ticket verweisen. Das ist nicht nur ein Wechsel des Vokabulars: Es macht eine Historie auch für jemand anderen als mich selbst lesbar.

Was ich anders machen würde: den Vertrag des Excel-Exports mit dem tatsächlichen Bedarf festlegen, bevor ich die ersten 900 Zeilen schreibe, statt ihn neu denken zu müssen, nachdem das Problem der überschriebenen Formeln festgestellt wurde. Einen von einem internen Audit gemeldeten Mangel noch am selben Tag beheben, statt ihn im nächsten Audit wieder auftauchen zu lassen. Und meine Arbeit in gezieltere Aufgaben aufteilen, statt an mehreren Fronten gleichzeitig voranzukommen, um die Fristen leichter einzuhalten.

## In Zahlen

- 5 Studierende im Team, 393 Commits insgesamt, davon rund 150 (38 %) von mir
- rund 26 500 Zeilen C# und XAML, dazu Python-Hilfsskripte, im am weitesten fortgeschrittenen Stand des Projekts
- 117 dokumentierte Tests (54 zur Extraktion, 63 zum Mapping), 131 von 131 nach dem Umbau des Excel-Exports
- 13 PlantUML-Diagramme, rund zwanzig technische Dokumente (Architektur pro Schicht, Onboarding, Audits, Kundendossier)
- 3 ausgelieferte Versionen als signiertes MSIX, von Januar bis Mai 2026

## Links

- Repository: internes GitLab des Programms UHA 4.0, nicht öffentlich (Zugang nur für Studierende und das Lehrteam).
- Keine Online-Demo: Windows-Desktop-Anwendung für den internen Gebrauch, ohne Webversion.
- Dokumentation: technisches Dossier, im Februar 2026 an den Auftraggeber geliefert und zusammen mit rund zwanzig Architektur- und Audit-Dokumenten im Repository des Projekts aufbewahrt.

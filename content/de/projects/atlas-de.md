---
title: "Atlas"
slug: "atlas-de"
type: "project"
lang: "de"
translation_key: "atlas"
date: 2026-10-26
subtitle: "Eine .NET-Plattform, die die öffentlichen französischen Unternehmens- und Immaterialgüterdaten vereint, durch Tests abgesichert"
seo_description: "Atlas führt öffentliche französische Unternehmens- und Immaterialgüterdaten hinter einer getesteten .NET-API in hexagonaler Architektur zusammen."
featured: false
status: "en-cours"
role: "Konzeption, Architektur, Entwicklung und Tests, allein"
period_start: 2026-05-26
period_end: ""
repo_url: "https://github.com/morgan-voltz/atlas"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [csharp, dotnet, architecture-hexagonale, cqrs, entity-framework-core, postgresql, xunit, securite, uno-platform]
skills: ["4.0.2/E06", "4.0.2/E07", "4.0.2/E08", "4.0.2/E09"]
---

Atlas ist eine .NET-Plattform, die ich seit Ende Mai 2026 allein entwickle, um die öffentlichen französischen Daten zu Unternehmen (nationales Register RNE) und zum gewerblichen Rechtsschutz (Marken, Patente sowie Designs, also Muster und Modelle, über das INPI) zusammenzuführen. Dazu kommt eine Monitoring-Engine, die gesetzlich vorgeschriebene Bekanntmachungen und Branchennachrichten darüberlegt. «Atlas» ist ein Codename: Der endgültige Name wird erst beim kommerziellen Start gewählt, nicht früher.

Das Backend ist für diesen ersten Umfang vollständig (Konten mit Zwei-Faktor-Authentifizierung, Suche nach Unternehmen und gewerblichen Schutzrechten, Favoriten, Monitoring mit Alerts), und ein erster Web-Client ist ausgeliefert. Ob das Geschäftsmodell ein SaaS im Abonnement oder eine Jahreslizenz wird, ist noch nicht entschieden, aber eine Premium-Schicht ist bereits abgetrennt, um diese Entscheidung aufzufangen, ohne den Kern des Produkts neu schreiben zu müssen.

## Das Problem

Die öffentlichen französischen Daten zu Unternehmen und zum gewerblichen Rechtsschutz gibt es, aber sie sind auf uneinheitliche Portale verstreut: das nationale Unternehmensregister auf der einen Seite, die Marken- und Patentdatenbanken des INPI auf der anderen, die gesetzlich vorgeschriebenen Bekanntmachungen des BODACC wieder anderswo. Die kommerziellen Aggregatoren, die eine einheitliche Sicht anbieten, bleiben geschlossen und über eine ganz einfache Nutzung hinaus kostenpflichtig. Sieben Berufsprofile erleben diese Zersplitterung täglich: Anwälte und Juristen, Kanzleien für gewerblichen Rechtsschutz, Compliance- und KYC-Teams, Steuerberater oder Treuhänder, M&A-Investoren, Wettbewerbsbeobachtung und Entwickler, die diese Daten nutzen möchten, ohne die Integration neu zu erfinden.

Das Produktproblem besteht also darin, einen einzigen Zugang zu diesen Quellen zu bauen, mit einer Unternehmenssuche, einem Zugriff auf gewerbliche Schutzrechte und einem Monitoring, das Alarm schlägt, statt zu verlangen, dass man jede Quelle von Hand neu prüft. Das technische Problem ist dagegen methodischer Natur: Wie baut man allein, über mehrere Monate, ein System, dessen Zielpublikum (Juristen, Compliance) keine Ungenauigkeit duldet, ohne das Peer-Review eines Teams, das Fehlentwicklungen auffängt?

## Die technischen Entscheidungen

**Die hexagonale Architektur wird von einem Testprojekt geprüft, nicht der Disziplin überlassen.** Die Abhängigkeiten zwischen den 18 Projekten der Solution folgen einer einzigen Richtung (`Atlas.Shared`, dann `Atlas.Domain`, dann `Atlas.Application` (und ihre Variante `.Premium`), dann die acht `Atlas.Infrastructure.*`-Projekte, dann `Atlas.Api`), und ein eigenes Projekt, `Atlas.Architecture.Tests`, lässt die CI fehlschlagen, sobald eine Schicht auf etwas verweist, worauf sie nicht verweisen darf, einschliesslich des Verbots für einen Client, mit der Infrastruktur statt mit der HTTP-API zu sprechen. In einem Projekt ohne zweiten menschlichen Blick ersetzt dieser Test das Code-Review, das ich nicht habe.

**Die Domäne verweigert das Implizite: Fehler und Bezeichner sind Werte, nie Überraschungen.** Jeder Anwendungsfall läuft über einen Typ `Result<T>`: Ein Validierungsfehler oder eine verletzte Geschäftsregel steht im Rückgabewert des Handlers, nie in einer Exception, und die Validierungen laufen vor ihm über ein eigenes MediatR-Behavior. Eine SIREN-Nummer folgt derselben Logik: nie ein blosser `string`, sondern ein Value Object, das sein Format mit dem Luhn-Algorithmus prüft, wobei ein fehlerhafter Wert an der Grenze der Domäne abgewiesen wird.

**Die Persistenz läuft über einen ORM, den die CI wirklich ausführt, das Deployment über eine Maschine, die sich mit einem einzigen Befehl neu aufbauen lässt.** Die Daten liegen in PostgreSQL über Entity Framework Core; die CI wendet bei jedem nächtlichen Lauf die Migrationen auf eine neue Datenbank an, und Hangfire plant dort die Hintergrundaufgaben. Die Vorproduktion läuft auf einem einzigen VPS mit docker-compose (API, Datenbank, Jobs und statische Dateien des Web-Clients), hinter einem Caddy-Reverse-Proxy, der sein TLS-Zertifikat allein verwaltet, mit einer einzigen Domain, um CORS zu vermeiden.

**Die Sicherheit ist für ein Publikum gedacht, das Amateurhaftigkeit nicht verzeiht.** Passwörter mit Argon2id gehasht, kurzlebige JWT-Tokens mit RS256 signiert, dazu ein rotierendes Refresh-Token, das serverseitig aufbewahrt wird, Zwei-Faktor-Authentifizierung per TOTP und INPI-Zugangsdaten, die mit AES-256-GCM verschlüsselt gespeichert, nur für die Dauer einer Anfrage entschlüsselt und nie protokolliert werden, ein absolutes Verbot, das in der Dokumentation festgeschrieben ist.

**Jede strukturelle Entscheidung ist datiert und wird nie gelöscht, auch wenn ich meine Meinung ändere.** 29 ADR halten die Architekturentscheidungen fest, einschliesslich der Kehrtwenden: Die Client-Strategie ging von Blazor WebAssembly zu MAUI (mobil) und Avalonia (Desktop) und dann zu einer einzigen Oberfläche in Uno Platform über; bei jeder Änderung wird der vorherige ADR als «ersetzt» markiert, statt umgeschrieben zu werden. Die Domäne selbst musste für diese drei Strategien nie eine Zeile ändern, weil die Clients nur über die HTTP-API mit ihr sprechen.

**Die CI prüft das Projekt gegen die reale Welt, nicht nur gegen sich selbst.** Zusätzlich zu den Unit-Tests und den Architekturtests, die bei jedem Push laufen, startet ein nächtlicher Workflow die API und spielt über Bruno-Collections Anfragen gegen die Produktions-API des INPI ab, um einen Vertragsbruch auf Seiten des Anbieters zu erkennen, bevor ein Nutzer ihn entdeckt. Er blieb seit seiner Erstellung Ende Mai jede Nacht rot und fand erst am 25. September 2026 zu einem stabilen Grün zurück, und seine erste nützliche Ausführung diente gerade dazu, eine Änderung der Authentifizierung beim INPI aufzuspüren. Der Mechanismus ist noch jung, aber das Prinzip trägt: den Code gegen eine echte Drittanbieter-API zu testen statt gegen Mocks.

## Was ich daraus gelernt habe

Dass eine Architektur, in der die Domäne von nichts ausser sich selbst abhängt, einen radikalen Wechsel der Client-Strategie auffängt, ohne dass man den Kern des Produkts anfassen muss: Die drei in vier Monaten ausprobierten Client-Technologien haben nie verlangt, `Atlas.Domain` oder `Atlas.Application` anzufassen. Dass Sorgfalt ihren Preis in Geschwindigkeit hat (Architekturtests pflegen, eine CI, die nachts eine echte Drittanbieter-API abfragt, 29 ADR aktuell halten), der sich zwangsläufig im Rhythmus der Auslieferungen zeigt, dass dies aber der Preis ist, den man für ein Publikum zahlt, das Ungefähres nicht duldet. Und dass es spätere Mühe erspart, eine getroffene Entscheidung zu dokumentieren, auch wenn sie noch nicht überall umgesetzt ist, statt sie Monate später aus dem Gedächtnis rekonstruieren zu müssen.

Eine Präzisierung, weil das Repository öffentlich ist und `git log` sie überprüfbar macht: Ich tippe nicht jede Zeile im strengen Sinn allein. Ich praktiziere agentische Entwicklung mit Claude als Pair-Programming-Partner, unter Rahmenbedingungen, die ich selbst setze; im Repository habe ich ein eigenes Werkzeug für diese Arbeitsweise, `Atlas.DevEye`. Die Mehrheit der Commits des Hauptzweigs nennt Claude als Co-Autor; ich bleibe der einzige menschliche Beitragende, der Urheber der Architekturentscheidungen und der letzte Reviewer. «Allein» zu sagen, ohne dies zu sagen, wäre unvollständig.

Was ich anders machen würde: Die Entwicklung des Projekts ist unterwegs von einem als Open Source gedachten Modell zu einem proprietären kommerziellen Modell abgedriftet, ohne dass ich die Lizenz und das README im gleichen Tempo angepasst hätte. Die Datei `LICENSE` trägt selbst den Vermerk «vor der öffentlichen Veröffentlichung zu ersetzen», obwohl das Repository bereits öffentlich ist. Eine Diskrepanz, die vor jeder weiteren externen Sichtbarkeit zu beheben ist.

## Zahlen

- 18 Produktionsprojekte in `src/` und 11 Testprojekte in `tests/` (29 insgesamt), hexagonale Architektur in Schichten
- Rund 47 000 Zeilen C#-Anwendungscode, rund 11 000 Zeilen Tests
- 472 xUnit-Tests (453 `[Fact]` + 19 `[Theory]`), dazu ein eigenes Projekt für die Architekturregeln
- 74 von der API bereitgestellte Endpoints (Minimal APIs)
- 29 ADR, 76 Feature-Beschreibungen
- 221 Commits auf dem Hauptzweig, von Ende Mai bis Ende September 2026; 151 (≈ 68 %) nennen Claude als Co-Autor, und ich bleibe der einzige menschliche Beitragende des Repositorys

## Links

- Repository: öffentlich, auf GitHub (`github.com/morgan-voltz/atlas`). Noch kein getaggtes Release.
- Demo: derzeit keine öffentliche Demo; die geplante Website mit der Benutzerdokumentation ist noch nicht deployt.
- Dokumentation: 29 ADR und die nummerierten Feature-Beschreibungen liegen im Repository (`docs/`).

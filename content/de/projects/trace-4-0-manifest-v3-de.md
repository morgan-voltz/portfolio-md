---
title: "Trace 4.0, Migration auf Manifest V3"
slug: "trace-4-0-manifest-v3-de"
type: "project"
lang: "de"
translation_key: "trace-4-0-manifest-v3"
date: 2026-10-26
subtitle: "Die Tracking-Erweiterung der Schule, die mit dem Ende von Manifest V2 ausfiel, aus eigener Initiative wieder zum Laufen gebracht"
seo_description: "Solo-Migration der Chrome-Erweiterung zur Lernzeiterfassung der UHA 4.0 auf Manifest V3 im Januar 2026, nachdem Chrome Manifest V2 abgeschaltet hatte."
featured: false
status: "stable"
role: "Solo-Migration auf Manifest V3, persönliche Initiative ausserhalb des Studiengangs"
period_start: 2026-01-01
period_end: 2026-01-20
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [javascript, chrome-extension, manifest-v3, service-worker, chrome-storage-api, rest-api, offscreencanvas, jquery]
skills: ["4.0.1/FR-08", "4.0.1/FR-15"]
---

Trace 4.0 ist die offizielle Chrome-Erweiterung der UHA 4.0, die die tatsächliche Aktivitätszeit angemeldeter Studierender auf den Ausbildungsressourcen misst. Sie beruhte auf Manifest V2, dem Format, das Chrome schliesslich abgeschaltet hat. Anfang 2026 habe ich die Erweiterung aus eigener Initiative und ausserhalb jedes bewerteten Rahmens allein auf Manifest V3 migriert, geliefert in einer einzigen Iteration (v1.2.0) mit einem Changelog, das jede geänderte Datei dokumentiert.

## Das Problem

Die Erweiterung erfasst für jeden angemeldeten Studierenden die Zeit, die er auf den von der Ausbildungsplattform aufgelisteten Ressourcen verbringt: UHA-Zugangsdaten, Kontrollkästchen für die Nutzungsbedingungen (CGU), sichtbarer Umschalter, ein Symbol, das je nach Zustand die Farbe wechselt (grün aktiv, rot inaktiv, grau abgemeldet). Die gemessenen Sitzungen gehen an eine interne API der Schule, und der Studierende findet seine Ausbildungen, seine Kurse und seinen Verlauf im Popup wieder.

Das Problem war nicht funktional, sondern eines der Infrastruktur: Manifest V2, das Format, auf dem sie beruhte, wurde von Google in aktuellen Browsern schrittweise abgeschaltet. Ohne Eingriff weigerte sich das Tracking-Werkzeug zu laden, und niemand in der Ausbildung konnte es mehr benutzen.

Ich habe die Initiative ergriffen, die Erweiterung in meiner Freizeit zu migrieren. Die Schwierigkeit besteht nicht darin, ein paar Schlüssel des Manifests umzubenennen: Manifest V3 ändert das Ausführungsmodell. Das Hintergrundskript ist keine persistente Seite mehr, deren Zustand jederzeit lesbar bleibt, sondern ein kurzlebiger Service Worker, den Chrome nach Belieben beendet und neu startet. Alles, was einen dauerhaft im Speicher verfügbaren Zustand voraussetzte, musste neu gedacht werden.

## Die technischen Entscheidungen

**Der Service Worker hat keinen verlässlichen Zustand mehr, also wird die gesamte Speicherung asynchron.** `localStorage`, synchron, ist für einen Service Worker nicht zugänglich. Ich habe es überall durch das asynchrone `chrome.storage.local` ersetzt, hinter zwei kleinen Wrappern `getStorageData`/`setStorageData`, die auf `Promise` aufbauen. Die Änderung zieht sich kaskadenartig durch: Die drei Dateien, die den Zustand lasen (`background.js`, `manager.js`, `gandalf.js`), wechseln vollständig auf `async`/`await`, ohne das beobachtbare Verhalten zu ändern.

**Periodische Aufgaben leben nicht im Service Worker.** Die klassische Falle einer MV3-Migration ist ein `setInterval` im Hintergrundskript: Chrome schaltet einen inaktiven Service Worker nach etwa dreissig Sekunden ab, was dessen Ausführung unterbrechen würde. `background.js` enthält keines. Das Abfragen der URL-Änderung (`setInterval(checkUrlChange, 100)`) und das gebündelte Senden alle zwei Minuten (`sendPeriod = 2 * 60 * 1000`) leben vollständig im Content-Skript `activityWatcher.js`, einem klassischen Seitenkontext, den diese Beendigung nicht betrifft. `chrome.alarms` ist nicht nötig: Der richtige Ausführungskontext genügt.

**Das Symbol aus einem Service Worker zu ändern, verlangt, das Bild von Hand neu zu zeichnen.** `setIcon` kann keinen direkten Dateipfad mehr annehmen. Die Funktion `updateIcon` in `background.js` holt das PNG per `fetch`, wandelt es in ein `ImageBitmap` um, zeichnet es auf ein `OffscreenCanvas` von 128 Pixeln, extrahiert daraus die `ImageData`, und genau dieses Ergebnis akzeptiert `chrome.action.setIcon`. Eine Einschränkung, die auf dem Papier nebensächlich wirkt, wird zum heikelsten Detail der Migration.

**Die Konfiguration der API stammt aus dem Manifest, nicht aus einer kopierten Konstante.** Statt die Adresse der API fest im Code zu hinterlegen, liest der Code sie dynamisch mit `chrome.runtime.getManifest().host_permissions[0]` aus. Die einzige Quelle bleibt das Manifest. Die Berechnung ist dagegen identisch in den drei Dateien, die sie brauchen, kopiert statt in ein gemeinsames Modul ausgelagert, eine technische Schuld, die ich weiter unten erläutere.

**Zwei Kommunikationskanäle bestehen nebeneinander statt nur einem.** Das kontinuierliche Tracking läuft über einen benannten `Port` mit dem Namen `"Tracing"`, den `activityWatcher.js` einmal öffnet und für die Lebensdauer der Seite behält, damit der Service Worker die Ereignisse `ACTIVITY`/`URL_CHANGE` ohne Mehraufwand erhält. Die punktuellen Ereignisse (nach einer Anmeldung, einer Abmeldung, einer Änderung des Tracking-Schalters oder einem Klick auf «Mes formations») laufen über einen zweiten Kanal, `chrome.runtime.sendMessage({type: 'REFRESH_TRACKING'})`, aufgerufen aus drei Dateien (`gandalf.js`, `manager.js` und `P_index.js`) und empfangen von einem eigenen `chrome.runtime.onMessage` in `background.js`. Ich habe die beiden nicht zusammengelegt, weil sie nicht dieselbe Lebensdauer haben: Der erste besteht für die Dauer der Navigationssitzung auf einer Seite, der zweite ist ein Einwegaufruf, der durch eine Aktion des Nutzers ausgelöst wird.

## Was ich daraus gelernt habe

Dass die Plattform der Chrome-Erweiterungen ein präzises Sicherheits- und Ausführungsmodell hat und dass dessen genaue Kenntnis (Trennung von `permissions`/`host_permissions`, Objektformat der CSP, Lebenszyklus des Service Workers) die Art verändert, jede beliebige Erweiterung zu lesen, nicht nur diese. Dass bestehenden Code zu übernehmen, ohne sein Verhalten zu ändern, eine andere Übung ist, als bei null zu schreiben: Man muss zuerst verstehen, was da ist, bevor man entscheidet, was sich ändern soll. Und dass Einschränkungen, die auf dem Papier nebensächlich wirken, etwa ein Symbol zu wechseln, manchmal diejenigen sind, die den grössten technischen Umweg verlangen.

Was ich anders machen würde: Die Speicher-Wrapper und das Auslesen der URL aus dem Manifest sind mangels eines gemeinsamen Speichermoduls identisch in drei Dateien dupliziert. Bei einem Projekt dieser Grösse ist dadurch nichts kaputtgegangen, aber genau diese Art von Duplikation würde ich heute von Anfang an auslagern.

## Zahlen

- ≈1 500 Zeilen insgesamt: 1 030 Zeilen anwendungseigenes JavaScript in 8 Dateien, 104 Zeilen HTML, 361 Zeilen eigenes CSS
- mitgelieferte Drittbibliotheken (jQuery slim, Bootstrap 4, Popper, Moment.js, Font-Awesome-Kit): ≈14 931 Zeilen, davon 14 876 allein für Moment.js
- 1 ausgelieferte Migrationsversion: v1.2.0, am 20. Januar 2026
- 1 Changelog mit 52 Zeilen, das jede geänderte Datei dokumentiert, 1 README mit 115 Zeilen
- angestrebte Kompatibilität: Chrome 88 und höher
- 0 automatisierte Tests, 0 CI

## Links

- Repository: persönlicher Bereich von Morgan auf dem internen GitLab der UHA 4.0, nicht öffentlich.
- Demo: keine, die Erweiterung setzt gültige Zugangsdaten der UHA 4.0 voraus.
- Dokumentation: Installations-README und technisches Changelog Datei für Datei, im Repository aufbewahrt.

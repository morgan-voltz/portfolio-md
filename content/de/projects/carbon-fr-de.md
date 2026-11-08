---
title: "carbon-fr"
slug: "carbon-fr-de"
type: "project"
lang: "de"
translation_key: "carbon-fr"
date: 2026-10-26
subtitle: "Eine API, die die CO₂-Intensität des französischen Stroms misst, ohne die Debatten zu entscheiden, zu denen sie die Faktengrundlage liefert"
seo_description: "carbon-fr ist eine Open-Source-API in Rust, die die CO₂-Intensität des französischen Stroms misst und auf data.gouv.fr als Nachnutzung referenziert ist."
featured: true
status: "stable"
role: "Produktkonzeption, Architektur, Backend- und Frontend-Entwicklung, Infrastruktur und Governance, allein"
period_start: 2026-06-14
period_end: ""
repo_url: "https://github.com/Kovelt/carbon-fr"
demo_url: "https://carbon-fr.kovelt.fr/dashboard"
client: ""
is_fil_rouge: false
tags: [rust, postgresql, architecture-hexagonale, typescript, axum, machine-learning, open-data, docker, self-hosted, openapi]
skills: ["4.0.1/FR-09", "4.0.2/E09"]
---

carbon-fr ist eine quelloffene Civic-Tech-API, die die CO₂-Intensität des französischen Stroms in Echtzeit bereitstellt, national und für die zwölf Regionen, mit einem öffentlichen Dashboard. Sie ist das französische Gegenstück zum britischen carbonintensity.org.uk, einem Dienst, den es in Frankreich nicht gab, obwohl die nötigen offenen Daten — RTE, ADEME, ENTSO-E — dort zu den besten Europas gehören. Doppellizenz MIT / Apache-2.0, als Nachnutzung auf data.gouv.fr referenziert.

Am 26. Oktober 2026 steht das Projekt bei Version 0.11.0 und ist stabil: 30 dokumentierte Endpoints, ein öffentliches Dashboard, ein veröffentlichtes TypeScript-SDK. Ich konzipiere, entwickle und hoste es allein und baue es laufend weiter aus.

## Das Problem

Das Vereinigte Königreich hat seit mehreren Jahren carbonintensity.org.uk, eine nationale API für die CO₂-Intensität, die zur Referenzinfrastruktur des *Carbon-aware Computing* geworden ist: Berechnungen in die Stunden verschieben, in denen der Strom am wenigsten CO₂ verursacht. Frankreich hatte nichts Vergleichbares. RTE veröffentlicht mit éCO2mix über die Plattform ODRÉ hervorragende Rohdaten — aber keine stabile, dokumentierte, regionalisierte Civic-Tech-API machte sie für Entwickler oder Integratoren nutzbar.

Die Anwendungsfälle dagegen häuften sich, ohne dass jemand sie bediente. Die Bewegung für eine verantwortungsvolle Digitalisierung (Boavizta, GreenIT.fr) will ihre Berechnungen auf CO₂-arme Zeitfenster legen. Die Wasserstoffbranche muss Stunde für Stunde wissen, ob der Netzstrom einen Elektrolyseur für die Regelwerke zu RFNBO oder zu kohlenstoffarmem Wasserstoff geeignet macht. Und allgemein konnte niemand einfach auf die Aufschlüsselung des Strompreises oder auf sauber belegte Referenzwerte für die Produktionskosten zugreifen.

Das Problem war also nicht das Fehlen von Daten, sondern das Fehlen eines Instruments: Daten, die sich auf mehrere Produzenten verteilen, im Lauf der Zeit revidiert werden, methodisch mehrdeutig sind — direkte Emissionen oder Ökobilanz? — und nie in einer Form bereitgestellt werden, die sich direkt nutzen lässt. Diese Lücke schliesst carbon-fr, mit einer Vorgabe, die vom ersten Commit an feststand: ein streng neutrales Messinstrument bleiben, das die Daten bereitstellt, ohne jemals die energiepolitischen Debatten zu entscheiden, zu denen sie die Faktengrundlage liefert.

## Die technischen Entscheidungen

**Die Architektur ist hexagonal, um die Berechnung des CO₂-Werts von Quellen zu isolieren, die sich ohne Vorwarnung ändern.** Die Domäne (`core`, `eligibility`) ist in reinen Typen ohne externe Abhängigkeit geschrieben; die Adapter (ODRÉ, ENTSO-E, Wetter, Prognose, GBDT, Postgres, axum, Webhooks) werden an Ports angeschlossen. Öffentliche Datenquellen ändern Format, Frequenz und Verfügbarkeit ohne Vorankündigung; die hexagonale Architektur macht jede Quelle austauschbar und einzeln testbar. Beleg: ein Cargo-Workspace mit elf Crates und einer einzigen Server-Binärdatei.

**Jeder Wert trägt einen `vintage`, weil RTE seine eigenen Zahlen revidiert.** Dieselbe Stunde durchläuft nacheinander drei Zustände — Echtzeit, konsolidiert, endgültig — mit jeweils anderen Werten; eine naive API würde je nach Zeitpunkt der Anfrage stillschweigend widersprüchliche Zahlen liefern. Die Ingestion beruht auf einem Upsert mit zusammengesetztem Schlüssel (Region, Timestamp, Methodik), ergänzt um ein Feld `vintage`. Direkte Folge: kein ORM, Persistenz in nativem SQL über sqlx (zur Kompilierzeit geprüfte Abfragen), um diese Upserts fein steuern zu können.

**Die regulatorischen Schwellenwerte sind Konfigurationsdaten, nie Konstanten im Code.** Die Kriterien für die Eignung (erneuerbarer Anteil ≥ 90 % nach Artikel 4, Überschussausnahme bei 20 €/MWh, Schwelle für kohlenstoffarm bei 64 g) stammen aus europäischen Rechtstexten, die sich weiterentwickeln; sie sind versioniert und parametrisiert statt fest im Code verankert, damit die API den Texten folgt, ohne neu geschrieben zu werden.

**Die rechtliche Granularität ist nicht die physische Granularität der Berechnung.** Die regulatorische Eignung wird auf der Ebene der nationalen Gebotszone beurteilt, während carbon-fr auf regionaler Ebene rechnet: Die API stellt deshalb nie eine Region als "geeignet" dar, als hätte das eine eigene regulatorische Bedeutung — es ist nur eine feinere Darstellungsebene als die, die rechtlich zählt.

**Keine Version des Prognosemodells geht in Produktion, ohne ein formales Backtest-Gate zu passieren.** RTE sagt die Zukunft nicht voraus: Ich modelliere die Prognose selbst, mit einem klimatologischen Referenzmodell und einem Gradient-Boosting-Modell (GBDT), das mit den Wetterdaten von Open-Meteo gespeist wird. Diese Prognose speist `/schedule` und das grünste Zeitfenster; sie ohne Kontrolle weiterzuentwickeln hiesse, Planungsentscheidungen auf ein verschlechtertes Modell zu stützen. Beleg: Backtest-Gates, festgeschrieben in ADR-0012 und ADR-0028.

**Neutralität wird durch ein adversariales Review überprüft, sie wird nicht behauptet.** Ich biete systematisch beide Lesarten an, statt anstelle der Nutzer zu entscheiden: zwei CO₂-Methodiken parallel (`rte-direct`, `acv-ademe`), zwei Regelwerke für die Eignung von Wasserstoff (RFNBO, kohlenstoffarm), LCOE als Spannen aus mehreren Quellen. Die sensibelsten Schichten — der LCOE-Vergleich (ADR-0024), die Eignungs-Overlays (ADR-0026) — haben je ein eigenes, versioniertes Neutralitäts-Review. Dieselbe Anforderung gilt für den Code: Die v0.7.0 ist ein Audit-Release aus einem vollständigen adversarialen Review des Workspace, neunzehn bestätigte Mängel wurden behoben. Und wenn die Daten unbequem sind — die Bretagne weist 329 g für die Erzeugung aus, während der nationale Wert bei 32 g liegt —, lautet die Antwort, sie zu dokumentieren, nicht zu glätten: Die Region erzeugt wenig, und was sie erzeugt, ist CO₂-intensiver, was nichts darüber sagt, was sie verbraucht (ADR-0008); die Verbrauchsreihe `acv-ademe@2`, bereinigt um den grenzüberschreitenden Austausch, vervollständigt das Bild (ADR-0010).

## Was ich daraus gelernt habe

Dass Neutralität keine Haltung ist, die man zur Schau stellt, sondern eine Ingenieursvorgabe, die man überprüft: einen Schwellenwert parametrisieren, ein Regelwerk in zwei Varianten führen, eine Formulierung von einem adversarialen Review prüfen lassen, das sind konkrete Handlungen, keine erklärten Absichten. Dass es besser ist, unbequeme Daten zu dokumentieren, als sie zu glätten: Der Fall der Bretagne hält, weil die Erklärung schriftlich vorliegt, bevor man sie braucht.

Ich habe ausserdem festgestellt, dass die Sorgfalt bei den Lizenzen der Quellen in einem Open-Source-Projekt, das man allein betreibt, eine unterschätzte Fähigkeit ist: prüfen, was man wiederverwenden darf, eine Quelle ausschliessen, deren Lizenz nicht passt, die Abhängigkeiten bei jedem CI-Lauf mit `cargo-deny` auditieren. Nichts davon ist in der fertigen API sichtbar, aber es ist einer der Gründe, warum das Projekt auf data.gouv.fr als Referenz zitierbar ist.

Und dass ein Projekt, das man allein betreibt, nicht dazu zwingt, die Disziplin eines Teams aufzugeben: Die unveränderlichen ADR und die adversarialen Reviews spielen die Rolle, die ein Peer-Review gespielt hätte. Das Audit-Release v0.7.0, neunzehn gefundene und behobene Mängel, ist der Beweis durch das Ergebnis, nicht nur durch die Absicht.

## Zahlen

- Version 0.11.0 (Release vom 20. Oktober 2026), stabil; v0.7.0 = Audit-Release, neunzehn bestätigte und behobene Mängel aus einem vollständigen adversarialen Review des Workspace
- 30 Endpoints unter `/v1`, in OpenAPI dokumentiert
- Backend in Rust (Edition 2024, tokio): Cargo-Workspace mit elf Crates und einer Server-Binärdatei, hexagonale Architektur
- Persistenz in PostgreSQL mit nativem SQL (sqlx), ohne ORM
- Geografische Abdeckung: national + 12 Regionen; 3 CO₂-Methodiken parallel bereitgestellt (`rte-direct`, `acv-ademe@1`, `acv-ademe@2`)
- 2 Regelwerke für die Eignung von Wasserstoff (RFNBO, kohlenstoffarm); 233 europäische Elektrolyseure kartiert (European Hydrogen Observatory)
- 35 ADR (Architecture Decision Records), die das Projekt steuern
- Doppellizenz MIT / Apache-2.0; veröffentlichtes TypeScript-SDK (`@carbon-fr/sdk`)
- Infrastruktur zu 100 % in der EU: API auf einem Hetzner-VPS hinter Traefik, Webauftritt bei o2switch (Frankreich); von der CI bei jedem Versions-Tag veröffentlichtes Docker-Image, selbst hostbar (daneben besteht ein gehostetes Angebot mit API-Schlüsseln)
- Beim INPI angemeldete Marke; Nachnutzung der Daten auf data.gouv.fr referenziert

## Links

- Repository: öffentlich auf GitHub (`github.com/Kovelt/carbon-fr`), Organisation Kovelt, Doppellizenz MIT / Apache-2.0.
- Demo: öffentliches Dashboard (`carbon-fr.kovelt.fr/dashboard`) mit interaktiver Karte, regionalen Ansichten und einem Widget für das grünste Zeitfenster.
- SDK: `@carbon-fr/sdk`, in TypeScript veröffentlicht.
- Dokumentation: OpenAPI-Referenz der API (`carbon-fr-api.kovelt.fr/docs`).
- Referenzierung: Nachnutzung auf data.gouv.fr aufgeführt.

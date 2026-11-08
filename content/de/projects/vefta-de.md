---
title: "Vefta"
slug: "vefta-de"
type: "project"
lang: "de"
translation_key: "vefta"
date: 2026-10-26
subtitle: "Eine persönliche Produktivitätssuite, durchgängig in Rust, die offline funktioniert und die Daten der Nutzer nie zu Geld macht"
seo_description: "Vefta ist eine Privacy-first- und Offline-first-Produktivitätssuite in Rust (Axum, Dioxus, PostgreSQL), die ich allein für Kovelt SAS entwickle."
featured: true
status: "en-cours"
role: "Gründer und einziger Entwickler, Kovelt SAS"
period_start: 2026-06-02
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, axum, dioxus, postgresql, webassembly, architecture-hexagonale, offline-first, privacy-first, ci-cd, sync]
skills: ["4.0.2/E09"]
---

Vefta ist die persönliche Produktivitätssuite, die ich für Kovelt SAS entwickle, das Unternehmen, das ich gegründet habe und in dem ich der einzige Entwickler bin. Bis Juli 2026 hiess sie Chronos. Die Ausgangsidee: ein einziges Werkzeug, das Kalender, Aufgaben, Kontakte, Notizen, E-Mail und Messenger einer Person verwaltet, ohne Verbindung funktioniert und dessen Daten nie die Kontrolle des Nutzers verlassen.

Der native Unterbau und die bidirektionale Synchronisation mit Google Kalender sind seit Juni 2026 ausgeliefert (MVP1). Die Mail-Zelle wird derzeit mit Alpha-Testern erprobt. Die vereinheitlichten Messenger-Dienste sind konzipiert und dokumentiert, aber noch nicht gebaut. Das professionelle Back-Office für Organisationen (Teams) folgt demselben Prinzip, doch seine Umsetzung bleibt ausdrücklich hinter einer messbaren wirtschaftlichen Reaktivierungsbedingung eingefroren: dass ein selbständiger Nutzer Vefta Google vorzieht und das Abonnement bezahlt. Die Embedded-KI folgt einer anderen Logik: Sie ist ein Grundlagenvorhaben, das bereits läuft, wobei das Fine-Tuning des kleinen lokalen Sprachmodells derzeit im Gang ist. Sie ist kostenlos, weil sie auf dem Gerät des Nutzers läuft, bei nahezu null Grenzkosten, anders als die Cloud-KI, die kostenpflichtig bleiben wird.

## Das Problem

Der Ausgangspunkt ist konkret: eine freiberufliche Grafikerin, die ständig fünf Tabs offen hat, nämlich Kalender, Mail, Notizen, einen Übersetzer und die Rechnungsstellung, und bei der kein Werkzeug etwas von den anderen weiss. Ihr Adressbuch kennt den Verlauf der Termine nicht, ihr Kalender kennt ihre Mails nicht, und alles liegt bei Anbietern, deren Geschäftsmodell auf der Auswertung ihrer Daten beruht.

Daraus ergeben sich drei konkrete Reibungspunkte. Die Fragmentierung: Dieselbe Information über dieselbe Person ist auf Silos verteilt, die nicht miteinander sprechen. Die Abhängigkeit: Die dominierenden Suiten erzwingen ihr Konto als Identität, machen den Ausstieg teuer und finanzieren ihr kostenloses Angebot mit gezielter Werbung. Die fehlende lokale Verankerung: Kein grosser Anbieter bildet Frankreich wirklich ab, mit Feiertagen je Zone, Schulferien je Académie, Steuerkalender und Behördenschaltern wie Ameli oder der CAF.

Vefta antwortet auf jeden dieser Reibungspunkte mit einer technischen statt einer kommerziellen Weichenstellung: eine einzige Anwendung statt einer Reihe abgeschotteter Werkzeuge, ein Client, der ohne die Cloud funktioniert, und exportierbare Daten statt eines Kontos, das einen festhält, sowie öffentliche französische Kalender, die nativ überlagert statt ignoriert werden.

## Die technischen Entscheidungen

**Durchgängig Rust, vom Server bis zu den drei Client-Zielen.** Das Backend ist in Axum mit PostgreSQL geschrieben; der Client in Dioxus kompiliert zu WebAssembly für das Web und zu nativen Binärdateien für Desktop und Mobilgeräte. Die Domain- und Application-Schichten bilden eine einzige Codebasis, die sich Server und die drei Client-Ziele teilen: Eine Geschäftsregel existiert nur einmal, nicht einmal pro Plattform. Dadurch kann ich die drei Ziele parallel bedienen, ohne die Arbeit zu verdreifachen, ein Punkt, der zählt, wenn man allein am Projekt arbeitet.

**Die hexagonale Abhängigkeitsregel wird von einem blockierenden CI-Gate geprüft, nicht nur von der Ordnerstruktur.** Die reine Domäne ist nach Schichten in Crates getrennt, und über das hinaus, was der Compiler ohnehin erzwingt, analysiert ein blockierendes CI-Gate den Graphen von `cargo metadata` und führt eigene Clippy-Lints aus, um jede Abhängigkeit abzulehnen, die die Pfeilrichtung verletzen würde. Bei einem Projekt, das ich allein und über lange Zeit führe, ist mir eine automatische Mauer lieber als eine Disziplin, an die ich mich selbst ständig erinnern müsste.

**Das Produkt ist in ausfallisolierte Zellen gegliedert statt in horizontale Schichten.** Cœur Suite, KI, Plugin-host und Mail sind vier unabhängige Makro-Einheiten, jede für sich testbar, und keine liest die Datenbank einer anderen: Jede Zelle definiert ihren eingeschränkten Betriebsmodus selbst. Das Versprechen «Wenn die KI ausfällt, läuft der Kalender weiter» ist eine Folge dieser Topologie, keine Absicht, die man bei jeder Änderung von Hand überprüfen müsste.

**Die Synchronisation zeigt Konflikte an, statt sie stillschweigend zu überschreiben.** Ein generischer Synchronisationskern, der auf ETags und auf externen Kennungen beruht, die von Synchronisationsverknüpfungen getragen werden, löst Konflikte nach dem Prinzip «Last Write Wins» auf, macht sie aber sichtbar, statt sie verschwinden zu lassen. Diese Schleife wurde zuerst vollständig am engsten denkbaren Fall bewiesen, der bidirektionalen Synchronisation mit Google Kalender, ausgeliefert als MVP1 im Juni 2026, bevor sie auf die anderen Quellen übertragen wurde.

**Die Infrastruktur ist selbst gehostet und containerisiert, statt an einen PaaS-Anbieter ausgelagert.** Das Repository läuft auf einem eigenen GitLab (git.kovelt.fr); die Produktion läuft auf Hetzner Cloud hinter einem Caddy-Reverse-Proxy, der das TLS automatisch verwaltet, in Docker-/Podman-Containern; die CI/CD läuft vorerst auf dem Entwicklungsrechner, mit einem Layer-Cache über cargo-chef, bis für die Beta ein eigener Build-VPS bereitsteht. Es ist dieselbe Logik wie bei den Daten der Nutzer: die Hosting-Kette von Anfang bis Ende kontrollieren, statt sie vollständig einem Dritten anzuvertrauen.

**Jede strukturelle Entscheidung wird in einem Journal festgehalten, das nie umgeschrieben wird.** Mehr als achtundvierzig ADR dokumentieren die Entscheidungen mit ihrem Kontext und ihren Alternativen, und eine Korrektur kommt immer als neuer, datierter Eintrag hinzu, statt den alten zu ersetzen. Über diesem Journal legt eine Verfassung (Constitution) die Identität des Produkts und eine Handvoll nicht verhandelbarer Ausschlüsse fest, darunter der, Google nie als Anmeldekennung aufzuzwingen. Die Umbenennung von Chronos in Vefta im Juli 2026 lief über denselben Kanal: Eine vollständige Recherche nach älteren Rechten ergab eine Beinahe-Homophonie mit Kronos, einem Anbieter von Workforce-Management, und zeigte zudem, dass der Name für eine Suite, die weit über den Kalender hinausgeht, zu eng geworden war. Der zugehörige ADR dokumentiert die Entscheidung, statt einfach ein Repository umzubenennen.

## Was ich daraus gelernt habe

Dass die Disziplin, die auf Dauer trägt, diejenige ist, die das Tooling erzwingt, nicht diejenige, die man sich selbst verspricht: Das CI-Gate für die Architektur und das unveränderliche ADR-Journal übernehmen als Einzelentwickler die Rolle, die in einem Team ein Peer-Review spielen würde. Dass ein dünner, durchgehender Faden (die ganze Kette am einfachsten Fall beweisen, bevor man sie erweitert) in der Korrektur weniger kostet als ein System, das in die Breite gebaut wurde, bevor es in die Tiefe bewiesen war: Deshalb wurde die Google-Synchronisation nur am Kalender bewiesen, bevor sie auf andere Bereiche übertragen wurde. Und dass das freiwillige Einfrieren der Umsetzung eines bereits konzipierten Vorhabens hinter einer messbaren Reaktivierungsbedingung, wie beim professionellen Back-Office Teams, Designschulden vermeidet, ohne darauf zu verzichten, es durchdacht zu haben.

## Zahlen

- Über 48 unveränderliche ADR (Architecture Decision Records)
- 4 unabhängige Makro-Zellen: Cœur Suite, KI, Plugin-host, Mail
- MVP1 im Juni 2026 ausgeliefert: nativer Unterbau + bidirektionale Google-Synchronisation
- Umbenennung Chronos → Vefta im Juli 2026 beschlossen, mit vollständiger Recherche nach älteren Rechten
- 2 ROCm-Toolchains für das Fine-Tuning des Embedded-SLM verglichen (Mistral 7B, QLoRA)

## Links

- Repository: privat, gehostet auf dem selbst gehosteten GitLab von Kovelt. Der Code ist beim Start proprietär; eine verzögerte Öffnung des Client-Codes ist vorbereitet, aber nicht ausgelöst.
- Demo: keine öffentliche Demonstration; das Produkt wird von einigen Nutzern in einer privaten Alpha getestet.

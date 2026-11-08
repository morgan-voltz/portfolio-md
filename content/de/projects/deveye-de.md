---
title: "DevEye"
slug: "deveye-de"
type: "project"
lang: "de"
translation_key: "deveye"
date: 2026-10-26
subtitle: "Ein MCP-Server in Rust, der einem KI-Agenten Augen und Hände auf einer laufenden grafischen Anwendung gibt"
seo_description: "DevEye, ein MCP-Server in Rust (41 Werkzeuge), gibt einem KI-Agenten Augen und Hände auf einer grafischen Anwendung, bis zur Eingabeinjektion unter Wayland."
featured: false
status: "en-cours"
role: "Konzeption, Entwicklung, Tests und Audits, im Alleingang"
period_start: 2026-06-01
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, mcp, architecture-hexagonale, wayland, kwin, cdp, dbus, dioxus, ia-agentique]
skills: ["4.0.2/E10"]
---

DevEye ist ein in Rust geschriebener MCP-Server (Model Context Protocol), der einem KI-Agenten – insbesondere Claude Code, aber jeder MCP-Client eignet sich – die Fähigkeit gibt, eine laufende grafische Anwendung zu beobachten und zu steuern: Bildschirmaufnahmen, Klicks, Eingaben, Inspektion des Anwendungszustands, Auslesen von Logs, Warten auf Bedingungen. Der Agent nutzt ihn wie jedes andere Werkzeug, ohne menschliches Eingreifen.

Das Projekt ist privat (persönliches GitLab-Repository Kovelt Utilities) und wird seit Juni 2026 entwickelt. Die Meilensteine M0 bis M7 sind seit Ende Juni geliefert, und die Entwicklung geht in Sprints zur Erweiterung der Werkzeuge weiter. Eine Veröffentlichung als Open Source ist für Version 1.0 vorgesehen, sobald das Werkzeug stabilisiert ist.

## Das Problem

Ein KI-Agent, der Code lesen und schreiben kann, bleibt vor einer grafischen Anwendung blind und ohne Hände: Er sieht nicht, was seine letzte Änderung auf dem Bildschirm bewirkt hat, kann keine Schaltfläche anklicken und kann nicht feststellen, dass eine Oberfläche kaputt ist. Für das Web gibt es bereits Brücken (Playwright und ferngesteuerte Browser). Für native Desktop-Anwendungen, insbesondere im Rust/Dioxus-Ökosystem, das ich für meine eigenen Produkte nutze, gab es nichts Vergleichbares.

Ich habe DevEye gebaut, um diese Lücke zu schliessen, als übergreifendes Werkzeug statt als Wegwerfskript für ein einzelnes Projekt: Es dient bereits als Werkzeugkasten für meine anderen Produkte, und sein Ziel ist nicht eine einzelne UI-Technologie, sondern alle, die ich nutze oder nutzen könnte.

Das daraus folgende technische Problem besteht darin, Arten der Oberflächensteuerung, die fast nichts gemeinsam haben, unter einer einzigen Abstraktion zusammenzubringen: das DevTools-Protokoll eines Chromium-Browsers, eine hauseigene HTTP-Brücke in der Ziel-App, die D-Bus-Bildschirmaufnahme eines Wayland-Compositors und, wenn es weder Browser noch Embedded-SDK gibt, die Eingabeinjektion auf der Ebene des Systems selbst – in einem Ökosystem, Wayland, das gerade dafür gemacht ist, diese Art von Injektion zu verhindern.

## Die technischen Entscheidungen

**Der Kern kennt keine Steuerungstechnologie, nur Ports.** `deveye-core` ist eine reine Domäne (`Snapshot`, `InteractionCommand`, `LogEvent`) ohne jede technische Abhängigkeit, und `deveye-ports` definiert zwölf Rust-Traits (`Renderer`, `AppInspector`, `InteractionSink`, `JsEvaluator`, `LogSource`, `CommandRouter`, `WaitCondition`, `WindowManager`, `Clipboard` …), die zehn Adapter jeweils für eine andere Technologie implementieren. Ein neues Ziel hinzuzufügen heisst, einen weiteren Adapter zu schreiben, nie den Kern oder die einundvierzig MCP-Werkzeuge anzufassen, die sich auf die Ports stützen: die hexagonale Architektur, an einem realen Fall erprobt und nicht nur als Prinzip aufgestellt.

**Vier Zielmodi wählen die Technologie, nie der Agent.** Der Modus `cdp` spricht mit dem Chrome DevTools Protocol für jede Chromium-WebView, `sdk` läuft über eine hauseigene HTTP-Brücke, die eine App mit einer einzigen Zeile einbindet, `kwin-eis` injiziert Eingaben auf Systemebene für Apps ohne WebView und ohne SDK (die «Augen» laufen dann über die Bildschirmaufnahme), und `auto` versucht zuerst `cdp` und wechselt von Aufruf zu Aufruf auf `sdk`, sobald der erste scheitert. Ich wollte nicht, dass der Agent, der DevEye steuert, wissen muss, welche Technologie auf der anderen Seite antwortet.

**Die Eingabeinjektion unter Wayland läuft über das offizielle KWin-EIS-Portal, in reinem Rust, ohne FFI.** Wayland ist so konzipiert, dass keine Anwendung Eingaben in eine andere injizieren kann: kein XWayland zum Umgehen, kein `uinput`, das man direkt öffnen könnte. Der einzige legitime Weg ist das Portal `org.kde.KWin.EIS.RemoteDesktop`, umgesetzt mit der Crate `reis`. Es wird über eine D-Bus-Verbindung ausgehandelt, die offen bleiben muss, sonst droht beim Handshake ein schlichtes EOF – eine Protokollfalle, die ich entdeckt habe, indem ich darüber gestolpert bin. Auf der Tastaturseite muss jedes Unicode-Keysym über `xkbcommon` zu einem evdev-Keycode aufgelöst werden, bevor es injiziert werden kann. Das ist der anspruchsvollste Teil des Projekts.

**Die Haltung als «Entwicklungswerkzeug» ist mehrfach abgesichert, nicht nur in einer README deklariert.** `deveye_sdk::init()` kompiliert ausserhalb von `debug_assertions` keinen DevEye-Code, selbst wenn die einbindende Person ihr `#[cfg]` vergisst; der Server bindet sich nur an Loopback (`127.0.0.1`, nie `0.0.0.0`) und weist vor jeder Route jeden `Host`-Header, der nicht lokal wäre, mit 403 ab; CORS ist auf deny-all gesetzt, und die exponierten Crates tragen `#[forbid(unsafe_code)]`. Die Warteschlange für die JS-Auswertung ist begrenzt und antwortet mit 503, statt den Speicher wachsen zu lassen, wenn die Ziel-App eingefroren ist, und der Log-Puffer ist gedeckelt, indem die ältesten Einträge überschrieben werden. Jeder Mechanismus verhindert eine ganz bestimmte Art von Leck in der Produktion, nicht ein allgemeines Risiko.

**Das Werkzeug wird mit derselben Strenge auditiert, die es auf den Code anderer anwendet.** Ein formales internes Audit, das ich nach dem Meilenstein M7 durchgeführt habe, ergab einhundertacht priorisierte Befunde, darunter blockierende Gates, gefolgt von einem zweiten Audit im Juli. Die CI auf meinem eigenen, selbst gehosteten GitLab-Runner führt `cargo fmt --check`, `clippy` mit `-D warnings` und die Tests des Workspace aus, dazu `cargo-deny` für die Prüfung der Abhängigkeiten und Lizenzen; die Tests, die eine echte Wayland-Sitzung brauchen, sind mit `#[ignore]` isoliert.

## Was ich daraus gelernt habe

Dass vier Steuerungstechnologien unter einer einzigen Abstraktion zusammenzubringen dazu zwingt, genau zu benennen, was ihnen gemeinsam ist – eine Darstellung, eine Eingabe, ein Warten –, statt sie hinter Sonderfällen zu stapeln. Dass die Warte-Primitive der wahre Kern der Zuverlässigkeit einer Agentenschleife sind: Ein KI-Agent reiht Aktionen viel schneller aneinander als ein Mensch, also musste ich die MCP-Aufrufe serialisieren und auf eine ausdrückliche Bedingung statt auf eine feste Frist warten, damit die Schleife hält. Und dass das Entwerfen für einen Agenten als Endnutzer eine eigene Disziplin ist: Die einundvierzig Werkzeuge haben präzise Verträge und strukturierte Rückgabewerte, die ich so entworfen habe, dass ein LLM sie versteht und aneinanderreiht, und nicht für die Lektüre durch einen Menschen – eine Form von UX, deren Nutzer kein Mensch ist.

## Zahlen

- 15 Crates, 71 Dateien, rund 13 500 Codezeilen
- 41 MCP-Werkzeuge in sechs Familien, auf 12 Ports und 10 Adaptern
- 4 Steuerungskanäle: CDP, Embedded-SDK-Brücke, KWin-Aufnahme, KWin-EIS-Injektion
- 65 Commits zwischen Juni und Juli 2026, Meilensteine M0 bis M7 am 25. Juni geliefert – Zahl dieser Phase, die Entwicklung geht seither in Sprints zur Erweiterung der Werkzeuge weiter
- 108 Befunde aus dem internen Audit nach dem Meilenstein M7, ein zweites Audit im Juli
- 3 Referenzdokumente (Nutzung, Architektur, Wire-Protokoll des SDK)

## Links

- Repository: persönliches, selbst gehostetes GitLab (Kovelt Utilities), privat. Eine Veröffentlichung als Open Source ist für Version 1.0 vorgesehen.

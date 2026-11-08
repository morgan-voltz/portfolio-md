---
title: "chronos-penpot-tools"
slug: "chronos-penpot-tools-de"
type: "project"
lang: "de"
translation_key: "chronos-penpot-tools"
date: 2026-10-26
subtitle: "Ein Penpot-Plugin, das aus einem Entwurf eine Dioxus-Komponente (Rust) macht, über einen nach WebAssembly kompilierten Konvertierungskern"
seo_description: "chronos-penpot-tools ist ein Penpot-Plugin, das aus einem Entwurf Dioxus-Komponenten (Rust) erzeugt, mit einem nach WebAssembly kompilierten Konvertierungskern."
featured: false
status: "prototype"
role: "Konzeption, Entwicklung und Dokumentation, allein"
period_start: 2026-02-01
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, webassembly, dioxus, penpot, typescript, vite, design-to-code, wasm-bindgen]
skills: []
---

chronos-penpot-tools ist ein Plugin für Penpot, die Open-Source-Alternative zu Figma, das aus einem im Editor ausgewählten Entwurf eine Dioxus-Komponente (RSX) in Rust erzeugt. Ich habe es gebaut, als Werkzeug für mein eigenes Produkt: Als ich die v0.1 schrieb, hiess dieses Produkt noch Chronos; im Juli 2026 wurde daraus Vefta. Das Plugin behält vorerst seinen ursprünglichen Namen, die Umbenennung steht noch aus.

Das Projekt ist privat und bleibt im Prototypstadium. Die v0.1, ausgeliefert im Februar 2026, deckt Formen, Text und flexible Layouts ab; eine versionierte Roadmap reicht bis zu einer v0.5. Die MIT-Lizenz ist von Anfang an gesetzt, doch die Veröffentlichung als Open Source für die Rust- und Dioxus-Community ist erst für die v1.0 vorgesehen.

## Das Problem

Dioxus ist ein plattformübergreifendes Rust-Framework für Benutzeroberflächen (Desktop, Web, Mobilgeräte), dessen Rendering-Syntax RSX heisst. Penpot ist ein Werkzeug für das Interface-Design, das offene Pendant zu Figma. Zwischen beiden gibt es keine Brücke: Der Weg von einem Penpot-Entwurf zu einer Dioxus-Komponente führt über Handarbeit, Bildschirm für Bildschirm: Man liest jede Ebene durch, um daraus Struktur und Stile abzuleiten.

Für Chronos, die plattformübergreifende Anwendung in Rust und Dioxus, die ich nebenher entwickle, kehrte diese manuelle Übersetzung bei jedem neuen Bildschirm wieder. Die Brücke habe ich selbst gebaut: ein Penpot-Plugin, das die Auswahl des Nutzers liest und die entsprechende Dioxus-RSX-Komponente erzeugt, bereit zum Einfügen in den Code. Ein «Figma-to-Code», aber für ein Open-Source-Ökosystem und eine Zielsprache, Rust, die kein Werkzeug dieser Art abdeckt.

Das eigentliche Problem besteht nicht darin, eine Penpot-JSON-Datei zu lesen, sondern von einem Ebenenbaum, der für einen visuellen Editor gedacht ist, zu Code zu gelangen, den eine Rust-Entwicklerin oder ein Rust-Entwickler gern unverändert behält, ohne die Übersetzung in einen Schritt einzusperren, der jede künftige Erweiterung auf andere Zielsprachen oder andere Arten der Gestaltung blockieren würde.

## Die technischen Entscheidungen

**Die gesamte Konvertierungslogik lebt in einem nach WebAssembly kompilierten Rust-Crate, nie im TypeScript des Plugins.** Das Plugin läuft im abgeschotteten iframe (Sandbox) von Penpot, doch die Übersetzung eines Ebenenbaums in eine Dioxus-Komponente ist ein eigenes Crate, `penpot-to-dioxus`, das aus dem TypeScript über `wasm-bindgen` aufgerufen wird. Für diese Aufteilung gibt es zwei Gründe: Die komplexe Logik bleibt in einer typisierten Sprache und ist unabhängig vom Browser mit `cargo test` testbar, und das Crate ist so angelegt, dass es ausserhalb des Plugins wiederverwendbar ist, als CLI oder direkt in den Werkzeugen von Chronos/Vefta. Das Crate wird als doppelter Typ gebaut, `cdylib` für das WebAssembly und `rlib` für die Tests, und wird mit `opt-level = "s"`, LTO und strip kompiliert, damit die WASM-Binärdatei schlank bleibt.

**Der Konverter geht über eine Zwischendarstellung, statt das Penpot-JSON direkt in RSX zu übersetzen.** Das Crate ist wie ein kleiner Compiler in sechs Module gegliedert: `parser.rs` deserialisiert das Penpot-JSON über serde in einen typisierten Baum `PenpotNode` (Füllungen, Konturen, Effekte, Flex- und Grid-Layouts, Text, Komponenteninformationen); `transform.rs` wandelt diesen Baum in die Zwischendarstellung `DioxusNode` um, mit Heuristiken wie der Wahl zwischen `h1` bis `h4`, `p` oder `span` je nach Schriftgrösse eines Texts; `rsx_builder.rs` erzeugt den endgültigen RSX-Code, eingerückt und lesbar; `styles.rs` übersetzt die visuellen Eigenschaften in CSS (Hexadezimalfarben zu rgba, Verläufe, Ausrichtungen, Grid-Spuren); `svg_extract.rs`, vorgesehen für die v0.4, wird die Formen erkennen, die als SVG exportiert werden sollen; `lib.rs` ist der Einstiegspunkt des Crates und stellt die Funktion `#[wasm_bindgen] fn convert(json, options)` bereit, die aus dem TypeScript aufgerufen wird. Der Weg über diese Zwischendarstellung statt über eine direkte Übersetzung macht den Generator für neue Zielsprachen und neue Stilmodi erweiterbar und Modul für Modul testbar.

**Das TypeScript-Plugin ist in zwei Welten geteilt, die nur über Nachrichten miteinander sprechen.** Die Plugin-API von Penpot verlangt diese Trennung: `plugin.ts` ist die einzige Datei mit Zugriff auf die `penpot`-API, sie liest die Auswahl und serialisiert den Design-Baum; `main.ts` ist die Oberfläche des iframes, die das WebAssembly-Modul initialisiert und die Vorschau des erzeugten Codes mit Kopieren und Herunterladen anzeigt; `wasm-bridge.ts` lädt dieses Modul asynchron. Diese Grenze beschränkt die Fläche, die das echte Dokument der Nutzerin oder des Nutzers berührt, und das Plugin verlangt nur die minimale Berechtigung `content:read`: Es ist absichtlich schreibgeschützt und kann am Quelldesign nichts verändern.

**Die Spezifikation geht dem Code voraus: Eine Zuordnungstabelle und ein Negativumfang wurden vor der ersten Zeile Rust geschrieben.** `MAPPING.md` legt die Philosophie der Konvertierung fest (zuerst visuelle Treue, idiomatischer und von Hand änderbarer Code, kein roher HTML-Dump, semantische Benennung, abgeleitet von den Ebenen), und eine versionierte Roadmap reicht von der v0.1 (Formen, Text, Flex) bis zur v0.5 (CSS-Klassen und Mustererkennung), vorbei an den Penpot-Komponenten, den typisierten Props, den Grids, Verläufen und Schatten und schliesslich dem SVG-Export. Der Negativumfang ist ausdrücklich festgehalten: keine Erzeugung von Geschäftslogik, in der v1 kein automatisches Responsive-Verhalten. Die bekannten Grenzen sind als lokalisierte TODO im Code dokumentiert, mit Datei und Funktion, statt sie zu verbergen.

**Die MIT-Lizenz ist ab der v0.1 gesetzt, die Veröffentlichung als Open Source ist jedoch auf die v1.0 verschoben.** Das Werkzeug entstand aus einem internen Bedarf, nämlich Chronos/Vefta auszurüsten, und bleibt ein privates Repository auf meinem persönlichen, selbst gehosteten GitLab, solange der Generator seine vollständige Roadmap nicht erreicht hat. Die Lizenz wird von Anfang an gewählt, um später nicht mehr darauf zurückkommen zu müssen, doch ich liefere der Rust-/Dioxus-Community lieber ein Werkzeug, das seine Versprechen hält, als ein halb fertiges Plugin, das schon einen öffentlichen Namen trägt.

## Was ich daraus gelernt habe

Dass es mir erspart, die Übersetzungslogik und die WebAssembly-Anbindung gleichzeitig zu debuggen, wenn ich das Konvertierungs-Crate als reines Rust-Modul anlege, das sich mit `cargo test` ohne Browser und ohne iframe prüfen lässt. Wenn ein Test von `transform.rs` fehlschlägt, weiss ich bereits, dass es sich nicht um ein Problem beim Laden des WASM oder bei `postMessage` handelt. Dass der Schritt, den man aus Ungeduld am leichtesten auslässt, zugleich der ist, dessen Auslassen am teuersten wird: `MAPPING.md` und sein Negativumfang, geschrieben vor der ersten Zeile Rust, setzen den Anspruch an idiomatisches RSX und die ausdrückliche Ablehnung des rohen HTML-Dumps von Anfang an als Prinzip des Projekts, nicht als Einschränkung, die man beim Schreiben des Codes erst wiederentdecken müsste. Dass die Wahl einer Zwischendarstellung statt einer direkten Übersetzung einen unmittelbaren Preis hat (sechs Module statt einer einzigen Datei, die alles erledigt), dieser Preis aber verschwindet, sobald man eine Zielsprache oder einen Stilmodus hinzufügen will, was die v0.4 und die v0.5 tun werden, ohne den bereits geschriebenen Kern anzutasten. Und dass mich das Bauen des Werkzeugs, das mein eigenes Produkt braucht, statt mich mit bestehenden Werkzeugen zu begnügen, auf dem anspruchsvollsten Weg an Rust und WebAssembly herangeführt hat: Ich musste eine vollständige Build-Kette verstehen, von `wasm-pack` bis zu zwei getrennten Vite-Einstiegspunkten, bevor sie unsichtbar wird.

## Zahlen

- Rust-Crate `penpot-to-dioxus`: rund 1 400 Zeilen, 6 Module, nach WebAssembly kompiliert
- TypeScript-Plugin: rund 500 Zeilen, mit Vite als Build-Werkzeug
- 4 Konzeptdokumente, darunter `MAPPING.md`
- v0.1 (MVP) im Februar 2026 ausgeliefert; versionierte Roadmap bis zur v0.5
- MIT-Lizenz ab der v0.1 gesetzt

## Links

- Repository: privat, gehostet auf meinem persönlichen, selbst gehosteten GitLab (Kovelt-Infrastruktur). Veröffentlichung als Open Source unter der MIT-Lizenz ist für die v1.0 vorgesehen, für die Rust- und Dioxus-Community.
- Demo: derzeit keine öffentliche Demonstration.

---
title: "xvii"
slug: "xvii-de"
type: "project"
lang: "de"
translation_key: "xvii"
date: 2026-10-26
subtitle: "Ein Audiostudio mit sechzehn Spuren in Rust, dessen Echtzeit-Thread weder Speicher anfordern noch warten darf"
seo_description: "XVII ist der Prototyp eines Audiostudios mit sechzehn Spuren in Rust und Dioxus: Echtzeit-Thread ohne Allokation und Sperre, Architektur vor dem Code."
featured: false
status: "prototype"
role: "Konzeption der Architektur und Prototyping, allein"
period_start: 2026-07-18
period_end: 2026-07-18
repo_url: "https://github.com/morgan-voltz/xvii"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, dioxus, cpal, symphonia, rubato, audio-temps-reel, dsp, mcp]
skills: []
---

XVII ist ein Audiostudio mit sechzehn Spuren in Rust und Dioxus, gedacht, um meine Metal-Stücke zu annotieren: eine Struktur setzen (Strophe, Riff A, Breakdown, Solo), ein Tempo festlegen, das sich im Lauf des Stücks ändert, und Liedtexte Zeile für Zeile und danach Wort für Wort synchronisieren. Der Name geht auf die römische Zahl siebzehn zurück: auf die siebzehnte Spur, derjenigen für den Text, die zu den sechzehn Audiospuren hinzukommt.

Am 26. Oktober 2026 ist nur Phase 1 von 6 der Roadmap ausgeliefert: eine als Argument übergebene Audiodatei in einem minimalen Fenster abspielen, mit einstellbarer Gesamtlautstärke. Die vollständige Architektur dagegen wurde schriftlich festgelegt, bevor die erste Codezeile entstand.

## Das Problem

Meine Stücke folgen keiner braven Strophe-Refrain-Struktur: Blastbeats, Half-Time und Double-Time, Breaks, die nie identisch wiederkehren. Die gängigen automatischen Annotationswerkzeuge setzen eine regelmässige Struktur voraus und irren sich bei dieser Art von Musik, oder schlimmer, sie zwingen ihre Lesart auf, ohne es zu sagen. Ich wollte ein Werkzeug, das eine Analyse vorschlägt, wenn es kann, mir aber nie die Kontrolle entzieht: Einen Abschnitt setzen, ein Tempo korrigieren, eine Zeile neu synchronisieren muss immer möglich bleiben, unabhängig von der Qualität der automatischen Erkennung.

Das zweite Problem ist persönlich. Ich bin sicher in Rust, aber ein völliger Anfänger in der Signalverarbeitung. XVII ist ebenso sehr ein Werkzeug, das ich nutzen will, wie ein Projekt, um Echtzeit-Audioprogrammierung und die Grundlagen der DSP zu lernen (FFT, Transientenerkennung, Temposchätzung), bevor ich mich an den Teil wage, der mich am meisten interessiert.

Das eigentliche Architekturproblem lautet daher: Wie baut man eine Audio-Engine, deren Echtzeitbetrieb keine Pause duldet, und lässt zugleich eine Benutzeroberfläche für Menschen und morgen einen KI-Assistenten dieselbe Anwendung steuern, ohne die Validierungsregeln je zu duplizieren?

## Die technischen Entscheidungen

**Das Modell kennt weder Audio noch Oberfläche.** Das Crate `xvii-model` hängt nur von `serde` ab: Cargo, nicht eine blosse Konvention, verbietet es, dort einen Audio- oder UI-Typ einzuführen. Darüber kennen `xvii-engine` und `xvii-decode` nur das Modell; `xvii-app` (Dioxus) und `xvii-mcp` (der künftige Server für einen KI-Assistenten) sind die beiden einzigen Zusammenführungspunkte, und sie kennen einander nicht. Beide gehen durch dieselbe Tür, `EngineHandle`, wo eine Verstärkung auf ×1,5 und ein Seek auf 2^60 Frames begrenzt wird: Die Validierung existiert genau einmal, nicht einmal pro Verbraucher.

**Der Audio-Thread befolgt sechs nicht verhandelbare Regeln, ohne Ausnahme.** Der `cpal`-Callback darf nie Speicher anfordern oder freigeben, nie eine Sperre nehmen, nie Ein- oder Ausgabe ausführen und muss in begrenzter Zeit laufen; jede Kommunikation läuft über eine lock-freie Warteschlange (`rtrb`, ein Produzent, ein Konsument) und Atomics. Die subtilste Regel: nie ein `Arc` auf diesem Thread freigeben. Wird eine Spur ersetzt, schickt der Audio-Thread das alte Exemplar in eine Rückgabewarteschlange, die die Oberfläche in aller Ruhe leert, mit einem letzten Ausweg, falls sie überläuft: lieber Speicher lecken lassen (`mem::forget`), als die Regel zu verletzen. `xvii-engine/src/rt.rs` (486 Zeilen) bündelt diesen Code, abgedeckt durch 12 Tests, die ohne Soundkarte laufen, denn CI-Maschinen haben keine.

**Eine Lautstärkeänderung darf nie abrupt sein.** Eine sofort angewandte Verstärkung erzeugt ein hörbares Knacken (das «Zipper Noise»): Die Wellenform macht eine Treppenstufe. Die Verstärkungen von Spur, Mute und Master gleiten deshalb über einen exponentiellen Einpol-Filter mit einer Zeitkonstante von etwa 5 ms zu ihrem Zielwert, kurz genug, um zu reagieren, lang genug, damit kein Ohr es hört.

**Alle Analysedaten müssen von Hand veränderbar bleiben, mit Herkunft und Konfidenz.** Das ist die Entscheidung D11: Nichts, was das künftige Analysemodul erzeugt, wird endgültig sein; ein erkannter Abschnitt trägt eine Konfidenz und kann von einem von Hand gesetzten Abschnitt überschrieben werden, der immer massgebend ist. Das ist keine abstrakte Vorsichtsmassnahme: Bei einer Passage mit verzerrter Stimme (Growl, Scream) verliert eine Spracherkennung die Phoneme, und ihre Konfidenz bricht ein. Das Oberflächen-Briefing sieht deshalb schon einen visuellen Zustand des «sichtbaren Verzichts» vor, statt ein Ergebnis zu erfinden. Mir ist ein Werkzeug lieber, das sagt, dass es etwas nicht weiss, als eines, das höflich lügt.

**Eine kritische Durchsicht des Codes fand fünf echte Fehler, bevor sie hörbar wurden.** Ein extremer Seek löste in der Arithmetik des Abspielkopfs eine Panic aus; ein Gerät, das bis zu 34 Kanäle meldet (Modus «Pro Audio»), konnte als Standard gewählt werden, obwohl der Mixer nur zwei speist; ein schnelles Doppeltippen auf Wiedergabe/Pause konnte den zweiten Druck verschlucken; die Wiedergabe pausierte am Dateiende nicht; ein Gerätefehler wurde der Oberfläche nie gemeldet. Alle fünf wurden im selben Commit behoben, die Seek-Grenze auf einen einzigen Validierungspunkt zurückgeführt.

## Was ich daraus gelernt habe

Dass das Dokumentieren der Architektur vor dem ersten Code dazu zwingt, Fragen zu entscheiden (welche interne Zeiteinheit, wer was verändern darf), die man sonst auf später verschiebt, wenn sie am teuersten zu korrigieren sind. Dass Audio-Echtzeit eine eigene Disziplin ist: Die meisten Reflexe «normaler» Programmierung (Speicher anfordern, sperren, Ein- oder Ausgabe ausführen) sind dort verboten, und man braucht ein anderes Vokabular (lock-freie Warteschlangen, Atomics, Rückgabewarteschlange), um ohne sie auszukommen. Und dass man sich mit einer Regel wie D11 schon beim Entwurf erspart, im Nachhinein zu entdecken, dass die automatische Erkennung bei meiner eigenen Musik scheitern wird.

Was ich anders machen würde: das Projekt nicht gleich nach dem Gerüstbau mehrere Monate pausieren lassen und einen kürzeren vertikalen Schnitt anpeilen, der den DSP-Teil schon in den ersten Sitzungen erreicht, statt den ganzen Unterbau (Workspace, Dekodierung, minimales Fenster) auszuliefern, bevor ich mich daran mache. Gerade das war der Teil, der mich beim Start am meisten interessierte, und es ist der, den ich noch nicht begonnen habe.

## Zahlen

- 6 Crates in einem Cargo-Workspace (Resolver 3, Edition 2024)
- 2 284 Zeilen Rust in 21 Dateien, insgesamt 37 Dateien im Repository
- 23 Tests, davon 12 zum Echtzeit-Audio-Thread, ohne Soundkarte ausgeführt
- 994 Zeilen Dokumentation in 4 Dateien: die Architektur (684 Zeilen, 16 Abschnitte, 12 vor dem Code festgelegte Entscheidungen), das Oberflächen-Briefing (162 Zeilen), der Zusammenarbeitsvertrag (100 Zeilen), das README (48 Zeilen)
- CI mit GitHub Actions auf 3 Betriebssystemen (Ubuntu, Windows, macOS) ab dem ersten Commit
- 10 Commits, am 18. Juli 2026, in zwei Sitzungen
- 1 ausgelieferte Phase von den 6 der Roadmap

## Links

- Repository: [github.com/morgan-voltz/xvii](https://github.com/morgan-voltz/xvii), öffentlich.
- Keine Online-Demo und keine paketierte Version: Die aktuelle Nutzung ist `cargo run -p xvii-app -- morceau.mp3`, lokal.

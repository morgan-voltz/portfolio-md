---
title: "HEHEWATT"
slug: "hehewatt-de"
type: "project"
lang: "de"
translation_key: "hehewatt"
date: 2026-10-26
subtitle: "Eine Fahrgemeinschafts-App für die Schule, die von mehreren Studienjahrgängen übernommen und weiterentwickelt wurde"
seo_description: "HEHEWATT ist eine Fahrgemeinschafts-App für das Lycée Henner in Altkirch (E-mersion, UHA 4.0): PHP/MySQL, OpenStreetMap, Team mehrerer Jahrgänge."
featured: false
status: "en-cours"
role: "Full-Stack-Entwickler, einer der ersten vier Beitragenden eines Teams von 30 bis 50 Personen, je nach Jahrgang"
period_start: 2026-01-05
period_end: 2026-02-13
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [php, mysql, javascript, leaflet, openstreetmap, osrm, docker, twig, api-rest]
skills: ["4.0.1/PM-01", "4.0.1/PM-07", "4.0.1/PM-08", "4.0.1/FR-07", "4.0.1/FR-09", "4.0.1/FR-13", "4.0.1/FR-14", "4.0.1/FR-15"]
---

HEHEWATT ist eine Fahrgemeinschafts-Anwendung, entwickelt im Rahmen des Projekts E-mersion, das das Lycée Jean-Jacques Henner in Altkirch und UHA 4.0 verbindet und von Noria finanziert wird. Das Repository besteht seit Oktober 2024 und wurde von mehreren aufeinanderfolgenden Jahrgängen übernommen: mehr als tausend Commits, zwischen dreissig und fünfzig Beitragende. Ich bin Anfang 2026 als Full-Stack-Entwickler eingestiegen, am fachlichen Kern der Fahrten und am Benachrichtigungssystem, mit rund 93 Commits, als einer der ersten vier Beitragenden.

Meine Arbeit an HEHEWATT endet mit meinem ersten Studienjahr; das Projekt wird weiter von den folgenden Jahrgängen übernommen, was einen Teil meiner Entscheidungen geprägt hat, allen voran die Dokumentation, für jemanden, dem ich nie begegnen werde.

## Das Problem

Das Lycée Jean-Jacques Henner liegt im Sundgau, einer ländlichen Gegend im Haut-Rhin, die vom öffentlichen Verkehr schlecht erschlossen ist: Schulpersonal, Eltern und Schülerinnen und Schüler legen jeden Tag denselben Weg von zu Hause zur Schule mit dem Auto zurück, oft allein. HEHEWATT bringt diese Menschen zusammen: Die Fahrenden veröffentlichen einmalige oder wiederkehrende Fahrten mit Zwischenhalten, die Mitfahrenden reservieren einen Platz auf der Fahrt, die in der Nähe ihres Wohnorts vorbeiführt, und eine Administratorin oder ein Administrator auf Seiten der Schule gibt die Konten frei. Die Schule ist Partnerin des Projekts, nicht Kundin: keine Rechnungsstellung, sondern ein reales Bedürfnis, das für ihre Gemeinschaft zu erfüllen ist.

Das technische Problem, auf das ich gestossen bin, ist nicht das eines Projekts, das bei null beginnt, sondern das eines PHP-Repositorys ohne grosses Framework, in dem eine Datei einer Seite oder einem API-Endpunkt entspricht, von Dutzenden Personen sehr unterschiedlichen Niveaus über mehr als ein Jahr von Hand geschrieben. Es galt, tiefgreifende Funktionen hinzuzufügen, nämlich mehrere Halte mit Reservierung pro Teilstrecke und wiederkehrende Fahrten, die mit dem Schulkalender vereinbar sind, ohne etwas zu zerstören, wovon reale Nutzende bereits abhängen. Das Programm E-mersion hat den Anspruch, dass langfristig Gymnasiasten direkt aus ihrem Browser zum Code beitragen (in der Docker-Umgebung gibt es einen Web-IDE-Dienst, der vorerst auskommentiert ist): Die Arbeit zu dokumentieren und aufzuteilen ist keine Komfortfrage.

## Die technischen Entscheidungen

**Eine Reservierung belegt einen Platz pro Teilstrecke der Fahrt, nicht auf der gesamten Fahrt.** Eine Fahrt mit mehreren Halten verliert ihren Sinn, wenn eine mitfahrende Person, die am zweiten Halt einsteigt und am dritten aussteigt, einen Platz auf der ganzen Fahrt blockiert. Das Modell ordnet daher jeder Reservierung einen Einstiegs- und einen Ausstiegshalt zu, und die Verfügbarkeit wird Teilstrecke für Teilstrecke berechnet (`Stop::getAvailableSeats()`) statt global. Ich habe die Anzeige dieser Belegung pro Teilstrecke in der Karten-Komponente (Card) einer Fahrt ergänzt, die von den fünf betroffenen Bildschirmen gemeinsam genutzt wird, damit Fahrende auf einen Blick sehen, welche Teilstrecke voll ist.

**Die Wiederholung einer Fahrt folgt dem tatsächlichen Schulkalender, nicht einer blossen Wochenregel.** «Jeden Montag und Mittwoch» sagt in den Ferien nichts aus. Das Schema trennt eine wiederkehrende Fahrt (`trajet_recurrent`) von ihren Ausnahmen (`trip_exceptions`), mit derselben Logik auf der Reservierungsseite (`reservation_recurrente` / `reservation_exceptions`), und kreuzt das Ganze mit zwei Tabellen des Schulkalenders, `planning_annee_scolaire` und `planning_conges`, statt die Ferien fest im Code zu verdrahten. Auf der Reservierungsseite habe ich eine Eindeutigkeitsbeschränkung aufgehoben, damit sich Mitfahrende auf derselben wiederkehrenden Fahrt für das ganze Jahr oder punktuell eintragen können, und die daraus folgenden Abfragen mit Tagesliste geschrieben.

**Der Grossteil der transaktionalen E-Mails läuft über einen einzigen Dienst, der den gesamten Lebenszyklus einer Fahrt abdeckt.** Von rund fünfzehn Dateien, die eine E-Mail auslösen können, bündelt `NotificationService` (`api/services/`) etwa zwanzig Methoden und fragt die Datenbank mit PDO Prepared Statements ab, nicht mit zusammengesetzten Zeichenketten. Zwei Unebenheiten sind nicht bereinigt: Das Zurücksetzen des Passworts ruft `EmailService` direkt auf, ohne über ihn zu gehen, und eine zweite, damit nicht verwandte Klasse `NotificationService` für die In-App-Benachrichtigungen (`api/notification/`, nicht signiert) trägt denselben Namen im selben globalen Namensraum. Das ist der Code, bei dem ich mir am sichersten bin, dass er von mir stammt: Die Kopfzeilen von `NotificationService.php` und der E-Mail-Konfiguration tragen meinen Namen, anders als der Grossteil des übrigen Repositorys, der nicht namentlich zugeordnet ist.

**Die Geolokalisierung stützt sich ausschliesslich auf freie OpenStreetMap-Dienste, ohne kommerzielle API.** Mangels Budget für einen kostenpflichtigen Schlüssel: Nominatim geokodiert die Adressen in einem Gebiet, das auf das Haut-Rhin und die angrenzenden Départements (Bas-Rhin, Vosges, Territoire de Belfort, Schweiz, Deutschland) begrenzt ist, OSRM berechnet die Fahrzeiten zwischen den Halten, und Leaflet stellt alles auf einer Karte dar. Die eigentliche Arbeit bestand darin, zwei APIs mit unterschiedlichen Einschränkungen zusammenzuführen: Nominatim begrenzt auf eine Anfrage pro Sekunde (Debounce, Cache in `autocomplete.js`), und OSRM erwartet seine Koordinaten als `longitude,latitude`, also in umgekehrter Reihenfolge, eine Vertauschung, die ich in `route-calculator.js` dokumentiert habe, nachdem ich sie beim stundenlangen Debuggen falscher Ankunftszeiten gefunden hatte.

**Eine versionierte API v1 kommt zu den bestehenden Endpunkten hinzu, statt sie zu ersetzen.** In einem Repository, in dem Dutzende Personen die alten Endpunkte bereits aufrufen, hätte ein Umschreiben auf einen Schlag die Arbeit aller zerstört. Ich habe die CRUD-Endpunkte dieser API v1 erstellt, nach Ressource gruppiert: Fahrten (4), Reservierungen (6), Halte (5). Sie können mit reinem JSON antworten oder mit vorgerenderten HTML-Fragmenten für die Seiten, die bereits AJAX nutzen, ohne den Bestand anzutasten. Die beiden APIs existieren bis heute nebeneinander.

## Was ich daraus gelernt habe

Dieses Projekt hat mir vor allem gezeigt, was «im Team arbeiten» bedeutet, wenn das Team alle paar Wochen ein anderes Gesicht hat. Eine gut gemachte Funktion, die in einem Stück geliefert wird, hilft niemand anderem, wenn sich niemand daran anschliessen kann: Ich habe gelernt, meine Arbeit in Stücke zu schneiden, die klein genug sind, dass eine beitragende Person, die die Geschichte des Projekts nicht kennt, eines davon übernehmen kann, ohne alles lesen zu müssen.

Ausserdem musste ich einen Reflex korrigieren: Wenn ein Teil des Teams wenig aktiv war, neigte ich dazu, die Lücke mit eigener Arbeit zu füllen, mit massiver Unterstützung durch KI-Werkzeuge, statt den Umfang anzupassen oder die Aufgabenverteilung neu anzustossen. Das Ergebnis: Zu viele gleichzeitige Änderungen machen die Arbeit schwer nachvollziehbar, und wer alles aufsaugt, trägt die Verantwortung allein, und das ist für niemanden gesund.

Die konkreteste Lehre betrifft tiefgreifende Änderungen: Eine Konfigurationsdatei zu ändern, die von der Umgebung des ganzen Teams und von den Servern der Schule gemeinsam genutzt wird, ohne es zu melden oder freigeben zu lassen, kann die Arbeit von Dutzenden Personen zerstören, die nichts verlangt haben. Ich kommuniziere solche Änderungen inzwischen, bevor ich sie zusammenführe, nicht nur in der Commit-Nachricht.

Was ich anders machen würde: die Zwischenbesprechungen systematisch vorbereiten, auch für weniger sichtbare Zielgruppen als einen Kunden, nämlich die Gymnasiasten, die das Projekt übernehmen werden, und kleinere, regelmässigere Lieferungen anstreben statt beeindruckender, aber für das Team störender Fortschritte.

## Zahlen

- 1 058 Commits im gesamten Repository, davon rund 93 von mir, als einer der ersten vier Beitragenden
- Zwischen 30 und 50 Beitragende je nach Jahrgang, über 16 Monate (Oktober 2024 bis Februar 2026)
- 262 Dateien ohne Abhängigkeiten: rund 26 000 Zeilen PHP (144 Dateien), 13 500 Zeilen JS, 4 700 Zeilen CSS, 1 000 Zeilen Twig
- 11 Tabellen in der Datenbank, 21 Twig-E-Mail-Vorlagen, eine API v1 mit 15 Endpunkten (4 Fahrten + 6 Reservierungen + 5 Halte)
- Entwicklungsumgebung mit Docker Compose: 4 aktive Dienste (PHP/Apache mit Xdebug, MariaDB, phpMyAdmin, Mailpit), dazu ein Web-IDE-Dienst für die Gymnasiasten, vollständig auskommentiert

## Links

- Repository: internes GitLab der UHA 4.0, nicht öffentlich. Es handelt sich um ein Schulprojekt im Rahmen einer Vereinbarung mit einer Partnerschule; eine öffentliche Lesekopie wurde nicht veröffentlicht.
- Technische Dokumentation: Ich habe die Dokumentation zu API, E-Mails und Geolokalisierung sowie das README im Repository (nicht öffentlich) verfasst oder aktualisiert.
- Lycée Jean-Jacques Henner in Altkirch, Partner des von Noria finanzierten Projekts E-mersion.

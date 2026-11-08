---
title: "UHA Learn"
slug: "uha-learn-de"
type: "project"
lang: "de"
translation_key: "uha-learn"
date: 2026-10-26
subtitle: "Kurskatalog und Fortschrittsverfolgung für UHA 4.0, mit einer ohne Framework geschriebenen PHP-REST-API"
seo_description: "UHA Learn, Leitprojekt UHA 4.0.1: Kurskatalog und Fortschrittsverfolgung, REST-API in PHP 8.2 ohne Framework, JWT, MySQL, Deployment für mehrere Distributionen."
featured: false
status: "archive"
role: "Konzeption, Entwicklung, Deployment und Dokumentation, allein"
period_start: 2025-09-01
period_end: 2025-12-01
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [php, react, mysql, redis, docker, jwt, rest-api, mvc, bootstrap, vite]
skills: ["4.0.1/FR-03", "4.0.1/FR-04", "4.0.1/FR-06", "4.0.1/FR-08", "4.0.1/FR-09", "4.0.1/FR-10", "4.0.1/FR-11", "4.0.1/FR-13", "4.0.1/FR-15", "4.0.1/FR-16"]
---

UHA Learn ist mein Leitprojekt (roter Faden) im ersten Jahr des Studiengangs UHA 4.0.1, vor MomoCad, meinem Leitprojekt im zweiten Jahr. Die Aufgabenstellung verlangt, allein eine vollständige Website rund um Daten Dritter zu bauen, von HTML und CSS bis zu einer selbst geschriebenen PHP-API, mit Git, einer MySQL-Datenbank und einem Deployment-Skript.

Das Projekt wurde im Dezember 2025 abgegeben und verteidigt. Seitdem wird es nicht mehr gepflegt: Es ist ein abgeschlossenes Dossier und mein erstes Projekt, das ich allein von Anfang bis Ende umgesetzt habe.

## Das Problem

Die Übung des Leitprojekts 4.0.1 ist als Progression angelegt: von einem HTML- und CSS-Mockup ausgehen, die Daten einer Drittanbieter-API lesen und dann dynamisch darstellen, eine relationale Datenbank und eine eigene PHP-API aufbauen, mit CRUD, Sicherheit und Dokumentation auf jeder Stufe.

Statt ein Thema zu wählen, das mit meinem Alltag nichts zu tun hat, habe ich ein Werkzeug für meine eigene Ausbildung gebaut. UHA 4.0 veröffentlicht eine institutionelle API, `filrouge.uha4point0.fr`, die die Jahre des Studiengangs (von 4.0.0 bis 4.0.3) und ihre Kurse auflistet. Um diese Quelle herum habe ich UHA Learn gebaut: einen nach Jahr abrufbaren Kurskatalog, eine persönliche Fortschrittsverfolgung, eine moderierte Warteschlange für Kursvorschläge und ein Admin-Panel.

Das eigentliche Problem bestand darin, diesen ganzen Stack allein zu stemmen, ohne zu schludern, besonders im Backend: die REST-API in PHP ohne Framework zu schreiben, um zu verstehen, was ein Framework sonst an meiner Stelle erledigt, und ein Deployment-Skript zu liefern, das wirklich auch anderswo als auf meinem Rechner funktioniert und nicht nur vor der Jury.

## Die technischen Entscheidungen

**Das REST-Backend ist in PHP ohne Framework geschrieben, mit einer von Hand aufgesetzten MVC-Architektur.** Controllers, Models, Middleware (CORS, JWT-Authentifizierung), Routes und Utils bilden die fünf Ordner des Backends, mit neunzehn PHP-Dateien und etwa viertausendvierhundert Zeilen. Das Ziel war nicht, ein Framework aus Prinzip zu meiden, sondern das Routing, das normalisierte Antwortformat und den Datenbankzugriff zu verstehen, die ein Framework sonst verbirgt, bevor ich beurteilen kann, was es mir wirklich bringen würde.

**Die Authentifizierung kombiniert JWT-Access- und Refresh-Tokens, drei Rollen und Kontostatus.** Studierende, Moderatoren und Administratoren haben nicht dieselben Rechte, und ein Konto kann aktiv, gesperrt oder diplomiert sein. Jeder der etwa fünfunddreissig Endpunkte der API ist im README mit der erforderlichen Zugriffsstufe (öffentlich, authentifiziert, Moderator oder Administrator) dokumentiert: Eine Endpunktliste ohne diese Spalte sagt nichts über die tatsächliche Sicherheit aus.

**Die Seed-Skripte sind ein Betriebswerkzeug, kein Wegwerfskript.** `seed_all.php` akzeptiert `--api-only`, um die echten Jahre und Kurse aus der institutionellen API zu importieren, `--test-only`, um einen Satz Testkonten zu erzeugen (sieben Studierende mit unterschiedlichen Status, drei Moderatoren, ein Administrator), und `--clean`, um alles zu entfernen. Das Admin-Panel stellt dieselbe Logik hinter einer gesicherten API bereit: Datenbankstatus, Start der Skripte, Bereinigung, Zurücksetzen. Eine Datenbank, die sich nur von Hand wiederaufbauen lässt, ist eine Datenbank, die man irgendwann nicht mehr anzufassen wagt.

**Das Deployment-Skript passt sich der Linux-Distribution an, statt meine aufzuzwingen.** `deploy.sh` erkennt den Paketmanager (apt, dnf, yum, pacman, zypper), konfiguriert SELinux auf Fedora und RHEL, vermeidet Portkonflikte, migriert die Datenbank und befüllt sie per Seed, mit den Modi `--auto`, `--update` und `--uninstall`. Mit einem einzigen Befehl erkennt und installiert `install.sh` Docker und Git, klont dann das Projekt und deployt es. Über die blosse Vorgabe eines Bash-Skripts hinauszugehen, hatte einen genauen Zweck: dass ein Kommilitone mit einer anderen Distribution dasselbe Ergebnis erhält, ohne eine Zeile des Skripts zu lesen.

**Der Katalog ist dafür gedacht, durchsucht zu werden, nicht nur aufgelistet.** Die erweiterte Suche umfasst Titel, Kategorie, Jahr und benötigte Werkzeuge, und der Katalog ist paginiert. Bei vier Ausbildungsjahren und mehreren Kursen pro Jahr wäre eine einzige Liste ohne Filter und Pagination schon im zweiten Inhaltsjahr unlesbar geblieben.

## Was ich daraus gelernt habe

Das MVC von Hand zu schreiben hat mir beigebracht, was ein PHP-Framework tatsächlich tut: eine Anfrage routen, eine Antwort vereinheitlichen, einen Zugriff per Middleware schützen. Diesen Massstab behalte ich seither bei, wenn ich ein Framework bewerte, um zu wissen, was es mir wirklich erspart, und nicht, was es mir verbirgt.

Es ist auch mein erstes Projekt, das ich allein von Anfang bis Ende geführt habe, von der ersten Codezeile bis zum Deployment-Skript, vor der Teamarbeit am von HEHEWATT geerbten Code und der Beziehung zum Auftraggeber bei AUTOFIN Control. Das Industrialisieren und Dokumentieren verlangte kein Kästchen der Aufgabenstellung: Ich habe es getan, weil ein Projekt, das sich nur installieren lässt, wenn man jeden Schritt mündlich erklärt, nicht wirklich fertig ist, und weil ein Authentifizierungsleitfaden, mit Analogien für einen Einsteiger geschrieben, den anderen Studierenden des Jahrgangs nützen sollte und nicht nur mir.

## In Zahlen

- September bis Dezember 2025, 96 Commits
- Backend: 19 PHP-Dateien, etwa 4 400 Zeilen
- Frontend: 34 JS/JSX/CSS-Dateien, etwa 7 400 Zeilen
- Etwa 35 REST-Endpunkte, verteilt auf 7 Bereiche (Authentifizierung, Kurse, Fortschritt, Jahre, Kategorien, Administration, Seeds)
- 3 Rollen, 3 Kontostatus (aktiv, gesperrt, diplomiert)
- 5 Docker-Compose-Dienste (PHP, MySQL, Redis, phpMyAdmin, Mailpit), Frontend gebaut und von Nginx ausgeliefert
- Per Seed erzeugter Testdatensatz: 7 Studierende, 3 Moderatoren, 1 Administrator
- Deployment-Skript für 5 Paketmanager (apt, dnf, yum, pacman, zypper)
- Etwa 10 Dokumentationsdateien

## Links

- Repository: internes GitLab der UHA (`git.uha4point0.fr`), nicht öffentlich.
- Kompetenzraster 4.0.1, offizielles Nachweisblatt der Ausbildung: siehe `content/skills.yaml` dieses Repositorys.

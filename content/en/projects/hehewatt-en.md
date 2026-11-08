---
title: "HEHEWATT"
slug: "hehewatt-en"
type: "project"
lang: "en"
translation_key: "hehewatt"
date: 2026-10-26
subtitle: "A school carpooling application taken over and extended by several cohorts of students"
seo_description: "HEHEWATT, an E-mersion UHA 4.0 school carpooling app for the Lycée Henner in Altkirch: PHP/MySQL, OpenStreetMap geolocation, a team spanning several cohorts."
featured: false
status: "en-cours"
role: "Full-stack developer, one of the first four contributors in a team of 30 to 50 people depending on the cohort"
period_start: 2026-01-05
period_end: 2026-02-13
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [php, mysql, javascript, leaflet, openstreetmap, osrm, docker, twig, api-rest]
skills: ["4.0.1/PM-01", "4.0.1/PM-07", "4.0.1/PM-08", "4.0.1/FR-07", "4.0.1/FR-09", "4.0.1/FR-13", "4.0.1/FR-14", "4.0.1/FR-15"]
---

HEHEWATT is a carpooling application developed as part of the E-mersion project, which brings together the Lycée Jean-Jacques Henner in Altkirch and UHA 4.0, funded by Noria. The repository has existed since October 2024 and has been taken over by several successive cohorts: more than a thousand commits, between thirty and fifty contributors. I joined in early 2026 as a full-stack developer on the domain core of the trips and on the notification system, with about 93 commits, as one of the first four contributors.

My work on HEHEWATT ends with my first year; the project continues to be taken over by the following cohorts, which has weighed on some of my decisions, documentation above all, since it is written for someone I will never meet.

## The problem

The Lycée Jean-Jacques Henner is located in the Sundgau, a rural area of the Haut-Rhin badly served by public transport: staff, parents and pupils make the same home-to-school journey by car every day, often alone. HEHEWATT connects these users: drivers publish one-off or recurring trips with intermediate stops, passengers book a seat on the trip that passes near their home, and an administrator on the school's side validates the accounts. The school is a partner of the project, not a client: no invoicing, just a real need to serve for its community.

The technical problem I ran into is not that of a project starting from scratch, but that of a PHP repository without a major framework, where each file is a page or an API endpoint, written by hand by dozens of people of very different levels over more than a year. The task was to add structural features, namely multiple stops with booking by segment and recurring trips compatible with the school calendar, without breaking what real users already rely on. The E-mersion programme aims for secondary-school pupils eventually to contribute to the code from their browser (a web IDE service exists in the Docker environment, commented out for now): documenting and splitting up the work is not an optional nicety.

## The technical decisions

**A booking uses up one seat per segment of the trip, not on the whole trip.** A trip with multiple stops loses its purpose if a passenger who gets on at the second stop and off at the third blocks a seat for the entire trip. The model therefore attaches to each booking a boarding stop and an alighting stop, and availability is computed segment by segment (`Stop::getAvailableSeats()`) rather than globally. I added the display of this per-segment occupancy to the trip card component shared by the five screens concerned, so that a driver can see at a glance which segment is full.

**Trip recurrence follows the real school calendar, not a bare weekly rule.** "Every Monday and Wednesday" means nothing during the holidays. The schema separates a recurring trip (`trajet_recurrent`) from its exceptions (`trip_exceptions`), with the same logic on the booking side (`reservation_recurrente` / `reservation_exceptions`), and cross-references all of this with two school calendar tables, `planning_annee_scolaire` and `planning_conges`, rather than hard-coding the holidays. On the booking side, I lifted a uniqueness constraint so that a passenger can sign up for the whole year or for single occasions on the same recurring trip, and wrote the day-list queries that follow from it.

**Most transactional emails go through a single service that covers the whole life cycle of a trip.** Of about fifteen files able to trigger an email, `NotificationService` (`api/services/`) centralises about twenty methods and queries the database with PDO prepared statements, not concatenated strings. Two rough edges remain unresolved: password reset calls `EmailService` directly, without going through it, and a second, unrelated `NotificationService` class (in-app notifications, `api/notification/`, unsigned) carries the same name in the same global namespace. This is the code I am most certain is mine: the headers of `NotificationService.php` and of the email configuration carry my name, unlike most of the rest of the repository, which is not attributed by name.

**Geolocation relies solely on free OpenStreetMap services, with no commercial API.** There was no budget for a paid key: Nominatim geocodes addresses within an area restricted to the Haut-Rhin and the neighbouring départements (Bas-Rhin, Vosges, Territoire de Belfort, Switzerland, Germany), OSRM computes travel times between stops, and Leaflet displays everything on a map. The real work was composing two APIs with different constraints: Nominatim limits requests to one per second (debounce, cache in `autocomplete.js`), and OSRM expects its coordinates as `longitude,latitude`, the reverse order, an inversion documented in `route-calculator.js` after I found it while spending hours debugging wrong arrival times.

**A versioned v1 API is added alongside the existing endpoints rather than replacing them.** In a repository where dozens of people already call the old endpoints, rewriting them all at once would have broken everyone's work. I created the CRUD endpoints of this v1 API, grouped by resource: trips (4), bookings (6), stops (5). They can respond with plain JSON or with pre-rendered HTML fragments for the pages already using AJAX, without touching the existing endpoints. The two APIs still coexist today.

## What I learned from it

This project taught me above all what "working in a team" means when the team changes its make-up every few weeks. A well-made feature delivered in one block helps nobody else if nobody can build on it: I learned to split my work into pieces small enough for a contributor who does not know the project's history to pick one up without rereading everything.

I also had to correct a reflex: when part of the team was not very active, I tended to fill the gap with personal work, with massive help from AI tools, rather than adapting the scope or reopening the allocation of tasks. The result: too many simultaneous changes make the work hard to follow, and a person who absorbs everything bears the responsibility alone, which is healthy for no one.

The most concrete lesson concerns structural changes: modifying a configuration file shared by the whole team's environment and by the school's servers, without flagging it or having it validated, can break the work of dozens of people who never asked for it. I now communicate this kind of change before merging it, not just in the commit message.

What I would do differently: systematically prepare the progress reviews, even for audiences less visible than a client, namely the pupils who will take over the project, and aim for smaller, more regular deliveries rather than impressive but disruptive leaps forward for the team.

## In figures

- 1,058 commits across the whole repository, about 93 of them mine, as one of the first four contributors
- Between 30 and 50 contributors depending on the cohort, over 16 months (October 2024 to February 2026)
- 262 files excluding dependencies: about 26,000 lines of PHP (144 files), 13,500 lines of JS, 4,700 lines of CSS, 1,000 lines of Twig
- 11 database tables, 21 Twig email templates, a v1 API with 15 endpoints (4 trips + 6 bookings + 5 stops)
- Docker Compose development environment: 4 active services (PHP/Apache with Xdebug, MariaDB, phpMyAdmin, Mailpit), plus a web IDE service for the pupils, entirely commented out

## Links

- Repository: UHA 4.0 internal GitLab, not public. A school project under an agreement with a partner institution; no public read-only copy has been published.
- Technical documentation: I wrote or updated the API, email and geolocation documentation, as well as the README, in the repository (not public).
- Lycée Jean-Jacques Henner in Altkirch, partner of the E-mersion project funded by Noria.

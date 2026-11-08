---
title: "UHA Learn"
slug: "uha-learn-en"
type: "project"
lang: "en"
translation_key: "uha-learn"
date: 2026-10-26
subtitle: "Course catalogue and progress tracking for UHA 4.0, with a PHP REST API written without a framework"
seo_description: "UHA Learn, the UHA 4.0.1 storyline project: course catalogue and progress tracking, PHP 8.2 REST API with no framework, JWT, MySQL, multi-distro deployment."
featured: false
status: "archive"
role: "Design, development, deployment and documentation, solo"
period_start: 2025-09-01
period_end: 2025-12-01
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [php, react, mysql, redis, docker, jwt, rest-api, mvc, bootstrap, vite]
skills: ["4.0.1/FR-03", "4.0.1/FR-04", "4.0.1/FR-06", "4.0.1/FR-08", "4.0.1/FR-09", "4.0.1/FR-10", "4.0.1/FR-11", "4.0.1/FR-13", "4.0.1/FR-15", "4.0.1/FR-16"]
---

UHA Learn is my storyline project (fil rouge) for the first year of the UHA 4.0.1 programme, before MomoCad, my storyline project for the second year. The brief asks for a complete website, built alone, around third-party data, from HTML and CSS up to an in-house PHP API, with Git, a MySQL database and a deployment script.

The project was delivered and defended in December 2025. It has not been maintained since: it is a closed chapter, and my first project carried out alone from start to finish.

## The problem

The 4.0.1 storyline exercise is designed as a progression: start from an HTML and CSS mock-up, read a third-party API's data and then display it dynamically, build a relational database and my own PHP API, with CRUD, security and documentation at every stage.

Rather than pick a theme unrelated to my everyday life, I built a tool for my own course. UHA 4.0 publishes an institutional API, `filrouge.uha4point0.fr`, which lists the years of the programme (from 4.0.0 to 4.0.3) and their courses. I built UHA Learn around that source: a course catalogue that can be browsed by year, personal progress tracking, a moderated queue of course proposals, and an administration panel.

The real problem was keeping this whole stack going alone without cutting corners, particularly on the backend: writing the REST API in PHP without a framework to understand what a framework usually does on my behalf, and delivering a deployment script that genuinely works somewhere other than on my machine, not only in front of the jury.

## The technical decisions

**The REST backend is written in PHP without a framework, with an MVC architecture set up by hand.** Controllers, Models, Middleware (CORS, JWT authentication), Routes and Utils make up the five folders of the backend, for nineteen PHP files and about four thousand four hundred lines. The aim was not to avoid a framework on principle, but to understand the routing, the normalised response format and the database access that a framework usually hides, before I could judge what it would really bring me.

**Authentication combines JWT access and refresh tokens, three roles and account statuses.** Student, moderator and administrator do not have the same rights, and an account can be active, suspended or graduated. Each of the roughly thirty-five endpoints of the API is documented in the README with its required access level, public, authenticated, moderator or administrator: a list of endpoints without that column says nothing about real security.

**The seed scripts are an operations tool, not a throwaway script.** `seed_all.php` accepts `--api-only` to import the real years and courses from the institutional API, `--test-only` to generate a set of test accounts (seven students with various statuses, three moderators, one administrator), and `--clean` to remove everything. The admin panel exposes the same logic behind a secured API: database status, running the scripts, cleaning, resetting. A database that can only be rebuilt by hand is a database you end up afraid to touch.

**The deployment script adapts to the Linux distribution rather than imposing mine.** `deploy.sh` detects the package manager (apt, dnf, yum, pacman, zypper), configures SELinux on Fedora and RHEL, avoids port conflicts, migrates and seeds the database, with `--auto`, `--update` and `--uninstall` modes. A one-command `install.sh` detects and installs Docker and Git, then clones and deploys the project. Going beyond the brief's plain requirement of a Bash script had a precise purpose: that a classmate on another distribution gets the same result without reading a line of the script.

**The catalogue is designed to be searched, not merely listed.** The advanced search covers title, category, year and required tools, and the catalogue is paginated. With four years of training and several courses per year, a single list with no filter or pagination would have become unreadable by the second year of content.

## What I learned from it

Writing the MVC by hand taught me what a PHP framework actually does: route a request, standardise a response, protect access through middleware. I have kept that yardstick whenever I have evaluated a framework since, to know what it really spares me rather than what it hides from me.

It is also my first project carried out alone from start to finish, from the first line of code to the deployment script, before the teamwork on code inherited from HEHEWATT and the client relationship of AUTOFIN Control. No box in the brief required me to industrialise and document: I did it because a project that can only be installed by explaining every step aloud is not really finished, and because an authentication guide written with analogies for a beginner reader was meant to be useful to the other students of the cohort, not only to me.

## In figures

- September to December 2025, 96 commits
- Backend: 19 PHP files, about 4,400 lines
- Frontend: 34 JS/JSX/CSS files, about 7,400 lines
- About 35 REST endpoints, spread over 7 domains (auth, courses, tracking, years, categories, admin, seeds)
- 3 roles, 3 account statuses (active, suspended, graduated)
- 5 Docker Compose services (PHP, MySQL, Redis, phpMyAdmin, Mailpit), frontend built and served by Nginx
- Test dataset generated by seed: 7 students, 3 moderators, 1 administrator
- Deployment script covering 5 package managers (apt, dnf, yum, pacman, zypper)
- About 10 documentation files

## Links

- Repository: UHA internal GitLab (`git.uha4point0.fr`), not public.
- 4.0.1 skills framework, the programme's official tracking sheet: see `content/skills.yaml` in this repository.

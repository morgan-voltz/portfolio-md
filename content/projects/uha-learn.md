---
title: "UHA Learn"
slug: "uha-learn"
type: "project"
date: 2026-10-26
subtitle: "Catalogue de cours et suivi de progression pour UHA 4.0, avec son API REST PHP écrite sans framework"
seo_description: "UHA Learn, fil rouge UHA 4.0.1 : catalogue de cours et suivi de progression, API REST PHP 8.2 sans framework, JWT, MySQL, déploiement multi-distributions."
featured: false
status: "archive"
role: "Conception, développement, déploiement et documentation, en solo"
period_start: 2025-09-01
period_end: 2025-12-01
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [php, react, mysql, redis, docker, jwt, rest-api, mvc, bootstrap, vite]
skills: ["4.0.1/FR-03", "4.0.1/FR-04", "4.0.1/FR-06", "4.0.1/FR-08", "4.0.1/FR-09", "4.0.1/FR-10", "4.0.1/FR-11", "4.0.1/FR-13", "4.0.1/FR-15", "4.0.1/FR-16"]
---

UHA Learn est mon projet fil rouge de première année du parcours UHA 4.0.1, avant MomoCad, mon fil rouge de deuxième année. La consigne demande de construire seul un site web complet autour de données tierces, du HTML et du CSS jusqu'à une API PHP maison, avec Git, une base MySQL et un script de déploiement.

Le projet a été livré et soutenu en décembre 2025. Il n'est plus maintenu depuis : c'est un dossier fermé, et mon premier projet mené seul de bout en bout.

## Le problème

L'exercice du fil rouge 4.0.1 est pensé comme une progression : partir d'une maquette HTML et CSS, lire puis dynamiser les données d'une API tierce, bâtir une base relationnelle et sa propre API PHP, avec CRUD, sécurité et documentation à chaque étage.

Plutôt que choisir un thème sans rapport avec mon quotidien, j'ai construit un outil pour ma propre formation. UHA 4.0 publie une API institutionnelle, `filrouge.uha4point0.fr`, qui liste les années du parcours (de 4.0.0 à 4.0.3) et leurs cours. J'ai construit UHA Learn autour de cette source : un catalogue de cours consultable par année, un suivi de progression personnel, une file de proposition de cours modérée, et un panel d'administration.

Le vrai problème était de tenir toute cette pile en solo sans la bâcler, en particulier sur le backend : écrire l'API REST en PHP sans framework pour comprendre ce qu'un framework fait d'habitude à ma place, et livrer un script de déploiement qui marche vraiment ailleurs que sur ma machine, pas seulement devant le jury.

## Les décisions techniques

**Le backend REST est écrit en PHP sans framework, avec une architecture MVC posée à la main.** Controllers, Models, Middleware (CORS, authentification JWT), Routes et Utils forment les cinq dossiers du backend, pour dix-neuf fichiers PHP et environ quatre mille quatre cents lignes. Le but n'était pas d'éviter un framework par principe, mais de comprendre le routing, le format de réponse normalisé et l'accès à la base de données qu'un framework masque d'habitude, avant de pouvoir juger ce qu'il m'apporterait vraiment.

**L'authentification combine access et refresh tokens JWT, trois rôles et des statuts de compte.** Étudiant, modérateur et administrateur n'ont pas les mêmes droits, et un compte peut être actif, suspendu ou diplômé. Chacun des environ trente-cinq points d'entrée de l'API est documenté dans le README avec son niveau d'accès requis, public, authentifié, modérateur ou administrateur : une liste d'endpoints sans cette colonne ne dit rien de la sécurité réelle.

**Les scripts de seed sont un outil d'exploitation, pas un script jetable.** `seed_all.php` accepte `--api-only` pour importer les années et les cours réels depuis l'API institutionnelle, `--test-only` pour générer un jeu de comptes de test (sept étudiants à des statuts variés, trois modérateurs, un administrateur), et `--clean` pour tout retirer. Le panel admin expose la même logique derrière une API sécurisée : statut de la base, lancement des scripts, nettoyage, remise à zéro. Une base qu'on ne peut reconstruire qu'à la main est une base qu'on finit par avoir peur de toucher.

**Le script de déploiement s'adapte à la distribution Linux plutôt que d'imposer la mienne.** `deploy.sh` détecte le gestionnaire de paquets (apt, dnf, yum, pacman, zypper), configure SELinux sur Fedora et RHEL, évite les conflits de ports, migre et seed la base, avec des modes `--auto`, `--update` et `--uninstall`. Un `install.sh` en une seule commande détecte et installe Docker et Git puis clone et déploie le projet. Dépasser la simple consigne d'un script Bash avait un but précis : qu'un camarade sur une autre distribution obtienne le même résultat sans lire une ligne du script.

**Le catalogue est pensé pour être cherché, pas seulement listé.** La recherche avancée porte sur le titre, la catégorie, l'année et les outils requis, et le catalogue est paginé. Avec quatre années de formation et plusieurs cours par année, une liste unique sans filtre ni pagination serait restée illisible dès la deuxième année de contenu.

## Ce que j'en ai appris

Écrire le MVC à la main m'a appris ce qu'un framework PHP fait réellement : router une requête, uniformiser une réponse, protéger un accès par middleware. Je garde ce repère chaque fois que j'évalue un framework depuis, pour savoir ce qu'il m'évite vraiment plutôt que ce qu'il me cache.

C'est aussi mon premier projet mené seul de bout en bout, de la première ligne de code au script de déploiement, avant le travail d'équipe sur du code hérité d'HEHEWATT et la relation commanditaire d'AUTOFIN Control. Industrialiser et documenter n'était exigé par aucune case de la consigne : je l'ai fait parce qu'un projet qu'on ne peut installer qu'en expliquant chaque étape à l'oral n'est pas vraiment fini, et parce qu'un guide d'authentification écrit avec des analogies pour un lecteur débutant devait être utile aux autres étudiants de la promotion, pas seulement à moi.

## En chiffres

- Septembre à décembre 2025, 96 commits
- Backend : 19 fichiers PHP, environ 4 400 lignes
- Frontend : 34 fichiers JS/JSX/CSS, environ 7 400 lignes
- Environ 35 points d'entrée REST, répartis en 7 domaines (auth, cours, suivi, années, catégories, admin, seeds)
- 3 rôles, 3 statuts de compte (actif, suspendu, diplômé)
- 5 services Docker Compose (PHP, MySQL, Redis, phpMyAdmin, Mailpit), frontend buildé et servi par Nginx
- Jeu de test généré par seed : 7 étudiants, 3 modérateurs, 1 administrateur
- Script de déploiement couvrant 5 gestionnaires de paquets (apt, dnf, yum, pacman, zypper)
- Environ 10 documents de documentation

## Liens

- Dépôt : GitLab interne de l'UHA (`git.uha4point0.fr`), non public.
- Référentiel de compétences 4.0.1, feuille de suivi officielle de la formation : voir `content/skills.yaml` de ce dépôt.

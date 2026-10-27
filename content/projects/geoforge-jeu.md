---
title: "GeoForge, le jeu"
slug: "geoforge-jeu"
type: "project"
date: 2026-10-26
subtitle: "Un puzzle géométrique sur grille où seul le serveur décide si un placement est valide"
seo_description: "GeoForge, puzzle géométrique en API .NET 10 Clean Architecture et front React 19/Phaser 3, fil rouge UHA 4.0.2 mené et soutenu seul."
featured: false
status: "archive"
role: "Conception, développement, tests, conteneurisation et soutenance, en solo"
period_start: 2026-02-01
period_end: 2026-04-01
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [csharp, dotnet, clean-architecture, react, typescript, phaser, postgresql, podman, jwt, xunit]
skills: ["4.0.2/E00", "4.0.2/E01", "4.0.2/E02", "4.0.2/E03", "4.0.2/E04", "4.0.2/E06", "4.0.2/E07", "4.0.2/E08", "4.0.2/E11", "4.0.2/E12", "4.0.2/O02", "4.0.2/O03"]
---

GeoForge est un jeu web de puzzle géométrique ; à ne pas confondre avec « GeoForge, l'addon Godot », un module de génération de terrains que j'ai développé séparément et qui ne partage que le nom avec ce projet. Celui-ci a été mon projet fil rouge de deuxième année du parcours UHA 4.0.2 pour l'année universitaire 2025-2026, mené seul de février à avril 2026 et soutenu devant le jury. Je suis réinscrit en 4.0.2 pour 2026-2027, cette fois avec MomoCad comme fil rouge officiel.

## Le problème

La consigne de 4.0.2 demande une application orientée objet qui gère des formes géométriques : les créer, les composer, calculer leurs caractéristiques. L'un des chemins suggérés est un jeu de type tangram servi par un site web ; j'ai pris ce chemin au pied de la lettre et construit un puzzle arcade contre la montre, où un modèle cible composé de plusieurs formes doit être reconstruit sur un quadrillage, en temps limité, avec un score de fidélité.

Le jeu étant compétitif — classement, temps limité, étoiles — le problème technique dépasse la géométrie elle-même. Si le client décide seul qu'un placement est valide, ou calcule lui-même le score qu'il transmet au serveur, rien n'empêche un joueur de truquer sa requête pour obtenir un score impossible. Il fallait donc une règle tenue strictement : le client prévisualise et guide, mais seule l'API décide, et elle décide à partir d'un état qu'elle contrôle entièrement, jamais de ce que le client prétend avoir obtenu.

Au-dessus de ce cœur, le projet devait aussi fonctionner comme une petite plate-forme : les niveaux ne sont pas seulement ceux que j'ai écrits, les joueurs peuvent en créer, et quelqu'un doit les relire avant qu'ils soient publiés. Ce second problème, éditorial plus qu'algorithmique, demandait un circuit de validation clair plutôt qu'un bouton « publier » sans filtre.

## Les décisions techniques

**Le client ne décide ni de la validité d'un placement ni du score : les deux sont calculés côté serveur, par une méthode reproductible.** Dans un jeu chronométré et classé, laisser le client juger de la validité ou calculer le score revient à laisser le joueur se noter lui-même, et je voulais un résultat reproductible et explicable plutôt qu'une note ad hoc. `IsPlacementValid` calcule les cellules de grille occupées par une forme à partir d'un masque local tourné selon sa rotation, vérifie les sorties de grille et les collisions ; `AddShape()` et `MoveShape()` annulent automatiquement (rollback) toute opération qui échoue à ce contrôle. Le score vient d'un pourcentage de fidélité calculé par comparaison cellule par cellule entre la construction du joueur et le modèle cible — occupée dans les deux, par le bon type de forme, au bon endroit — plutôt que d'une formule calculée à la main ; combiné à un bonus de temps, ce pourcentage alimente le score et sa conversion en étoiles, de une à cinq. Le client n'affiche qu'un aperçu vert ou rouge — une prédiction, jamais une décision.

**Le domaine ne connaît ni la base ni le HTTP.** Je voulais pouvoir tester toute la logique de jeu sans lancer un serveur ni une base, et garantir qu'un contrôleur ne puisse pas contenir une règle métier qu'il serait seul à connaître. `GeoForge.Core` — les formes `Shape2D`, sept formes concrètes, la fabrique `Shape2DFactory` — ne dépend d'aucun autre projet de la solution ; `GeoForge.Api` (contrôleurs, DTOs) et `GeoForge.Infrastructure` (EF Core, migrations) dépendent de lui, jamais l'inverse. Les 131 tests unitaires xUnit tournent sur ce seul projet, sans base ni serveur à démarrer.

**Le contenu créé par les joueurs suit un circuit à plusieurs états, pas un bouton « publier ».** Un niveau mal conçu — trop facile, trop dur, injouable — dégrade l'expérience de tous les autres joueurs ; publier sans filtre n'était pas acceptable. Un niveau passe par brouillon, test par son auteur, soumission, modération — acceptée ou refusée, avec une difficulté assignée — puis publication, le tout piloté depuis un panel d'administration qui gère aussi les comptes et les niveaux système.

**Un niveau peut être généré par un algorithme, et sa difficulté évaluée automatiquement, sans passer par un humain à chaque fois.** Écrire à la main chaque niveau d'un catalogue qui grossit ne tient pas à l'échelle, et juger la difficulté « à l'œil » ne donne pas un classement cohérent d'un niveau à l'autre. Le panel d'administration expose, à côté du circuit de modération ci-dessus, un générateur procédural de niveaux et une évaluation automatique de leur difficulté — deux algorithmes qui sortent du cadre d'un CRUD classique.

**L'authentification résiste à plus qu'un mot de passe oublié.** Un système de comptes et de classement est une cible normale pour des tentatives de connexion automatisées si rien ne les freine. Elle repose sur des jetons JWT, des mots de passe hachés en BCrypt et des rôles qui protègent les routes sensibles ; après trois échecs de connexion, un compte se verrouille pendant quinze minutes avant de pouvoir réessayer.

**Phaser est resté un moteur enfermé dans son coin, pas mélangé au reste de React.** Un canvas Phaser monté et démonté au rythme du cycle de vie de React — re-renders, navigation entre pages — crée facilement des instances fantômes du jeu qui continuent de tourner en arrière-plan. Les scènes du jeu (`GameScene`, `ShapeRenderer`) vivent derrière une frontière stable, et l'état de la partie en cours est répliqué dans le store Zustand `gameStore` : le reste de l'interface — chronomètre affiché, boutons, modales — n'a jamais besoin de lire l'intérieur du canvas pour savoir où en est la partie.

## Ce que j'en ai appris

Que la règle « le client ne décide de rien » est plus simple à poser dès le premier contrôleur qu'à ajouter après coup : parce que la validation de placement et le score étaient côté serveur depuis le début, je n'ai jamais eu à revenir en arrière pour fermer une faille de triche. Que l'intégration d'un moteur de jeu dans une application web n'est pas un détail de montage : Phaser et React ont chacun leur idée de qui possède l'affichage, et le seul moyen d'éviter les conflits est de décider explicitement où passe la frontière, une fois, plutôt que de la redessiner à chaque bug. Que documenter un algorithme avant la soutenance force à vérifier qu'on le comprend vraiment soi-même, pas seulement qu'il compile et passe les tests. Et que me fixer un cadre écrit pour l'usage de l'IA, avec l'objectif explicite de pouvoir tout réexpliquer et tout refaire sans aide, a plus changé ma manière de travailler que n'importe quel choix d'architecture.

## En chiffres

- 141 commits, tous de Morgan Voltz, de février à avril 2026
- 97 fichiers C# côté API (~6 400 lignes hors migrations), 54 fichiers TypeScript/TSX côté front (~4 600 lignes)
- 136 tests automatisés (131 unitaires xUnit + 5 d'intégration), rapport de couverture générable (Cobertura + ReportGenerator)
- 38 requêtes API dans la collection Bruno
- 26 endpoints REST sur 4 contrôleurs, documentés avec Swagger
- 7 types de formes, 3 tailles, 4 rotations
- Classement des 50 meilleurs scores, filtrable par niveau
- Verrouillage de compte après 3 échecs de connexion, pour 15 minutes

## Liens

- Dépôt : GitLab interne UHA 4.0, non public.
- Démo : non publiée ; l'environnement Podman Compose (nginx, API, PostgreSQL) servait au développement, pas à une instance hébergée en permanence ; la soutenance s'appuyait sur un dossier dédié (slides Slidev, pitch, diagramme d'architecture).
- Documentation : architecture, modèle de données, algorithmes de gameplay, guide de déploiement, diagrammes UML et supports de soutenance existent dans le dépôt du projet, non publiés séparément.

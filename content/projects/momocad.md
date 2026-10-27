---
title: "MomoCad"
slug: "momocad"
type: "project"
date: 2026-10-26
subtitle: "Un logiciel de dessin 2D façon CAO dont l'état n'est qu'un journal d'événements rejoué"
seo_description: "MomoCad, projet fil rouge UHA 4.0.2 : dessin 2D en C#/.NET 10, architecture hexagonale, event sourcing, 275 tests à 100 % de couverture."
featured: true
status: "en-cours"
role: "Conception, développement, tests et documentation, en solo"
period_start: 2026-09-04
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: true
tags: [csharp, dotnet, architecture-hexagonale, event-sourcing, xunit, plantuml, ddd, clipper2, mcp, avalonia]
skills: ["4.0.2/E00", "4.0.2/E01", "4.0.2/E02", "4.0.2/E03", "4.0.2/E04", "4.0.2/E05", "4.0.2/E06", "4.0.2/O02"]
---

MomoCad est mon projet fil rouge de deuxième année du parcours UHA 4.0.2. La consigne demande une application et une API qui gèrent des formes géométriques en programmation orientée objet : créer et supprimer des formes, composer des ensembles, calculer leurs caractéristiques mathématiques, et laisser un modèle de langage interroger les données. Le nom vient de « Morgan » et d'AutoCAD, l'outil qui a inspiré l'angle choisi.

Au 26 octobre 2026, la première livraison, le cœur métier, est écrite et couverte à cent pour cent par les tests. Les livraisons suivantes, l'API et sa base, le poste de travail, la synchronisation et le chatbot, sont spécifiées et attendent leur tour.

## Le problème

La consigne est pensée pour une application de dessin ou un jeu de type tangram servi par un site web. J'ai voulu en faire autre chose : un petit logiciel de dessin 2D qui se comporte comme un outil de CAO. On y assemble des formes par union et par soustraction, et la forme composée qui en résulte garde la mémoire des formes dont elle vient. C'est ce qui rend le projet intéressant à construire, parce qu'une composition n'est plus une simple liste d'objets mais un historique d'opérations.

Deux écarts avec la consigne ont été discutés et validés avec l'encadrant : une application de bureau plutôt qu'un site web, et un fonctionnement local d'abord, avec une synchronisation vers un serveur. L'API et la base hébergées restent dues au même titre que dans la consigne, et le chatbot aussi.

Le vrai problème technique est alors celui-ci : comment représenter un dessin pour que l'annulation, l'historique et la synchronisation entre plusieurs postes ne soient pas des fonctionnalités ajoutées après coup, mais des conséquences naturelles du modèle ?

## Les décisions techniques

**Rien n'est stocké comme état, tout est journal.** Chaque action du dessinateur devient un événement ajouté à un journal qui ne fait que grossir. L'état affiché est un rejeu de ce journal depuis le début. La méthode qui applique un événement est la même quand on agit et quand on rejoue : il ne peut donc pas exister d'écart entre ce qu'on voit après avoir dessiné et ce qu'on obtient en reconstruisant le dessin depuis zéro. Un test rejoue deux fois le même journal et compare les résultats. L'historique, l'annulation et la synchronisation deviennent des lectures du journal, pas des mécanismes à part.

**L'architecture hexagonale est garantie par le compilateur, pas par une convention.** Le projet du cœur métier ne référence aucun paquet externe, et les méthodes qui modifient une forme ou un dessin sont internes au projet : seuls les gestionnaires de commandes et le rejeu peuvent les appeler, les tests y accèdent par une visibilité explicite. Un hôte, qu'il soit console, API ou interface graphique, ne peut physiquement pas modifier un dessin en contournant une commande. Le graphe de dépendances entre les dix projets de la solution a été posé dès le premier jour, avant que les adaptateurs existent.

**Les invariants sont écrits en logique positive.** Pour refuser un rayon invalide, le code teste « n'est pas strictement positif » plutôt que « est inférieur ou égal à zéro ». La différence compte pour une valeur qui n'est pas un nombre : toute comparaison avec elle est fausse, et la forme négative aurait laissé passer le rayon. Les tests vérifient les cas limites avec des valeurs non numériques et infinies.

**La géométrie est calculée par le domaine, pas déléguée.** L'aire d'un polygone vient de la formule du lacet, un cercle est discrétisé en cent vingt-huit segments avec une erreur relative mesurée, et les coordonnées sont recentrées avant calcul pour garder la précision en virgule flottante loin de l'origine. La bibliothèque Clipper2 n'interviendra que pour l'union et la soustraction de contours, derrière un port du domaine. En préparant cet adaptateur, j'ai trouvé que la documentation officielle de la bibliothèque donnait un ordre d'arguments faux pour une de ses fonctions, vérifié directement sur le binaire, et je l'ai consigné avant d'écrire la moindre ligne.

**Les tests sont revus par mutation.** Casser le code exprès et regarder si un test tombe a conduit à encadrer chaque tolérance par deux tests, l'un qui refuse une valeur mille fois plus petite, l'autre qui accepte la valeur limite, et à vérifier le contenu des messages d'erreur plutôt que leur seul type. La couverture de cent pour cent est mesurée par l'outillage, pas affirmée dans un README.

**La documentation précède le code.** Neuf diagrammes PlantUML ont été écrits avant les classes, puis scindés en quatre vues parce qu'une planche unique ne se lisait plus. Le vocabulaire du projet est fixé dans un langage ubiquitaire, et la documentation elle-même a été passée au crible d'un audit croisé : cent cinquante et un constats bruts, soixante-dix-sept confirmés par des vérificateurs indépendants, dix-huit décisions de cadrage tranchées.

## Ce que j'en ai appris

Qu'un modèle choisi pour une raison simple, garder la trace des opérations d'un dessin, rend gratuites des fonctionnalités que j'aurais autrement bricolées une par une. Que les garde-fous les plus solides sont ceux que le compilateur impose, pas ceux qu'on se promet de respecter. Que tester, c'est aussi essayer de faire mentir ses propres tests. Et qu'un projet solo supporte très bien une discipline d'équipe, revues indépendantes comprises, à condition de la formaliser.

Ce que je ferais autrement : fusionner plus tôt la branche de travail dans la branche principale et commiter la documentation au fil de l'eau, plutôt que de laisser cent quatre-vingt-quinze pages hors du dépôt.

## Correspondance avec les étapes du fil rouge

| Étape de la consigne | Dans MomoCad |
|---|---|
| 0. Spécifier le MVP en UML | Backlog, wireframe, pseudo-code de l'aire d'une forme composée, user story validée |
| 1. Classes | `Rectangle`, `Triangle`, `Cercle`, instanciées dans l'hôte console |
| 2. Encapsulation | Accesseurs publics, modificateurs internes appelables seulement par une commande ou le rejeu |
| 3. Héritage | `Forme` abstraite, polymorphisme démontré par une boucle unique et testé |
| 4. UML | Neuf diagrammes PlantUML, quatre vues de classes |
| 5. Composition et polymorphisme | `Dessin` agrège les formes et somme aires et périmètres, testé jusqu'à cent formes |
| 6. Tests | 275 tests, 100 % des lignes et des branches du cœur |
| 7. API | Livraison 2, à venir |
| 8. ORM | Livraison 2, à venir, EF Core avec SQLite au poste et PostgreSQL au serveur |
| 9. Déploiement | Livraison 2, à venir |
| 10. Serveur MCP et chatbot | Livraison 5, à venir |
| 11. Front | Livraison 3, à venir, application de bureau Avalonia, écart validé |
| Optionnelle design patterns | Composite préparé, Command écrit pour moitié, Adapter réalisé pour le journal en mémoire |
| Optionnelle personnelle | Local d'abord et synchronisation entre postes, livraisons 3 et 4 |

## En chiffres

- 10 projets dans la solution, 36 types et 147 membres dans le cœur
- 1 038 lignes de code applicatif, 4 385 lignes de tests
- 275 tests, 100 % des lignes et des branches couvertes
- 9 diagrammes PlantUML, 2 300 lignes de documentation de conception, un manuel de référence de 195 pages
- 18 commits, septembre 2026

## Liens

- Dépôt : GitLab interne UHA 4.0, non public. Une copie de lecture sera publiée avec les livraisons suivantes.
- Consigne officielle du fil rouge 4.0.2 : transcrite dans la documentation du projet.

# Journal des décisions architecturales

**Fichier** : `docs/architecture/99-decisions.md`
**Rôle** : trace des décisions structurantes, avec leur contexte et leur justification
**Format** : inspiré d'ADR (Architecture Decision Records) de Michael Nygard
**Lectorat** : contributeur, Morgan lui-même à froid, recruteur attentif
**Dernière mise à jour** : 22 avril 2026

---

## À quoi sert ce document

Ce document consigne les décisions architecturales non-triviales prises au fil du projet, avec pour chacune le contexte dans lequel elle a été prise, ce qu'elle énonce précisément, les alternatives qui ont été écartées et pourquoi, et les conséquences qu'elle entraîne pour le reste du projet.

L'objectif n'est pas la traçabilité bureaucratique, c'est la conservation du *pourquoi*. Une décision prise aujourd'hui, même bien argumentée, sera dans six mois une simple ligne de code ou une structure de dossiers dont on aura oublié les raisons. Sans journal, on se retrouve à remettre en cause des choix par ignorance de leur justification, ou pire, à les défendre par pure inertie. Avec journal, on peut revenir à une décision avec le contexte d'origine en main, ce qui est la seule façon de la remettre en cause intelligemment — en sachant ce qui a changé depuis qui la rendrait caduque.

Le document n'est pas un historique chronologique des commits, ni un suivi de tickets. Il est spécifiquement centré sur les décisions *structurantes* : celles dont la remise en cause aurait un impact étendu sur le projet. Une décision du type « utiliser `league/commonmark` plutôt qu'un autre parser Markdown » ne mérite pas une entrée — elle est locale et réversible. Une décision du type « WordPress reste le CMS maître » mérite une entrée — elle engage l'architecture dans son ensemble.

---

## Comment lire ce document

Le tableau de la section suivante donne une vue synoptique de toutes les décisions numérotées (ADR-001, ADR-002, etc.). Il permet de repérer rapidement une décision et de sauter à son entrée détaillée.

Chaque entrée détaillée suit un format fixe en quatre sections : contexte, décision, alternatives considérées, conséquences. Ce format vient du standard ADR popularisé par Michael Nygard ; il est devenu la convention implicite du monde open-source pour ce type de journal.

Les entrées ne sont jamais supprimées. Si une décision est révisée, on ajoute une nouvelle entrée qui explique la révision et on marque l'ancienne comme `Révisée (voir ADR-NNN)`. L'historique complet reste lisible, ce qui évite la perte d'information et permet de comprendre l'évolution de la pensée du projet.

---

## Tableau synoptique

| Numéro | Titre | Date | Statut |
|---|---|---|---|
| ADR-001 | WordPress maître avec double stockage Markdown et HTML | 2026-04-22 | Accepté |
| ADR-002 | GFM avec frontmatter YAML comme format pivot | 2026-04-22 | Accepté |
| ADR-003 | Clean Architecture en trois projets pour l'API C# | 2026-04-22 | Accepté |
| ADR-004 | `wp_postmeta` plutôt qu'une table custom pour le stockage du Markdown | 2026-04-22 | Accepté |
| ADR-005 | Règle du `source_of_truth` pour la gestion des conflits | 2026-04-22 | Accepté |
| ADR-006 | Conventional Commits avec scope obligatoire et feature branches | 2026-04-22 | Accepté |
| ADR-007 | Langue hybride — anglais en surface, français en interne | 2026-04-22 | Accepté |
| ADR-008 | Monorepo contenant plugin, API, et front | 2026-04-22 | Accepté |
| ADR-009 | Pas de MediatR dans la version initiale de l'API | 2026-04-22 | Accepté |
| ADR-010 | Stack front Vite + React Router + TanStack Query + Tailwind | 2026-04-22 | Accepté |
| ADR-011 | Modélisation des compétences académiques et du projet fil rouge | 2026-04-22 | Accepté |
| ADR-012 | Alignement de la documentation sur .NET 10 plutôt que .NET 8 | 2026-04-23 | Accepté |
| ADR-013 | Podman netavark basculé sur le backend iptables en dev WSL2 | 2026-04-23 | Accepté |

---

## ADR-001 — WordPress maître avec double stockage Markdown et HTML

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Le projet doit produire simultanément deux interfaces publiques pour un contenu unique. La première est un site WordPress classique imposé par une contrainte pédagogique (formation Licence Informatique), qui doit être authentique — éditeur Gutenberg fonctionnel, plugins SEO opérationnels, thème natif. La seconde est un portfolio technique construit sur une stack moderne (API C#, front React) pour démontrer des compétences fullstack. La tension entre ces deux objectifs est réelle : un contenu géré uniquement dans WordPress limite la portabilité et la liberté technique ; un contenu géré ailleurs transforme WordPress en coquille vide.

### Décision

WordPress est le **CMS maître** au sens où il stocke la source de vérité du contenu, mais le contenu est écrit en **Markdown**. Un plugin custom `portfolio-md` accepte du Markdown en entrée, le stocke tel quel comme source canonique dans `wp_postmeta`, et en dérive en parallèle une version HTML en blocs Gutenberg qu'il stocke dans `wp_posts.post_content`. Le HTML alimente le thème WordPress, Yoast, et tout l'écosystème WordPress. Le Markdown reste accessible via une API REST custom que consommeront les autres composants (API C#, et donc indirectement le front React).

### Alternatives considérées

**Markdown en source dans un repo Git, WordPress comme simple miroir** : architecturalement plus propre, mais ne permet pas aux profs d'observer un vrai WordPress utilisable. Éditer dans Gutenberg serait inutile puisque les fichiers Git écraseraient. Incompatible avec la contrainte pédagogique.

**WordPress stocke tout en HTML Gutenberg natif, sans Markdown** : c'est l'usage standard. Simple mais perd la portabilité du contenu, bloque toute migration future hors de WordPress, et complique considérablement la consommation par l'API C# qui devrait parser du HTML Gutenberg plutôt que du Markdown plat.

**Deux stockages totalement séparés (WordPress + base externe)** : duplication inévitable, synchronisation fragile, deux sources de vérité potentielles avec tous les problèmes de cohérence qui en découlent.

### Conséquences

Le plugin `portfolio-md` doit implémenter un pipeline de conversion Markdown vers HTML Gutenberg robuste, ce qui représente un effort initial non-négligeable. Le risque de divergence entre la source Markdown et le HTML dérivé doit être géré par discipline (ne pas éditer le `post_content` directement via Gutenberg natif, mais passer par la métabox Markdown custom). Le Markdown devient le format portable qui protège le projet contre un verrouillage WordPress : exportable à tout moment, versionable dans Git, consommable par n'importe quel renderer externe.

---

## ADR-002 — GFM avec frontmatter YAML comme format pivot

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Puisque le Markdown est le format canonique (voir ADR-001), il faut choisir un dialecte précis. Le monde du Markdown compte plusieurs variantes incompatibles (CommonMark strict, GitHub Flavored Markdown, MultiMarkdown, PHP Markdown Extra, etc.) et plusieurs conventions pour les métadonnées associées (frontmatter YAML, frontmatter TOML, frontmatter JSON, métadonnées dans le corps, métadonnées externes).

### Décision

Le dialecte retenu est **GitHub Flavored Markdown (GFM)**, une extension de CommonMark qui ajoute quatre fonctionnalités : tableaux, listes de tâches avec cases à cocher, barré par `~~`, liens automatiques. Les métadonnées sont portées par un **frontmatter YAML** délimité par des triples tirets en tête de fichier, convention popularisée par Jekyll et devenue standard de fait.

### Alternatives considérées

**CommonMark strict sans extensions** : trop limité pour un contenu technique — pas de tableaux, pas de listes de tâches. Aurait forcé des contournements laids.

**MDX (Markdown + JSX)** : séduisant pour intégrer des composants React dans les articles, mais casse la portabilité car le rendu exige un runtime JavaScript/React. Le côté WordPress ne pourrait plus afficher le contenu. Incompatible avec l'architecture à double rendu.

**Frontmatter TOML ou JSON** : TOML est un bon format mais moins répandu que YAML dans l'écosystème Markdown. JSON est verbeux pour des métadonnées humaines à éditer.

**Métadonnées dans le corps du document** (via des shortcodes ou des blocs spéciaux) : non-standard, fragile, difficile à parser séparément du contenu.

### Conséquences

Le plugin et l'API C# peuvent tous deux utiliser des parsers Markdown standards bien maintenus (`league/commonmark` en PHP, `Markdig` en C#) sans avoir besoin d'extensions custom au-delà de GFM. Les fichiers restent compatibles avec Obsidian, Hugo, Astro, Zola, et tout l'écosystème Markdown moderne, ce qui garantit une portabilité future sans conversion. Le frontmatter YAML impose l'ajout de `symfony/yaml` comme dépendance PHP, coût minime.

---

## ADR-003 — Clean Architecture en trois projets pour l'API C#

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

L'API C# joue le rôle de gateway entre WordPress et le front React. Plusieurs architectures étaient envisageables pour organiser le code : architecture en couches classique (3-tier), Clean Architecture canonique à quatre projets (Domain + Application + Infrastructure + Presentation), Clean Architecture allégée, Vertical Slice Architecture, CQRS avec MediatR. Le choix avait des implications durables sur la maintenabilité, la testabilité, et la possibilité future d'alimenter d'autres interfaces (desktop, mobile, CLI) depuis le même cœur métier.

### Décision

L'API est organisée en **trois projets** selon une version allégée de la Clean Architecture. `Portfolio.Api` contient la surface HTTP (Minimal APIs, DTOs, middlewares). `Portfolio.Application` contient le cœur métier (modèles, interfaces d'abstraction, handlers de cas d'usage) et ne dépend d'aucune technologie externe. `Portfolio.Infrastructure` contient les implémentations concrètes des interfaces définies dans Application (client HTTP WordPress, cache mémoire, renderer Markdig). Les dépendances pointent toutes vers Application.

### Alternatives considérées

**Architecture en couches classique (3-tier Controllers / Services / Data Access)** : plus simple initialement mais tend à laisser les dépendances devenir floues avec le temps. La logique métier finit par connaître trop de détails d'infrastructure.

**Clean Architecture canonique à quatre projets** (Domain séparé de Application) : rigoureuse mais sur-dimensionnée pour ce projet. Pour une gateway API simple, séparer les entités métier pures des cas d'usage applicatifs dilue la logique sans la clarifier.

**Vertical Slice Architecture** : organiserait le code par fonctionnalité (un dossier par cas d'usage contenant tous les éléments nécessaires). Approche pragmatique et moderne, mais moins adaptée à une API où les cas d'usage partagent beaucoup d'infrastructure commune.

**MVC avec Controllers classiques** : plus verbeux que les Minimal APIs, moins moderne, et sans bénéfice particulier ici.

### Conséquences

Le coût initial est plus élevé qu'une architecture monolithique (trois projets à créer, règles de dépendances à respecter, plus de fichiers). Le bénéfice principal est la possibilité de réutiliser `Portfolio.Application` tel quel depuis d'autres interfaces (un futur desktop Avalonia, une app MAUI, un outil CLI) sans dupliquer la logique métier. La testabilité est excellente : tous les cas d'usage peuvent être testés en isolation avec des mocks, sans démarrer de serveur HTTP. Le code reste lisible pour un développeur .NET qui connaît les conventions Clean Architecture.

---

## ADR-004 — `wp_postmeta` plutôt qu'une table custom pour le stockage du Markdown

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Le plugin `portfolio-md` doit stocker le Markdown source et plusieurs métadonnées techniques (hash de contenu, type de source, timestamps) pour chaque article. Deux approches s'offraient : utiliser le mécanisme standard WordPress `wp_postmeta` (clé-valeur associée à un post) ou créer une table custom dédiée.

### Décision

Toutes les métadonnées du plugin sont stockées dans `wp_postmeta` avec le préfixe conventionnel `_portfolio_` pour les masquer de l'interface « Custom Fields » de l'admin. Aucune table custom n'est créée.

### Alternatives considérées

**Table custom dédiée** : théoriquement plus propre — schéma strict, index optimisés, requêtes performantes à grande échelle. Mais coût élevé : implémentation de migrations manuelles, intégration REST API sur-mesure, absence de compatibilité avec les plugins de sauvegarde et de migration qui connaissent `wp_postmeta` mais ignoreraient nos tables.

**Custom Post Type avec méta-attributs structurés** : limité, WordPress ne propose pas vraiment ce niveau de structuration côté stockage au-delà de `wp_postmeta`.

### Conséquences

Le plugin reste compatible avec tout l'écosystème WordPress (UpdraftPlus, Duplicator, WP All Import, etc.) sans configuration supplémentaire. Les performances sont adéquates pour l'échelle d'un portfolio (50 à 300 articles cumulés sur plusieurs années), `wp_postmeta` étant dimensionné pour des charges bien supérieures. Si un jour la volumétrie justifiait une table custom (scénario hypothétique), la migration serait faisable en une journée — `_portfolio_md_source` vers une colonne dédiée — sans refonte architecturale. Ce choix illustre le principe YAGNI (You Aren't Gonna Need It).

---

## ADR-005 — Règle du `source_of_truth` pour la gestion des conflits

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Le contenu peut être modifié depuis trois portes d'entrée distinctes : l'éditeur admin WordPress, un webhook déclenché par un push Git sur un repo d'articles, et un import manuel par collage dans une page d'administration. Sans mécanisme explicite, une édition admin pourrait être silencieusement écrasée par une synchronisation Git subséquente, ou inversement, provoquant des pertes de travail.

### Décision

Chaque article porte une méta `_portfolio_source_type` (valeur `admin`, `git`, ou `paste`) mise à jour à chaque écriture. Quand un webhook Git arrive, le plugin compare le hash du contenu actuellement stocké (méta `_portfolio_md_hash`) avec ce qu'il attend trouver. Si le hash actuel ne correspond pas au dernier hash connu du côté Git et que `_portfolio_source_type` vaut autre chose que `git`, le plugin détecte un conflit et refuse l'écrasement silencieux en retournant un HTTP 409 pour l'article concerné. Morgan résout le conflit manuellement via l'admin. Un mécanisme de *force update* explicite existe pour les cas où l'écrasement est intentionnel.

### Alternatives considérées

**Dernier écrivant gagne (last write wins)** : simple mais dangereux. Une édition admin d'une heure effacée par un push Git obsolète sans avertissement serait une catastrophe pour la productivité.

**Verrouillage exclusif d'une source** : forcer le choix « soit tout passe par l'admin, soit tout passe par Git ». Rigide et incompatible avec la volonté exprimée de conserver les trois portes d'entrée.

**Fusion automatique trois voies comme Git** : techniquement possible avec un algorithme de diff/merge, mais la complexité d'implémentation dépasse de loin le bénéfice pour un projet de cette taille.

### Conséquences

Le plugin peut refuser une synchronisation Git, ce qui ajoute une friction occasionnelle quand les sources ont divergé. Cette friction est délibérée : elle protège contre la perte silencieuse de travail, au prix d'une résolution manuelle dans les cas de divergence. La logique s'inspire directement de Git lui-même (un push non fast-forward est rejeté). La détection de conflit est bornée et testable : c'est la comparaison de deux hashes SHA-256, sans heuristique floue.

---

## ADR-006 — Conventional Commits avec scope obligatoire et feature branches

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Le projet est un monorepo public sur GitHub, visible par des recruteurs et des contributeurs potentiels. La discipline Git du projet influence directement la lisibilité de son historique, la facilité d'onboarding de contributeurs, et l'impression professionnelle générale. Plusieurs conventions de commits (Conventional Commits stricts avec ou sans scope, style libre) et de branches (trunk-based, feature branches, Git Flow) étaient envisageables.

### Décision

Les commits suivent **Conventional Commits avec scope obligatoire**. Les types admis sont `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`, `style`. Les scopes admis sont `plugin`, `api`, `frontend`, `docs`, `repo`. Les messages sont en anglais, impératif présent, ligne de sujet sous 72 caractères. Les branches suivent le modèle **feature branches vers main via pull request avec squash merge**. La branche `main` est protégée. Le nommage des branches reflète le scope : `feature/plugin-yaml-parser`, `fix/api-cache-invalidation`, `docs/pedagogy-overview`.

### Alternatives considérées

**Style libre mais discipliné** : flexible mais ne permet pas d'automatisation (génération de CHANGELOG, semantic-release) et rend la recherche dans l'historique plus difficile.

**Conventional Commits sans scope obligatoire** : moins strict, mais dans un monorepo avec plusieurs composants, le scope est ce qui permet de filtrer par composant dans l'historique. Sans lui, on perd une grande partie du bénéfice.

**Trunk-based development** : excellent pour des équipes matures avec CI/CD intégrée et couverture de tests solide. Pour un développeur seul en apprentissage, il retire la friction utile de la pull request et expose main aux expérimentations cassées.

**Git Flow complet** (main + develop + feature + release + hotfix) : conçu pour des projets avec des releases planifiées. Surdimensionné pour un projet itératif piloté par une personne.

### Conséquences

L'historique Git est lisible et filtrable par composant (`git log --grep="^feat(plugin)"`). L'automatisation future (CHANGELOG, versioning automatique) est possible sans refonte. Les pull requests forcent un moment de revue même en solo, qui attrape la plupart des erreurs évidentes. Le squash merge garantit que `main` contient un commit par fonctionnalité, pas un journal de WIP. Le coût est une discipline à maintenir, qui devient un réflexe après quelques semaines.

---

## ADR-007 — Langue hybride — anglais en surface, français en interne

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Le projet est public sur GitHub avec une visibilité internationale potentielle (recruteurs étrangers, contributeurs externes). En même temps, Morgan pense et apprend en français, et une partie de la documentation est explicitement destinée à un public francophone (étudiants juniors). Le choix entre tout-anglais, tout-français, ou hybride avait des conséquences sur l'accessibilité publique et sur le confort de développement quotidien.

### Décision

La séparation est **fonction de la surface de visibilité**. En anglais : tout le code source, les commentaires de code, les messages de commits, le fichier `README.md`, les issues et pull requests sur GitHub. En français : la documentation interne dans `docs/architecture/` et `docs/pedagogie/`, ainsi que le fichier `CLAUDE.md`.

### Alternatives considérées

**Tout en français** : cohérent avec la pensée de Morgan, mais coupe le projet de la lisibilité internationale. Signale un projet amateur pour un recruteur étranger. Le code en français (`obtenir_utilisateur_par_id`) est un anti-pattern reconnu dans l'industrie.

**Tout en anglais** : maximum de portée internationale, mais crée une friction constante pour Morgan quand il réfléchit en français pendant qu'il rédige. La documentation pédagogique serait moins accessible à son public cible (étudiants francophones).

**Français pour commits, anglais pour code** : incohérent. Un commit `feat(plugin): ajouter le parser YAML` mélange la convention anglaise de Conventional Commits avec du contenu français.

### Conséquences

Le contexte linguistique bascule entre code et documentation, ce qui demande une légère gymnastique mentale mais reste naturel en pratique. Le projet apparaît professionnel pour un visiteur anonyme (README et historique en anglais) tout en restant agréable à maintenir pour Morgan (docs internes en français). Les étudiants juniors francophones visés par la doc pédagogique y accèdent sans barrière linguistique.

---

## ADR-008 — Monorepo contenant plugin, API, et front

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

Le projet comporte trois composants applicatifs indépendants (plugin PHP, API C#, front React) plus une documentation transverse. Deux approches de repository sont courantes dans l'industrie : le *monorepo* (un seul repo contenant tous les composants) et le *polyrepo* (un repo par composant).

### Décision

Un **monorepo unique** hébergé sur GitHub contient tous les composants dans des sous-dossiers (`plugin/`, `api/`, `frontend/`, `docs/`). La documentation transverse vit à la racine et dans `docs/`.

### Alternatives considérées

**Polyrepo** (un repo par composant) : isole mieux les composants, permet des permissions granulaires, facilite le versioning indépendant. Mais pour un projet piloté par une personne, multiplie les clones, les issues trackers, les pipelines CI, et la documentation. Friction plus importante que le bénéfice.

**Monorepo avec outils spécifiques** (Nx, Lerna, Turborepo) : outillage puissant pour des monorepos de grande envergure avec des dépendances croisées complexes. Sur-dimensionné ici, aucune dépendance de code entre les trois composants (chacun a son propre système de build).

### Conséquences

Toute la matière du projet est accessible en un clone. Les issues GitHub peuvent référencer n'importe quel composant avec des labels (`component:plugin`, `component:api`, `component:frontend`). La documentation transverse vit naturellement à côté du code qu'elle documente. Le coût est un repo potentiellement plus volumineux, négligeable à l'échelle du projet. Si un jour un composant devait vivre séparément (par exemple ouvrir le plugin à la communauté WordPress sur wordpress.org), l'extraction est possible via `git subtree split`.

---

## ADR-009 — Pas de MediatR dans la version initiale de l'API

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

L'écosystème .NET moderne utilise fréquemment la librairie MediatR (implémentation du pattern Médiateur) combinée au pattern CQRS pour découpler les endpoints des handlers métier. Cette combinaison est présentée par beaucoup de ressources comme « la bonne manière » de structurer une API ASP.NET Core, au point qu'elle apparaît quasiment par défaut dans les templates de projets entreprise.

### Décision

**MediatR n'est pas utilisé** dans la version initiale de l'API. Les endpoints Minimal API injectent directement les handlers applicatifs via le système d'injection de dépendances natif d'ASP.NET Core. Un endpoint appelle `handler.ExecuteAsync(...)` sans passer par un médiateur.

### Alternatives considérées

**Utiliser MediatR dès le départ** : prépare l'ajout futur de pipeline behaviors (logging, validation, caching transverses). Mais pour une API de gateway avec une dizaine d'endpoints en lecture, l'indirection apportée par MediatR masque le flux au lieu de le clarifier. Chaque handler devient un type avec un dispatcher à résoudre, ce qui complique la navigation dans le code et alourdit les tests.

**CQRS sans MediatR** (juste la séparation command/query) : la séparation lecture/écriture est une bonne idée, mais l'API étant quasi-exclusivement en lecture, le bénéfice est nul à cette échelle.

### Conséquences

Le code de l'API reste direct et lisible : un endpoint, un handler, un appel. Les tests unitaires des handlers ne nécessitent aucun setup de médiateur. Si le projet grossit significativement (plusieurs dizaines de handlers avec des préoccupations transverses partagées), MediatR pourrait être introduit plus tard — l'interface des handlers reste compatible avec un passage futur, une classe `GetArticleBySlug` pouvant devenir `IRequestHandler<GetArticleBySlugQuery, ArticleDto?>` sans refonte du reste.

---

## ADR-010 — Stack front Vite + React Router + TanStack Query + Tailwind

**Date** : 2026-04-22
**Statut** : Accepté

### Contexte

L'écosystème React en 2026 propose plusieurs stacks complètes : Next.js (framework opinionated avec SSR), Remix (philosophie web platform), Astro (îlots d'interactivité sur base statique), ou une stack composée (Vite + bibliothèques choisies individuellement). Chaque option représentait un compromis différent entre simplicité, performance, SEO, et courbe d'apprentissage.

### Décision

Le front est construit sur une **stack composée** : Vite comme bundler, React 18 avec TypeScript, React Router v6 pour la navigation, TanStack Query pour la gestion du cache client et des états serveur, Tailwind CSS pour le styling. Pas de state manager global (les besoins d'état sont couverts par TanStack Query côté serveur et par `useState`/`useReducer` côté local). Pas de SSR.

### Alternatives considérées

**Next.js** : framework complet avec SSR et App Router. Bénéfice SEO sans intérêt ici puisque le SEO est porté par le site WordPress parallèle. Complexité significative pour les cas d'usage simples.

**Remix** : excellente philosophie mais moins mainstream, courbe d'apprentissage non-négligeable, écosystème plus petit que celui de Next ou Vite.

**Astro** : excellent pour un blog majoritairement statique avec peu d'interactivité. Contradictoire avec l'objectif d'apprentissage en profondeur de React — Astro minimise la part React.

**Create React App** : déprécié depuis 2023, exclu.

### Conséquences

La stack est simple à expliquer à un recruteur et correspond aux attentes courantes du marché. Vite offre un temps de démarrage quasi-instantané et un build optimisé sans configuration. TanStack Query couvre à lui seul 90 % des besoins d'état dans une application consommant une API. Tailwind accélère le prototypage visuel. L'absence de SSR simplifie le déploiement (bundle statique sur CDN) au prix d'un léger *flash of content* au premier chargement — acceptable pour un portfolio. Si un jour le SEO du front devenait critique, une migration vers Next.js reste possible en gardant la logique des composants.

---

## Modèle pour nouvelles entrées

Quand une décision architecturale nouvelle est prise, elle est ajoutée ici en suivant ce modèle.

```markdown
## ADR-NNN — Titre court et descriptif

**Date** : YYYY-MM-DD
**Statut** : Accepté | Révisé (voir ADR-NNN) | Abandonné

### Contexte

Le problème qu'on cherchait à résoudre, les contraintes en présence,
ce qui rendait la décision nécessaire. Deux à cinq phrases.

### Décision

Ce qu'on a choisi, formulé de manière directe et non-ambiguë. Pas
de conditionnel ni de « on pourrait ». Deux à quatre phrases.

### Alternatives considérées

Les autres options réelles qui ont été pesées, chacune avec la raison
principale pour laquelle elle a été écartée. Une à trois alternatives
suffisent généralement.

### Conséquences

Ce que la décision entraîne pour le reste du projet, positif et négatif.
Trois à cinq phrases.

---

## ADR-012 — Alignement de la documentation sur .NET 10 plutôt que .NET 8

**Date** : 2026-04-23
**Statut** : Accepté

### Contexte

Lors de la vérification de l'outillage de développement au démarrage de la Phase 0, il est apparu que la version de .NET installée nativement sur l'environnement Fedora de Morgan est la 10.0.100, alors que la documentation architecturale initiale spécifiait .NET 8 comme cible. Cette divergence demande un arbitrage : aligner la documentation sur l'environnement réel, ou installer également le SDK .NET 8 pour respecter la spécification initiale.

### Décision

La documentation est alignée sur **.NET 10**. Toutes les mentions de « .NET 8 » ou « .NET 8+ » dans `00-overview.md`, `02-api-csharp.md`, `CLAUDE.md` et `README.md` sont remplacées par « .NET 10 ». Aucune installation supplémentaire de SDK .NET n'est faite sur l'environnement de développement.

### Alternatives considérées

**Installer également le SDK .NET 8 en parallèle du 10** pour respecter la spécification initiale : rejeté, car cela polluerait inutilement l'environnement avec deux SDK alors qu'un seul suffit, et .NET 10 est rétrocompatible avec les APIs qu'on comptait utiliser en .NET 8 (Minimal APIs, IMemoryCache, HttpClient typé).

**Conserver la doc en .NET 8+ et préciser que le projet tourne effectivement sur .NET 10** : rejeté, car ça créerait une ambiguïté durable entre ce que la doc demande et ce que l'environnement fournit, et compliquerait inutilement la lecture pour un contributeur futur.

### Conséquences

L'environnement de développement et la documentation sont désormais cohérents. Les fonctionnalités spécifiques à .NET 10 (si on décide d'en utiliser certaines) sont disponibles sans restriction. Aucun impact sur la feuille de route de la Phase 6 qui reste construite autour des mêmes patterns et bibliothèques. Le risque principal est qu'un contributeur qui aurait un SDK .NET 8 uniquement ne puisse pas compiler — en pratique, installer un SDK supplémentaire est trivial, et la convention moderne est plutôt d'utiliser un `global.json` à la racine du projet API pour figer la version attendue lors du build (on le posera en Phase 6).

```

Le nouveau numéro est toujours le suivant dans la séquence, jamais réutilisé. Si une décision ancienne est révisée, la nouvelle entrée explique la révision et l'ancienne est marquée `Révisée (voir ADR-NNN)` sans être supprimée.

---

## ADR-013 — Podman netavark basculé sur le backend iptables en dev WSL2

**Date** : 2026-04-23
**Statut** : Accepté

### Contexte

Le développement local du projet se fait sur une installation Fedora hébergée dans WSL2 (Windows Subsystem for Linux 2), avec Podman rootless 5.6.2 comme moteur de conteneurs. Netavark, le moteur réseau de Podman 5.x, programme par défaut ses règles de pare-feu en parlant directement à `nftables` via le binaire `nft`. Le kernel WSL2 fourni par Microsoft n'expose qu'un sous-ensemble des modules `nf_tables` et des helpers associés, ce qui fait échouer netavark à la pose de certaines règles dès qu'un conteneur avec un port exposé est attaché à un bridge — le message observé est `Could not process rule: No such file or directory` puis `netavark: nftables error: "nft" did not return successfully while applying ruleset`. Le symptôme bloque totalement `podman-compose up` pour le stack MariaDB + WordPress de la Phase 0.

### Décision

Le backend firewall de netavark est basculé de `nftables` à `iptables` via un fichier de configuration utilisateur `~/.config/containers/containers.conf` dont le contenu est :

```toml
[network]
firewall_driver = "iptables"
```

La configuration est utilisateur (pas système), s'applique à toutes les invocations Podman du compte courant, et ne nécessite aucun redémarrage de service — netavark relit cette configuration à chaque invocation.

### Alternatives considérées

**Passage immédiat à Fedora Linux natif** (dual-boot ou remplacement complet de Windows) : identifié comme la solution durable qui supprime totalement le problème à la racine, mais reportée à un créneau week-end dédié pour ne pas interrompre la progression de la Phase 0. Un ADR ultérieur documentera cette migration quand elle aura lieu.

**Bascule en Podman rootful** (commandes sous `sudo`) : rejeté car cela supprime les bénéfices de sécurité du mode rootless, pollue le filesystem avec des fichiers appartenant à root, et ajoute de la friction quotidienne.

**Remplacement de Podman par Docker Desktop ou Docker CE** : rejeté car cela modifierait l'outillage conteneur du projet et introduirait soit une dépendance à un produit propriétaire (Docker Desktop), soit un setup parallèle à Podman déjà en place. La cohérence de l'environnement prime.

**Suppression de la déclaration de réseau custom dans `docker-compose.yml`** (pour rester sur le bridge `podman` par défaut) : rejeté car la parade iptables résout le problème à sa source, préserve l'isolation réseau propre à chaque stack Podman, et ne force pas d'adaptation du compose.

### Conséquences

Le développement local est débloqué immédiatement — le stack `db` + `wordpress` démarre et répond sur `http://localhost:8080`. Tous les projets Podman rootless du compte utilisateur (pas uniquement portfolio-md) passent désormais par le backend iptables, ce qui est sans impact fonctionnel : `iptables` sur Fedora moderne est en réalité `iptables-nft`, une surcouche de compatibilité au-dessus de nft qui génère un jeu de règles plus simple et mieux toléré par le kernel WSL2 incomplet. Cette décision est spécifique à l'environnement de dev sous WSL2 : une fois la migration vers Fedora Linux natif effectuée, le fichier `~/.config/containers/containers.conf` pourra être supprimé et netavark reprendra son backend `nftables` par défaut sans conséquence sur le projet. Un contributeur qui voudrait reproduire le setup sous WSL2 doit être informé de cette configuration ; la mention sera consignée dans un document `docs/environnement.md` à créer en fin de Phase 0.

---

## Navigation

| Document | Contenu |
|---|---|
| [`00-overview.md`](00-overview.md) | Vue d'ensemble du projet |
| [`01-plugin-php.md`](01-plugin-php.md) | Architecture du plugin WordPress |
| [`02-api-csharp.md`](02-api-csharp.md) | Architecture de l'API C# |
| [`03-frontend-react.md`](03-frontend-react.md) | Architecture du front React |
| [`../../CLAUDE.md`](../../CLAUDE.md) | Règles de collaboration avec Claude Code |

---

*Document vivant. Enrichi au fil du projet à chaque décision architecturale non-triviale.*

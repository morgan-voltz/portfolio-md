# Journal des décisions architecturales

**Fichier** : `docs/architecture/99-decisions.md`
**Rôle** : trace des décisions structurantes, avec leur contexte et leur justification
**Format** : inspiré d'ADR (Architecture Decision Records) de Michael Nygard
**Lectorat** : contributeur, Morgan lui-même à froid, recruteur attentif
**Dernière mise à jour** : 7 novembre 2026

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
| ADR-012 | Révision du contrat de collaboration : Claude écrit le code, Morgan relit et valide | 2026-10-26 | Accepté |
| ADR-013 | Champs personnalisés : préfixe `_portfolio_` généralisé, trois registrars, autorisation explicite | 2026-10-26 | Accepté |
| ADR-014 | Taxonomie `skill` hiérarchique par référentiel, code officiel en term meta | 2026-10-26 | Accepté |
| ADR-015 | Contenu versionné dans le monorepo, grille de compétences et périmètre des fiches | 2026-10-26 | Accepté |
| ADR-016 | Éditeur CodeMirror 6 avec prévisualisation rendue par le serveur | 2026-10-27 | Accepté |
| ADR-017 | Les pages natives dans le pipeline, éditées comme leur document quand elles en portent un | 2026-10-27 | Accepté |
| ADR-018 | Endpoints REST publics en lecture : le catalogue des documents publiés | 2026-10-29 | Accepté |
| ADR-019 | La porte Git s'authentifie comme un utilisateur et ne force rien | 2026-10-29 | Accepté |
| ADR-020 | Site trilingue : Polylang gratuit, un document par langue, préfixe d'URL dès le premier déploiement | 2026-11-07 | Accepté |
| ADR-021 | La langue dans les routes REST de lecture | 2026-11-07 | Accepté |

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

**Note d'implémentation (26 octobre 2026).** La règle vit dans `Service\ConflictResolver`, décision pure testée ligne par ligne, et s'applique à toute écriture, pas seulement au webhook : la commande `wp portfolio import` enregistre la provenance `git` (les fichiers viennent du dépôt) et refuse donc d'écraser un post dont la dernière écriture vient de l'admin, d'un collage ou d'ailleurs (post créé à la main, sans métas du plugin) quand le contenu diffère, sauf `--force`. Un contenu identique au hash près n'est pas réécrit, sauf si la version de rendu stockée est plus ancienne que celle du renderer. Les deux portes de l'admin (métabox et page de collage, même jour) passent par le même service avec les provenances `admin` et `paste`, qui gagnent toujours : ce sont des actes humains. Deux précautions les accompagnent : le texte d'un formulaire est ramené à LF avant hachage, pour qu'un document enregistré sans retouche garde son hash et ne déclenche pas de faux conflit au prochain import Git ; et la métabox cible le post qu'elle édite plutôt que le slug, avec quatre refus (post inexistant ou d'un autre type, post à la corbeille, slug d'un autre post, changement du slug écrit dans le document d'un post qui en porte un ; l'URL du post ne compte pas, c'est le document stocké qui fait foi). Détail dans `01-plugin-php.md` §5 et §6.1.

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


Cette décision règle la langue du code et des documents, pas celle du site publié, qui relève d'ADR-020.
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

## ADR-011 — Modélisation des compétences académiques et du projet fil rouge

**Date** : 2026-04-22
**Statut** : Accepté (forme concrète de la taxonomie précisée par ADR-014 ; les noms `portfolio_project` et `tech_stack` employés ci-dessous sont devenus `project` et `tech_tag`, voir `04-structure-wordpress.md`)

### Contexte

Le cahier des charges académique de la Licence Informatique UHA 4.0 impose que les compétences des référentiels Compétences 4.0.1 et Compétences 4.0.2 soient rattachées explicitement aux projets du portfolio, pour prouver qu'elles ne sont pas seulement théoriques mais démontrées par des réalisations concrètes. Le cahier des charges désigne en outre un projet particulier — le fil rouge 4.0.2 — qui doit bénéficier d'un traitement éditorial distinctif sur le site (mise en avant, description détaillée). L'architecture initiale du plugin `portfolio-md` n'adressait aucun de ces deux besoins : les compétences n'apparaissaient ni dans le frontmatter, ni dans le schéma de stockage, ni dans les taxonomies ; le fil rouge n'avait aucun statut distinctif par rapport aux autres projets.

### Décision

Les compétences sont modélisées comme une **taxonomie WordPress custom `skill`**, attachée au CPT `portfolio_project` au même titre que `tech_stack`. Chaque projet déclare dans son frontmatter YAML un champ `skills` listant les codes de compétences démontrées (par exemple `[4.0.1/C1.3, 4.0.2/C2.1]`). Le plugin mappe ces codes aux termes de la taxonomie, en créant à la volée les termes manquants avec code comme slug et intitulé comme nom.

Le projet fil rouge est marqué par un **champ booléen `is_fil_rouge` dans le frontmatter**, stocké côté WordPress dans une meta `_portfolio_project_is_fil_rouge`. Le thème WordPress et le front React peuvent requêter ce projet en une ligne via `meta_query` et lui appliquer le traitement éditorial distinctif attendu.

### Alternatives considérées

**Les compétences comme simples tags dans la taxonomie `post_tag`** : rejeté, car mélangerait compétences académiques (codées, normées) et tags thématiques (libres, éditoriaux). La pollution sémantique affecterait à la fois l'administration WordPress et les archives publiques, qui mélangeraient « architecture » et « 4.0.1/C1.3 » dans les mêmes listings.

**Les compétences comme champ meta string séparé par virgules** : rejeté, car ce format perd la capacité d'agrégation inverse. WordPress ne peut pas nativement lister « tous les projets qui démontrent telle compétence » sur un champ meta textuel, alors qu'il le fait gratuitement sur une taxonomie. Or cette agrégation est exactement ce que demande le jury pour évaluer.

**Les compétences listées en dur dans le thème ou le front** : rejeté, car figerait le référentiel dans le code au lieu du contenu. Chaque évolution des référentiels UHA 4.0 forcerait une modification de code plutôt qu'une mise à jour de termes de taxonomie.

**Le fil rouge comme valeur particulière d'un enum `project_tier`** : considéré mais jugé sur-dimensionné. Un booléen suffit puisqu'il n'existe jamais qu'un seul fil rouge à un moment donné, et l'enum introduirait une structure ouverte (valeurs futures à définir) sans bénéfice concret.

### Conséquences

Le plugin doit enregistrer la classe `PostTypes\SkillTaxonomy` au démarrage, au même titre que `TechStackTaxonomy`. La forme de la réponse de l'API REST `/wp-json/portfolio/v1/projects/{slug}` s'enrichit de deux champs : `skills` (liste des compétences rattachées) et `is_fil_rouge` (booléen). Le front React et le thème WordPress exploitent ces champs pour afficher les compétences sur chaque fiche projet et pour appliquer le traitement éditorial distinctif du fil rouge. Une page archive par compétence devient naturelle à construire via les archives de taxonomie WordPress, ce qui répond directement à l'exigence du jury de pouvoir consulter les projets démontrant une compétence donnée. La liste concrète des codes de compétences n'est pas figée dans le plugin mais vit dans les termes de la taxonomie, ce qui permet d'évoluer sans toucher au code — si les référentiels UHA 4.0 évoluent d'une promotion à l'autre, seuls les termes WordPress changent.

---

## ADR-012 — Révision du contrat de collaboration : Claude écrit le code, Morgan relit et valide

**Date** : 2026-10-26
**Statut** : Accepté

### Contexte

Le contrat de collaboration initial (`CLAUDE.md` version 1.0, 22 avril 2026) posait une règle cardinale : Claude explique, guide et relit, mais n'écrit jamais le code métier, parce que Morgan apprend en tapant chaque ligne lui-même. Six mois plus tard, le dépôt n'a pas avancé au-delà du modèle de contenu du plugin (post types, taxonomies, quatre post metas) : la contrainte de temps de Morgan, en formation et en emploi, n'a pas permis de tenir le rythme d'écriture. Entre-temps, le besoin s'est précisé : Morgan cherche une alternance et un stage pour janvier 2027 et veut un portfolio présentable pour appuyer ses candidatures. Il n'y a pas de date butoir académique ferme, mais un horizon de quelques mois pour disposer au minimum du site WordPress fonctionnel, idéalement de la stack complète.

### Décision

**Claude écrit le code, les tests et la documentation associée ; Morgan relit, questionne et valide.** La relation reste une revue à deux, mais le clavier change de mains. Chaque lot livré par Claude comprend obligatoirement trois choses : le code, les tests qui le protègent, et un résumé pédagogique qui explique ce qui a été fait, pourquoi, quelles décisions locales ont été prises et ce que Morgan doit vérifier en priorité. Avant d'être présenté à Morgan, chaque lot passe par une relecture indépendante par des agents de revue (bugs, sécurité WordPress, cohérence avec la doc, qualité des tests).

Les garde-fous qui ne changent pas : aucune décision architecturale n'est prise par Claude seul (les options sont présentées, Morgan tranche) ; aucune mention d'IA n'apparaît dans les commits ou les pull requests ; la documentation n'est jamais modifiée silencieusement ; rien n'est supprimé sans confirmation explicite. Côté Git, Claude peut désormais créer les branches, commiter, pousser et ouvrir les pull requests ; **le merge sur `main` reste l'acte de Morgan**, par lequel il atteste avoir lu et compris le lot.

### Alternatives considérées

**Conserver le contrat initial** : pédagogiquement le plus pur, mais le rythme observé (aucun commit entre le 28 avril et le 26 octobre 2026) rend l'objectif de janvier 2027 inatteignable. Un portfolio inexistant n'enseigne rien et ne sert aucune candidature.

**Claude en autonomie complète, sans revue** : le plus rapide sur le papier, mais rompt le lien entre le code et la compréhension de Morgan. Le projet est public et sera discuté en entretien : Morgan doit pouvoir défendre chaque choix. La revue obligatoire et le résumé pédagogique sont la contrepartie du droit d'écrire.

**Pair programming synchrone** (Claude propose ligne à ligne, Morgan tape) : préserve le geste d'écriture, mais coûte presque autant de temps que le contrat initial sans en avoir la valeur formatrice complète. Écarté.

### Conséquences

La vitesse de production augmente nettement : les phases 2 à 5 de la feuille de route (pipeline Markdown, blocs Gutenberg, éditeur admin, API REST) deviennent atteignables avant la fin de l'année. Le risque principal est une compréhension superficielle du code par Morgan ; il est contenu par l'obligation d'explication, par la relecture de chaque PR avant merge, et par les tests qui jouent le rôle de spécification lisible. La discipline documentaire est maintenue à l'identique. Le `README.md` est mis à jour pour rester transparent sur le mode de collaboration, par honnêteté vis-à-vis des recruteurs et des étudiants qui liront le dépôt. `CLAUDE.md` passe en version 1.1 ; les sections 2, 3, 4 et 5.4 sont réécrites en conséquence.

---

## ADR-013 — Champs personnalisés : préfixe `_portfolio_` généralisé, trois registrars, autorisation explicite

**Date** : 2026-10-26
**Statut** : Accepté

### Contexte

Le cadrage du 25 avril 2026 (`04-structure-wordpress.md` §5) prévoyait quatre champs publics **sans** préfixe underscore, pour qu'ils restent visibles dans la boîte « Champs personnalisés » de l'admin, et des champs projet et internes préfixés `_portfolio_`. La session du 28 avril a généralisé le préfixe à tous les champs (cohérence, signalisation claire « géré par le plugin ») et choisi trois classes d'enregistrement plutôt qu'une. En implémentant, une conséquence non anticipée est apparue : WordPress considère toute clé commençant par `_` comme *protégée*, et `register_meta()` donne alors à `auth_callback` la valeur par défaut `__return_false` (`wp-includes/meta.php`, bloc « If `auth_callback` is not provided, fall back to `is_protected_meta()` »). Les quatre metas communes mergées le 28 avril étaient donc exposées dans l'API REST mais **interdites en écriture pour tout le monde, administrateurs compris**, via Gutenberg ou REST. Par ailleurs, la spec `01-plugin-php.md` listait deux flags `needs_mermaid` / `needs_katex` absents du cadrage du 25 avril.

### Décision

Toutes les clés meta du plugin sont préfixées `_portfolio_` et donc protégées. Elles sont enregistrées par **trois classes** dans `plugin/src/Meta/` : `CommonMetaRegistrar` (quatre metas publiques sur `article` et `project`), `ProjectMetaRegistrar` (huit metas publiques sur `project`, dont `_portfolio_project_is_fil_rouge`), `InternalMetaRegistrar` (cinq metas internes sur les deux types). Les metas **publiques** déclarent explicitement `auth_callback => [MetaRegistration::class, 'authorizeEditPost']`, qui autorise l'écriture à quiconque peut éditer le post (`user_can($userId, 'edit_post', $postId)`). Les metas **internes** gardent `auth_callback => '__return_false'` et `show_in_rest => false` : seul le code du plugin les écrit, via `update_post_meta()` qui ne passe pas par les capabilities. Les sanitizers sont des fonctions **pures et strictes** regroupées dans `Meta\Sanitizer` : une valeur invalide devient la valeur neutre du type (`''`, `false`, `0`), jamais une valeur « réparée ». Les flags `needs_mermaid` / `needs_katex` sont **reportés** : ils seront enregistrés quand le pipeline les produira et que le thème les consommera.

### Alternatives considérées

**Champs publics sans préfixe** (cadrage initial) : visibles et éditables à la main dans la boîte « Champs personnalisés », ce qui contourne le pipeline et la sanitization typée, et mélange les champs du plugin avec ceux d'autres extensions. Écarté au profit de l'édition via Gutenberg, REST ou le pipeline.

**Un seul registrar** : plus court, mais une classe de dix-sept champs aux règles différentes (publics / internes, article / projet) devient vite illisible. Trois classes alignées sur les trois lots du cadrage sont plus faciles à relire et à tester.

**Sanitization permissive** (corriger les valeurs : tronquer, arrondir, remplacer) : ne casse rien en apparence mais masque les erreurs du pipeline ou de la saisie. Pour un projet d'apprentissage, voir l'erreur vaut mieux que la cacher.

**Enregistrer `needs_mermaid` / `needs_katex` dès maintenant** : alignerait la spec et le code, au prix de deux champs sans producteur ni consommateur. Code mort refusé.

### Conséquences

La boîte « Champs personnalisés » de l'admin n'affiche aucun champ du plugin ; l'édition passe par Gutenberg (metas exposées en REST), par l'API REST ou par le pipeline Markdown. Toute nouvelle meta publique **doit** porter `MetaRegistration::authorizeEditPost`, sans quoi elle sera muette en écriture ; un test unitaire vérifie ce callback sur chaque meta enregistrée. Les constantes de clés vivent sur les registrars (`CommonMetaRegistrar::FEATURED`, etc.) : aucune clé n'est écrite en dur ailleurs. Les règles de sanitization sont documentées dans `04-structure-wordpress.md` §5 et vérifiées par la suite PHPUnit (sans WordPress) et par un test de fumée WP-CLI (avec WordPress). Toute écriture de meta depuis PHP passe par `Meta\MetaWriter::update()` : `update_post_meta()` déslashe sa valeur (`wp_unslash()`) avant stockage, ce qui supprimerait les antislashs d'un Markdown et casserait son hash ; le helper applique `wp_slash()` au préalable, comme l'API REST de WordPress. La note de session `docs/guide-implementation-meta.md` du 28 avril 2026 est supprimée : son contenu durable vit dans `04-structure-wordpress.md` §5 et dans cette entrée.

---

## ADR-014 — Taxonomie `skill` hiérarchique par référentiel, code officiel en term meta

**Date** : 2026-10-26
**Statut** : Accepté (précise ADR-011)

### Contexte

ADR-011 a décidé de modéliser les compétences académiques UHA 4.0 comme une taxonomie custom attachée aux projets, en prévoyant « code comme slug et intitulé comme nom ». À l'implémentation, deux faits ont imposé de préciser la forme. D'abord, un code comme `4.0.1/C1.3` ne survit pas à `sanitize_title()` : le point et la barre oblique deviennent des tirets, ce qui donne `4-0-1-c1-3`, où le référentiel et le code ne se distinguent plus (vérifié sur WordPress 7.1). Ensuite, le jury raisonne par référentiel (Compétences 4.0.1, Compétences 4.0.2) : il veut voir, pour chaque référentiel, les compétences démontrées et les projets qui les démontrent.

### Décision

La taxonomie `skill` est **hiérarchique**, attachée au seul post type `project`, avec l'URL de base `/competences/`. Les termes **parents** sont les référentiels (`4-0-1` → « Compétences 4.0.1 », `4-0-2` → « Compétences 4.0.2 »), auto-insérés à l'activation du plugin comme les statuts de projet (`TaxonomyRegistrar::ensureSkillReferentialTerms()`). Les termes **enfants** sont les compétences ; leur nom est l'intitulé officiel, leur slug est dérivé du code (`C1.3` → `c1-3`, WordPress suffixe automatiquement en cas de doublon entre référentiels), et le **code officiel complet** (`4.0.1/C1.3`) est stocké dans le term meta `_portfolio_skill_code`, exposé en REST et sanitizé comme texte court. Les compétences sont saisies dans la grille `content/skills.yaml` et projetées dans la taxonomie par `wp portfolio skills sync` (ADR-015), ou à la main dans l'admin ; le pipeline Markdown les référence par leur code (`skills: [4.0.1/C1.3, …]`) sans jamais en créer ; la liste des codes et intitulés est du **contenu**, fourni par Morgan depuis le référentiel officiel, pas du code.

**Précision d'implémentation (26 octobre 2026)** : les slugs ne sont pas dérivés à la volée mais écrits dans `content/skills.yaml`, et ils sont uniques dans toute la taxonomie, référentiels compris, parce que WordPress impose cette unicité quel que soit le parent (`wp_unique_term_slug()` suffixerait silencieusement une insertion et `wp_update_term()` refuserait la mise à jour). Le chargeur de la grille rejette tout doublon avant d'écrire. La synchronisation (`wp portfolio skills sync`) retrouve les termes par leur code, met à jour en place, signale les termes non revendiqués par la grille sans jamais les supprimer, et enregistre un terme refusé par WordPress comme échec sans interrompre le reste. Le passage de la grille initiale aux intitulés officiels de 4.0.1 (ADR-015) s'est fait par ce chemin.

### Alternatives considérées

**Taxonomie plate avec slug dérivé du code complet** (`4-0-1-c1-3`) : la plus simple à implémenter, mais le regroupement par référentiel devrait être recodé dans le thème et dans l'API C# en analysant les slugs, et une archive « tous les projets du référentiel 4.0.1 » n'existerait pas nativement.

**Deux taxonomies** (`skill_401`, `skill_402`) : duplication de la structure, et un troisième référentiel un jour imposerait du code.

**Code officiel dans la description du terme** : pas requêtable proprement (`get_terms` avec `meta_query` fonctionne sur les term metas, pas sur la description), et mélange une donnée technique avec un texte éditorial.

### Conséquences

Le pipeline Markdown résout un code en deux temps : le préfixe (`4.0.1`) désigne le parent, le code complet retrouve l'enfant via `meta_key = _portfolio_skill_code` ; s'il n'existe pas, l'import de la fiche échoue (note d'implémentation ci-dessous). Les archives WordPress fournissent gratuitement `/competences/4-0-1/` (tous les projets démontrant au moins une compétence du référentiel) et `/competences/4-0-1/c1-3/` (une compétence précise). L'API C# expose pour chaque compétence `{ slug, code, label }` comme prévu dans `02-api-csharp.md`, le code venant du term meta. ADR-011 reste accepté ; seule la forme concrète (hiérarchie, slug, term meta) est fixée ici. Reste à faire côté contenu : saisir ou importer la liste officielle des compétences des deux référentiels.

**Note d'implémentation (26 octobre 2026).** La création « à la volée » d'une compétence inconnue n'a pas été retenue : depuis que la grille `content/skills.yaml` porte les intitulés officiels et que `wp portfolio skills sync` la projette dans la taxonomie (ADR-015), un code que la taxonomie ne connaît pas est une erreur du document, pas une compétence à inventer. `wp portfolio import` refuse alors la fiche en listant les codes inconnus ; le remède est d'ajouter le code à la grille puis de resynchroniser. Le slug d'une compétence est fixé dans la grille (`04-structure-wordpress.md` §4.3), unique dans toute la taxonomie.

---

## ADR-015 — Contenu versionné dans le monorepo, grille de compétences et périmètre des fiches

**Date** : 2026-10-26
**Statut** : Accepté

### Contexte

Le modèle de contenu du plugin est en place (ADR-013, ADR-014) mais le site n'a encore aucun contenu, et le pipeline Markdown (phase 2) comme le webhook Git (phase 9) restent à construire. Morgan dispose pourtant de la matière : onze fiches projet détaillées qu'il a rédigées pour son CV, les exports de trois projets de formation, ses dépôts GitHub publics, le dépôt du fil rouge 4.0.2 en cours, les consignes officielles des deux fils rouges et la feuille de suivi des formateurs de première année. Quatre questions devaient être tranchées avant d'écrire la première fiche : où vivent les fichiers Markdown, quel projet porte le marqueur fil rouge 4.0.2, quels projets entrent dans le portfolio, et quelle liste de compétences alimente la taxonomie `skill`. Le frontmatter laissait aussi un champ `category` hérité de l'overview, en contradiction avec `04-structure-wordpress.md` §4.4.

### Décision

**Le contenu est versionné dans le monorepo**, sous `content/` : `content/projects/<slug>.md` pour les projets, `content/pages/` pour les pages statiques, `content/skills.yaml` pour la grille de compétences. `content/README.md` fixe le frontmatter, le patron narratif en trois temps (le problème, les décisions techniques, ce que j'en ai appris) suivi de « En chiffres » et « Liens », la première personne, et les règles de publication (aucune donnée client, aucun secret, aucun nom de tiers). Le champ `category` disparaît du frontmatter : les tags techniques (`tech_tag`) suffisent, la taxonomie native `category` n'est pas utilisée.

**MomoCad est le projet fil rouge 4.0.2** marqué `is_fil_rouge`, en tant que fil rouge de l'année 2026-2027. GeoForge (le jeu), fil rouge de l'année 2025-2026, est présenté comme un projet à part entière, achevé et soutenu. UHA Learn est le fil rouge de première année.

**Tous les projets entrent dans le portfolio** : les cinq de la formation (AUTOFIN Control, HEHEWATT, Trace 4.0, UHA Learn, MomoCad), les projets personnels sur GitHub (Atlas, GeoForge l'addon Godot, xvii, ancestors-modding), les projets Kovelt et open source (Vefta, carbon-fr, DevEye, chronos-penpot-tools), soit quatorze fiches ; le CV Typst alimente la page CV.

**La grille de compétences reprend les intitulés officiels de l'école quand ils existent.** Pour 4.0.1, la feuille de suivi des formateurs fournit vingt-neuf compétences réparties en trois familles : techniques, rattachées aux étapes du fil rouge (`4.0.1/FR-nn`), métier, évaluées sur les projets en équipe (`4.0.1/PM-nn`), transverses (`4.0.1/TR-nn`). Pour 4.0.2, à défaut de feuille de suivi pour l'année en cours, les étapes de la consigne du fil rouge tiennent lieu de grille (`4.0.2/Enn`, `4.0.2/Onn`) jusqu'à ce que les intitulés officiels soient disponibles. Une fiche ne cite que les compétences que le projet démontre concrètement. Les niveaux atteints par Morgan sont des données personnelles et ne sont pas versionnés.

### Alternatives considérées

**Dépôt de contenu séparé** : garde le contenu privé jusqu'à publication et isole les droits, mais impose un second clone, une seconde configuration de webhook et une synchronisation de versions entre code et contenu pour un auteur unique. Écarté ; le dépôt est public de toute façon.

**Contenu saisi directement dans WordPress** : évite tout fichier intermédiaire, mais contredit ADR-001 (le Markdown est la source) et laisserait le contenu sans historique ni relecture possible avant la phase 4.

**Une grille de compétences maison, orientée recruteurs** : plus lisible pour un employeur, mais le jury évalue sur son propre référentiel ; les intitulés officiels sont la seule base incontestable. Les compétences « métier » et « transverses » couvrent d'ailleurs ce qu'un recruteur cherche.

**Les étapes des fils rouges pour les deux années** : c'était le choix initial de la journée, remplacé pour 4.0.1 dès que la feuille de suivi officielle a été fournie ; il reste la solution d'attente pour 4.0.2.

**GeoForge (le jeu) comme fil rouge 4.0.2** : c'est bien le fil rouge de 2025-2026, achevé et plus complet que MomoCad à ce jour, mais Morgan est réinscrit en 4.0.2 pour 2026-2027 avec MomoCad ; le portfolio doit présenter le fil rouge courant, et le jeu garde sa fiche.

### Conséquences

Le dossier `content/` devient la source que le pipeline importera (phase 2) puis que le webhook synchronisera (phase 9) ; d'ici là, l'import passe par WP-CLI. Le parser de frontmatter devra lire les dates nues avec `Yaml::PARSE_DATETIME`, sans quoi `symfony/yaml` les convertit en entiers. Les fiches sont rédigées avec l'assistance d'agents sous relecture croisée et validées par Morgan avant fusion (ADR-012) ; un validateur de frontmatter et de structure accompagne le dossier. Les codes 4.0.2 changeront lorsque la feuille de suivi de deuxième année sera fournie : le term meta `_portfolio_skill_code` et les frontmatters seront alors mis à jour ensemble. `01-plugin-php.md` §4.4 et l'exemple de frontmatter de `00-overview.md` perdent le champ `category`.

---

## ADR-016 — Éditeur CodeMirror 6 avec prévisualisation rendue par le serveur

**Date** : 2026-10-27
**Statut** : Accepté

### Contexte

La métabox « Document Markdown » de l'admin (`01-plugin-php.md` §6.1) était un simple `<textarea>` : pas de coloration, aucun retour avant l'enregistrement, et une erreur de frontmatter ne se voyait qu'après coup, dans la notice du refus. Le plan d'origine laissait ouverts le choix de la librairie d'édition (EasyMDE ou CodeMirror 6) et celui de la prévisualisation : côté client, par un rendu Markdown en JavaScript forcément différent du pipeline PHP (CommonMark avec ses extensions GFM, coloration des blocs de code côté serveur, transformation en blocs Gutenberg, passage kses), ou côté serveur, par ce pipeline lui-même. Morgan a tranché le 27 octobre 2026.

### Décision

L'éditeur est CodeMirror 6, assemblé à partir de ses paquets (`@codemirror/state`, `view`, `commands`, `language`, `search`, `lang-markdown`, et `lang-yaml` pour le frontmatter) et construit avec esbuild en un seul script, `plugin/assets/dist/admin-editor.js`, non versionné (`npm ci && npm run build`). La prévisualisation est rendue par le serveur : `POST /wp-json/portfolio/v1/preview` (`Rest\Controller\PreviewController`, première route du namespace du plugin) passe le document par `Service\PreviewService`, donc par le parseur de frontmatter et le `ContentRenderer` de l'import, puis par le passage kses du stockage (`Storage\HtmlSanitizer`), le rendu des blocs et la typographie du site (`do_blocks`, `wptexturize`, `convert_smilies`), et renvoie ce HTML avec le titre, un résumé du frontmatter et la liste de ce que l'import refuserait. Le navigateur l'affiche dans un cadre `sandbox` qui ne charge que les feuilles de style des blocs du cœur. Le `<textarea>` reste le champ du formulaire et la solution de repli quand le bundle n'est pas construit.

### Alternatives considérées

- **EasyMDE** : un éditeur complet, barre d'outils et aperçu intégrés, mais son aperçu est un rendu Markdown JavaScript (marked) qui ignore la coloration serveur, les blocs, kses et les règles exactes de `league/commonmark` : il aurait menti par construction. EasyMDE repose en outre sur CodeMirror 5, maintenu pour la compatibilité seulement.
- **Textarea avec aperçu serveur seul** : le même aperçu exact sans éditeur riche. Moins de code et de poids, mais ni coloration, ni historique, ni recherche, et un frontmatter YAML illisible dans un long document.
- **Aperçu côté client avec CodeMirror 6** : écarté pour la même raison qu'EasyMDE, l'écart entre deux moteurs de rendu.

### Conséquences

L'aperçu montre ce que le site affichera, à deux écarts près, voulus : la feuille de style du thème (le cadre charge les styles des blocs, pas ceux de GeneratePress) et la chaîne `the_content`, dont la relecture du lot a montré qu'elle exécute les codes courts et les embeds de WordPress (requête HTTP sortante et cache oEmbed écrit en base pour une URL tapée par n'importe quel contributeur) : l'aperçu rend les blocs et la typographie, et montre un code court tel quel. Les liens de l'aperçu s'ouvrent dans un nouvel onglet sans `opener` ni referrer. Chaque pause de frappe coûte une requête REST authentifiée (nonce porté et renouvelé par `wp-api-fetch`) qui rend le document entier : acceptable pour des fiches de quelques dizaines de kilooctets, et borné côté serveur à un million de caractères. Le plugin a désormais une chaîne de construction JavaScript (Node 20+, esbuild, 63 paquets npm) à côté de Composer, un bundle de 560 Ko servi une fois puis mis en cache, et une suite de tests Node (`node --test`, jsdom). Les blocs de code ne sont pas colorés dans l'éditeur (ils le sont dans l'aperçu) : charger les grammaires à la demande demanderait un bundle en modules ES découpés, un levier gardé pour plus tard. Le premier endpoint du namespace `portfolio/v1` existe (`Rest\RouteRegistrar`), ce qui prépare la phase 5.

---

## ADR-017 — Les pages natives dans le pipeline, éditées comme leur document quand elles en portent un

**Date** : 2026-10-27
**Statut** : Accepté

### Contexte

Les pages `À propos` et `CV` existent dans `content/pages/` depuis ADR-015, avec un frontmatter réduit (`title`, `slug`, `type`, `date`, `seo_description`), mais `wp portfolio import` les refusait : les cinq champs internes qui rendent un import idempotent (ADR-005) et la description SEO n'étaient enregistrés que sur `article` et `project`, et le dépôt ne savait pas écrire le type natif `page`. `04-structure-wordpress.md` §3.4 laissait le choix ouvert entre enregistrer ces champs sur `page` et saisir les deux pages à la main. La page Contact, elle, se fera à la main dans l'éditeur de blocs : un formulaire, pas un document. Et la page CV renvoie aux fiches par des liens de fichier (`../projects/<slug>.md`), que le site ne sait pas suivre.

### Décision

Les pages entrent dans le pipeline, dans le type natif `page` : les cinq champs internes et `_portfolio_seo_description` sont enregistrés aussi sur `page` (`MetaRegistration::MARKDOWN_POST_TYPES`), le service accepte `type: page`, le dépôt écrit une page sans terme et sans les autres champs publics. Côté admin, la boîte « Document Markdown » et l'écran épuré s'appliquent **post par post** : à une page qui porte un document (son hash interne est écrit), jamais à une page faite à la main ; « Ajouter une page » garde l'éditeur de blocs. Une page documentée vit à la racine du site : sa boîte d'attributs (parent, modèle, ordre) est retirée et le dépôt remet son parent à zéro à chaque écriture. Coller une page demande `edit_others_pages` en plus de `edit_others_posts`. Les liens d'un document vers un autre sont réécrits en liens du site au rendu (`Parsing\RelativeLinks`), les bases d'URL (`articles`, `projets`, racine pour les pages) étant portées par `Domain\ContentType` et partagées avec le registrar des types.

### Alternatives considérées

- **Saisir les deux pages à la main** : zéro code, mais deux exemplaires à entretenir (le CV change souvent) et le Markdown cesse d'être la source pour elles (ADR-001).
- **Un troisième type personnalisé** : toute la mécanique du plugin, mais la perte des gabarits, menus et hiérarchie que le thème offre aux pages natives, pour deux pages.
- **La boîte sur toutes les pages** : plus simple que le choix post par post, mais elle aurait privé Contact, et toute page faite à la main, de l'éditeur de blocs.
- **Réécrire les liens au moment de l'affichage** (filtre `the_content`) plutôt qu'au rendu : le HTML stocké aurait gardé des liens morts, contraire à ADR-001, qui veut un `post_content` complet.

### Conséquences

Les deux pages s'importent, se relisent et se modifient comme les fiches, avec la même règle de conflit. Le type natif `page` porte désormais six champs du plugin ; une page documentée ne redevient pas une page ordinaire sans supprimer ces champs (à outiller si le besoin apparaît). `ContentRenderer::VERSION` passe à 1.1.0 : au prochain import, toutes les fiches sont re-rendues, sans changement visible hors la page CV, dont les liens vers les fiches fonctionnent. Les bases d'URL sont une donnée du domaine que le registrar des types et le réécriveur de liens partagent : les changer se fait à un seul endroit.

---

## ADR-018 — Endpoints REST publics en lecture : le catalogue des documents publiés

**Date** : 2026-10-29
**Statut** : Accepté (précisé par ADR-021 pour la langue)

### Contexte

La phase 5 de la feuille de route promettait cinq routes publiques (`01-plugin-php.md` §7) mais laissait ouverts le nom des paramètres, les statuts servis, la forme du `status` d'un projet et celle du sitemap, le sort des pages (entrées dans le pipeline par ADR-017, après la rédaction du §7) et celui d'un post publié sans document. Le document de l'API C# supposait déjà `page` et `per_page` et un sitemap interrogé toutes les trente secondes. Le dépôt de l'import (`Service\ContentRepository`) cherche un post sous un slug quel que soit son statut, corbeille comprise, pour appliquer la règle de conflit d'ADR-005 : réutilisé tel quel, il aurait servi des brouillons à un visiteur anonyme. Morgan a tranché les quatre points ouverts le 29 octobre 2026.

### Décision

La lecture publique a son propre port, `Service\ContentCatalog` (`list()`, `find()`, `sitemap()`), son propre objet, `PublishedDocument`, et son propre adaptateur, `Storage\WordPressContentCatalog`, sur `WP_Query`, qui ne lit que les posts publiés, sans mot de passe, qui portent un document (`_portfolio_md_hash` non vide). Sept routes sous `portfolio/v1` : `/articles`, `/projects` et `/pages` (les pages documentées seulement : le front React aura besoin d'À propos et du CV, et le Markdown reste la source), chacune avec sa route `/{slug}`, et `/sitemap` ; un seul `Rest\Controller\CollectionController` instancié par collection. La pagination suit la convention de l'API native, que la passerelle C# connaît : le corps est la liste nue, `page` et `per_page` (10 par défaut, 100 au plus), les totaux dans `X-WP-Total` et `X-WP-TotalPages`, les voisines dans `Link`, une page au-delà de la dernière en 400 comme les collections natives, un slug sans document publié en 404 `portfolio_not_found`. Les champs sont ceux du §7.3 plus `link` (le permalien) ; un optionnel vide vaut `null` ; `status` est un objet `{slug, label}` comme `tags` et `skills` ; `date` et `updated` viennent de `post_date_gmt` et `post_modified_gmt`, en ISO 8601 UTC. Les filtres sont des slugs de termes : `tag` (articles, projets), `status` et `skill` (projets ; le slug d'un référentiel sélectionne ses compétences). Le sitemap est groupé par collection, `{articles, projects, pages}`, chaque entrée `{slug, updated}`, les trois clés toujours présentes. Explicitement reportés : `ETag`, `Last-Modified` et `304`, la limitation de débit côté plugin, les agrégats par tag ou par compétence ; l'API REST native reste ouverte telle quelle.

### Alternatives considérées

- **Étendre `ContentRepository` d'une méthode de liste** : un port de moins, mais un port formé par la règle de conflit (hash, provenance, corbeille) qui aurait porté aussi la pagination et les filtres d'un lecteur, et une doublure de test grossie d'autant.
- **Une enveloppe JSON `{items, total, total_pages}`** : une désérialisation un peu plus directe en C#, mais hors convention WordPress, et le §7.1 écrivait déjà que le corps est la liste.
- **Un sitemap plat `[{type, slug, updated}]`** : une seule boucle côté consommateur, mais une clé composite à gérer, alors qu'un slug n'est unique que dans sa collection.
- **Servir un post sans document avec `markdown: null`** : une requête plus simple, mais une API de Markdown qui servirait des posts sans Markdown ; par construction (`Admin\EditScreen` renvoie en brouillon un post qui n'a jamais tenu de document), un article ou un projet publié porte toujours un document, et l'exclusion est la règle honnête.
- **Les pages hors périmètre, à la lettre du §7** : le front aurait dû lire leur HTML par `wp/v2/pages`, contre ADR-001.
- **Des contrôleurs héritant de `WP_REST_Controller`** : un schéma auto-décrit, mais une classe de base lourde et un écart de style avec `PreviewController` ; `_fields` fonctionne de toute façon, le serveur l'applique à toute route.

### Conséquences

La passerelle C# a un contrat stable et nommé ; `02-api-csharp.md` est corrigé (le filtre « catégorie » avait disparu avec ADR-015, les paramètres sont ceux du plugin, les agrégats n'ont pas de source côté plugin). Une seconde classe de lecture parle à WordPress ; comme les deux autres, elle est couverte par un test de fumée (`rest-smoke.php`), pas par la suite unitaire, qui couvre les contrôleurs et le présentateur avec un catalogue en mémoire. Le Markdown source est public, frontmatter compris : c'est le contenu de la page publique, et l'objet même de l'API. L'heure d'une `date` est minuit, heure du site, converti en UTC, et non un instant de publication : le frontmatter ne porte qu'un jour. Deux surfaces publiques coexistent, celle du plugin (Markdown) et la native (HTML) ; les restreindre serait une décision à part. Restent à faire, quand le besoin apparaîtra : les en-têtes de cache conditionnel, une limitation de débit, puis `/sync` (phase 9).


ADR-020 ajoute à ce contrat un paramètre de langue explicite, `language`, et un repli par langue ; ADR-021 en fixe la forme : les routes et les clés décrites ici restent, cinq clés s'ajoutent après `slug`, et le paramètre absent vaut `fr` (il n'y a plus de lecture « toutes langues mêlées » hors du sitemap, dont chaque ligne gagne `lang` et `translation_key`).
---

## ADR-019 — La porte Git s'authentifie comme un utilisateur et ne force rien

**Date** : 2026-10-29
**Statut** : Accepté

### Contexte

Le plan d'avril (`01-plugin-php.md` §6.2 et §11) authentifiait le webhook Git par un jeton Bearer dans une constante de `wp-config.php`, comparé par `hash_equals()`, la route opérant alors « en super-admin ». Trois critiques indépendantes de la conception ont montré ce que ce choix entraînait : il faut inventer un auteur pour les posts créés (une seconde constante), et si cet auteur n'a pas `unfiltered_html`, WordPress rebranche un passage kses plus strict que celui du dépôt et les cases à cocher des listes de tâches disparaissent ; il faut imposer la longueur du jeton et HTTPS dans le code, lire l'en-tête `Authorization` de façon défensive selon le serveur, et le jeton partagé devient le premier chemin HTTP capable de supprimer du contenu. Le payload laissait aussi ouverts le sort d'un fichier supprimé, le `force` (ADR-005 en fait un acte humain) et la correspondance entre le dossier d'un fichier et le type de son frontmatter. Morgan a tranché le 29 octobre 2026.

### Décision

La porte Git s'authentifie par un **mot de passe d'application WordPress** en Basic auth : le cœur vérifie le secret et ouvre la requête au nom de l'utilisateur, qui devient l'auteur des posts créés ; la route demande à cet utilisateur `edit_others_posts`, `edit_others_pages`, `manage_categories` (la grille écrit les termes de la taxonomie) et `unfiltered_html`. Il n'y a ni constante ni classe de vérification de jeton dans le plugin ; la stack locale déclare `WP_ENVIRONMENT_TYPE=local` (via `WORDPRESS_CONFIG_EXTRA`) pour que WordPress accepte ces mots de passe sans HTTPS. **Il n'y a pas de `force` sur la route** : un conflit répond 409, fait échouer le job de CI, et se résout à la main (admin, page de collage, `wp portfolio import --force`). **Git reprend ce que Git a écrit** : un document supprimé met son post à la corbeille seulement si la dernière écriture venait de Git ; un post écrit depuis l'admin ou un collage, ou fait à la main, répond `conflict` ; déjà à la corbeille, `unchanged` ; sans post, `skipped`. Le dossier d'un fichier doit être celui de son type (`ContentImportService::import()` reçoit le type attendu, la commande WP-CLI aussi). La grille `skills.yaml` est synchronisée en premier dans un lot. Les chemins sont relatifs à `content/`, les lots bornés à cent fichiers, les codes HTTP 200/409/422 disent s'il faut regarder, la dernière synchronisation est consignée dans l'option `portfolio_md_last_sync`. Le côté Git est un workflow GitHub Actions (`sync-content.yml`) et un script Python partagé avec l'usage local (`.github/scripts/sync-content.py`), inertes tant que les trois secrets du dépôt n'existent pas.

### Alternatives considérées

- **Le jeton Bearer dans une constante, comme prévu** : indépendant des utilisateurs, mais une seconde constante pour l'auteur, des contrôles de longueur et de HTTPS à écrire, une lecture défensive de l'en-tête, et un auteur qui doit avoir `unfiltered_html` ; plus de code et de configuration pour un résultat équivalent.
- **Un `force` global dans la requête, « à la main par curl seulement »** : repose sur la discipline de toute édition future du workflow ; ADR-005 réserve le forçage à un humain qui regarde.
- **Supprimer sans condition** : simple, mais la porte que la règle de conflit avait oubliée : un fichier supprimé aurait mis à la corbeille un post édité dans l'admin.
- **Ignorer les suppressions en v1** : plus prudent, mais un fichier renommé laisse l'ancien post publié jusqu'à une intervention manuelle, pour un gain de sûreté que la règle « Git reprend ce que Git a écrit » apporte déjà.
- **Détecter les renommages** (`R` dans le diff) : exigerait une opération de renommage que le dépôt n'a pas et que le service d'import refuse (un document garde le slug écrit en lui) ; `--no-renames` donne une suppression plus une création, que le plugin traite déjà.

### Conséquences

Trois des quatre points de la conception d'avril changent (`01-plugin-php.md` §6.2 et §11 réécrits, `04-structure-wordpress.md` §6) ; le payload perd `source` et gagne `dry_run`. La sécurité repose sur un mécanisme du cœur, révocable depuis le profil, et sur des capacités WordPress ordinaires : un mot de passe d'application qui fuit permet d'écrire et de retirer ce que Git a écrit, pas d'écraser ce qu'un humain a écrit. Le dépôt gagne une méthode `trash()` sur son port, la seule qui retire un post, et jamais pour de bon. Le workflow restera inerte jusqu'au déploiement (phase 11) : le chemin complet a été joué en local, avec un mot de passe d'application créé puis supprimé par WP-CLI. La résolution d'un conflit est documentée mais pas outillée : si elle devient fréquente, un bouton « reprendre la version Git » dans la boîte de l'admin serait le prochain pas. Reste l'import des images (§8), seule partie du plan de départ encore à construire côté plugin.

---

## ADR-020 — Site trilingue : Polylang gratuit, un document par langue, préfixe d'URL dès le premier déploiement

**Date** : 2026-11-07
**Statut** : Accepté (confirmé par le spike du lot 0 le 7 novembre 2026, `spikes/2026-11-07-polylang.md`)

### Contexte

Le 7 novembre 2026, Morgan a posé que le site doit servir le français, l'allemand et l'anglais « au besoin de la personne qui le consulte », et ce pour tout le contenu : jury francophone, recruteurs suisses et allemands, recruteurs internationaux, avec un CV PDF par langue produit hors dépôt. Le dépôt est monolingue de bout en bout : la liste fermée des clés de `Domain\Frontmatter` refuse toute clé `lang`, l'identité d'un document est le couple (type, slug) et WordPress impose un slug unique par type de post, `ContentType::path()` ne connaît aucun préfixe de langue, la profondeur « dossier/fichier » du contenu est imposée par `Service\ContentSyncService` et par `.github/scripts/sync-content.py`, et le sitemap d'ADR-018 comme les caches prévus de l'API C# et du front raisonnent sur le slug seul. ADR-007 règle la langue du code et des documents, pas celle du site. Aucune URL publique n'existe avant la phase 11 : c'est le moment le moins coûteux pour fixer le schéma d'URL, alors qu'un préfixe ajouté après indexation imposerait des redirections partout. Le contenu pèse environ 21 000 mots par langue cible. Trois conceptions indépendantes (WordPress natif d'abord, langue comme axe du modèle de contenu, échéance d'abord) ont été jugées sous trois lentilles puis réfutées point par point ; le brief complet est conservé hors dépôt.

### Décision

Polylang gratuit, version épinglée, en mode répertoire avec le préfixe français visible : `/fr/`, `/de/`, `/en/`. Le contenu passe en `content/<lang>/<dossier>/<slug>.md` ; chaque document porte `lang` (obligatoire, égal au dossier) et `translation_key` (obligatoire pour `de` et `en`, optionnelle pour `fr` où elle vaut le slug), et reste autosuffisant : un fichier traduit contient tout son frontmatter, rien n'est copié depuis l'original à l'import. Slugs : uniques par type ; fiches et articles non français suffixés `-de` ou `-en`, et un slug français ne finit jamais par ces suffixes ; pages à slugs naturels distincts par langue (`cv`, `lebenslauf`, `resume` ; `a-propos`, `ueber-mich`, `about`), jamais identiques entre langues. Le plugin reste maître de la langue : il la stocke dans ses propres meta (`_portfolio_lang`, `_portfolio_translation_key`) et la pousse chez Polylang à chaque écriture à travers une interface `LanguageGateway` (adaptateurs Polylang, nul et en mémoire) ; il ne décide jamais de la langue d'après Polylang (il ne la lit que pour constater et corriger : `LanguageInspector`, lot 2). Les trois langues existent dès le départ ; une langue est ouverte au public par l'option `portfolio_md_published_languages` quand l'accueil, À propos, CV, Contact et le fil rouge sont traduits. Tout le contenu est destiné à exister dans les trois langues ; en attendant, un document manquant est servi en repli, de vers en puis fr, signalé comme tel. REST et API C# : un paramètre de langue explicite nommé `language` (Polylang lit `lang` sur toute route REST ; sa version gratuite ne filtre pas encore sur ce paramètre, la Pro le fait, et le nom distinct découple nos routes), jamais `Accept-Language`, la langue dans toutes les clés de cache, la détection du navigateur limitée à l'accueil. Les bases d'URL `projets` et `articles` restent françaises sous `/de/` et `/en/`. Les intitulés officiels des compétences 4.0.1 et 4.0.2 sont traduits et marqués non officiels tant que l'UHA n'en fournit pas de version. Git est seul écrivain des traductions. Les PDF du CV sont déposés dans la médiathèque WordPress sous un nom fixe par langue.

### Alternatives considérées

- **Polylang gratuit avec un fichier de traduction réduit aux champs traduisibles** : surface minimale, mais un fichier refusé par le parseur (`status` obligatoire pour un projet) et une propagation des champs à maintenir à chaque import.
- **Un module de langue maison** : même slug dans les trois langues, mais un moteur multilingue à maintenir seul, bâti sur un filtre de slug non vérifié, et plus rien de « WordPress standard » à montrer au jury.
- **Polylang Pro** : slugs partagés et bases d'URL traduites, mais payant ; reste l'échappatoire si les bases françaises sous `/de/` et `/en/` gênent.
- **WPML, TranslatePress, multisite, qTranslate-XT** : coût, table de liaison propriétaire, traduction du HTML rendu inadaptée à une source Markdown, ou multiplication des bases de données.
- **Le français seul d'abord, sans préfixe** : plus rapide, mais ajouter `/fr/` après indexation impose des redirections partout.

### Conséquences

Seize fichiers sont déplacés vers `content/fr/` et reçoivent `lang: fr` ; leurs hash changent et les seize posts passent en `updated`. Un chemin de profondeur 2 est désormais refusé en `skipped`. Le déplacement arrive dans la porte Git comme seize suppressions plus seize créations (`git diff --no-renames`) : sur l'ancien plugin, les seize posts partiraient à la corbeille et chaque slug serait ensuite bloqué par `ImportException::inTrash`. D'où l'ordre impératif : le plugin à profondeur 3 est déployé et testé avant que la migration n'atteigne `main`, avec un `sync --dry-run` entre les deux. `ContentRenderer::VERSION` monte et `render` reçoit la langue du document. La langue est stockée deux fois, chez nous et chez Polylang, et Polylang peut la modifier par sa boîte, sa colonne, sa modification rapide ou en masse : `wp portfolio translations reconcile` est la garantie, pas le masquage de l'interface ; la boîte Markdown de l'admin devient lecture seule pour les traductions. Les slugs suffixés sont durables. Les archives des taxonomies du plugin, non traduites, n'ont pas d'URL par langue : 404 sous `/de/`, une seule URL sans préfixe dont le contenu suit la langue du cookie ou du navigateur ; traduire les termes chez Polylang, mettre ces archives en `noindex` ou les désactiver est à trancher au lot 5. Le minimum WordPress de la stack monte à ce qu'exigent Polylang (6.8 pour la 3.9) et Yoast SEO (7.0 pour la 28.8), et les images Docker sont épinglées. Le volume de traduction dépasse ce que le repli masque : l'ouverture d'une langue est conditionnée à sa relecture par Morgan. Les comportements de Polylang sur lesquels cette décision s'appuie (langues et réglages créés par code, langue attribuée aux types de contenu custom par `pll_set_post_language` et jamais automatiquement à l'import, liaison des traductions par l'API, suffixe `-2` du noyau sur un slug dupliqué, hreflang de Polylang seul et sans doublon avec Yoast, anciennes URL sans préfixe redirigées en 301, détection du navigateur sur `/` seulement) ont été confirmés par le spike du 7 novembre 2026 (`spikes/2026-11-07-polylang.md`), avec deux nuances : une langue sans contenu est déjà masquée par Polylang à la détection du navigateur, et les robots sont redirigés sur `/` comme les visiteurs, avec `Vary: Accept-Language`. Les documents 00, 01, 02, 03, 04 et `content/README.md` sont mis à jour lot par lot, au fil de l'implémentation.

**Notes d'implémentation.** *Lot 1a (7 novembre 2026, PR #21)* : `Domain\Language`, `Domain\ContentPath`, les clés `lang` et `translation_key` de `Domain\Frontmatter`, le validateur `Validation\ContentValidator` (`plugin/tools/validate-content.php`) et l'arborescence par langue de `content/README.md` ; `lang` reste facultatif (vaut `fr`) jusqu'à la migration du contenu. *Lot 1b (7 novembre 2026)* : les portes. Ce qui a été tranché en route, au-delà de la décision :

1. La langue sort du plugin par un **port**, `Service\LanguageGateway` (`setLanguage`, `linkTranslations`) : adaptateur `NullLanguageGateway` en production jusqu'au lot 2 (puis `Storage\PolylangLanguageGateway`), en mémoire dans les tests. Le plugin ne lit jamais la langue chez Polylang (précisé au lot 2 : la réconciliation la lit pour constater et corriger, jamais pour décider).
2. La passerelle est appelée après chaque écriture **et pour un document inchangé** (hors `dry_run`) : un redéploiement ou un replay remet Polylang d'aplomb sans toucher au hash.
3. **Un post garde sa langue** : un document dont la `lang` diffère de celle du post qui porte son slug est refusé (`ImportException::languageOfPostDiffers`) ; la langue d'un post se change par le dépôt (le fichier supprimé, un autre écrit sous un autre slug, puisqu'un slug dont le post est à la corbeille reste bloqué), jamais par une réécriture. Un post écrit avant ce lot, sans langue stockée, prend celle de son prochain import.
4. La clé de traduction n'est **jamais vide en base** : en français elle vaut le slug. `ContentRepository::findByKey()` retrouve les posts d'une clé, du même type, corbeille exclue, du plus ancien au plus récent ; les traductions ne sont reliées que lorsqu'au moins deux langues existent. Depuis le lot 2, les requêtes du dépôt passent `lang => ''` à `WP_Query`, sans quoi Polylang filtrerait `findByKey()` sur la langue courante et ne verrait jamais deux langues.
5. `ContentRecord` (l'écriture) ne change pas : le dépôt écrit les deux metas depuis le frontmatter. `StoredContent` (la lecture) gagne `lang` et `translationKey`, nuls pour un post d'avant ce lot.
6. Les portes lisent la langue dans le chemin, `content/<lang>/<dossier>/<slug>.md` : la porte Git **ignore** (`skipped`) un chemin de profondeur 2, suppression comprise, et `wp portfolio import` le **refuse** avec le déplacement à faire. La migration de `content/` est donc un `git mv` strict vers `content/fr/` (même contenu, même slug ; le renderer 1.2.0 fait passer chaque post en `updated` sans toucher au hash) et son ordre est : déployer ce lot, `sync --dry-run`, pousser la PR de migration aussitôt, puis le lot 2. La fenêtre où un post sans langue stockée serait réécrit depuis l'admin avant la migration est assumée.
7. Deux garde-fous au retrait d'un document, le second posé après relecture : le suffixe du slug pour les fiches et les articles (un slug qui n'appartient pas à la langue de son dossier ne retire rien) et la langue stockée du post pour tous les types (un `en/pages/cv.md` égaré ne retire pas la page française `cv` ; un post sans langue stockée, d'avant ce lot, est retiré sur la foi du dossier seul) ; type et conflit suivent les règles existantes.
8. L'interdiction d'importer un document `de` ou `en` avant ce lot (`languageNotYetSupported`, lot 1a) est levée : la règle du dossier la remplace, et la boîte Markdown de l'admin refuse déjà de changer la langue d'un post.

*Lot 1c, migration du contenu (7 novembre 2026)* : (1) les seize fichiers sont passés à `content/fr/` avec `lang: "fr"` (`git mv` puis ajout de la ligne après `type`, en un seul commit) ; la ligne ajoutée change les hash, et le renderer 1.2.0 aurait de toute façon fait re-rendre chaque post : seize `updated`, aucune corbeille. Cela remplace la note 6 du lot 1b (« `git mv` strict, hash inchangé ») et la mention « `lang` reste facultatif (vaut `fr`) jusqu'à la migration » du lot 1a. (2) `lang` est obligatoire dans `Domain\Frontmatter` (message `missing required key "lang"`) ; `translation_key` reste facultatif en français (il vaut le slug) et obligatoire en de et en ; les gabarits de l'admin (boîte d'un nouveau post, page de collage) portent `lang: "fr"`. (3) Le code de transition est retiré : `lang` absent valant `fr`, `translation_key` sans `lang`, la disposition « avant migration » du validateur et le champ `layout` de son rapport. Un document sans ligne `lang` est désormais refusé par toutes les portes ; un post sans langue stockée (fait à la main, ou pas encore importé depuis le lot 1b) reste toléré et prend la langue de son prochain import ; un post écrit depuis l'admin avant la migration, dont le document stocké n'a pas de ligne `lang`, ne se sauvegarde plus depuis la boîte tant que la ligne n'y est pas ajoutée : fenêtre assumée, la synchronisation l'ajoute à tout post écrit par Git. (4) Restent, reformulés en règles permanentes et partageant le préfixe « not a document path: documents live in content/<lang>/ » (la porte Git y ajoute le dossier réel, `projects/` par exemple ; les deux autres écrivent `<folder>/`), le refus de `wp portfolio import`, le `skipped` de la porte Git (suppression comprise : défense en profondeur contre un script en retard ou un appel direct) et l'avertissement du script de synchronisation, hors suppressions (un chemin supprimé n'a plus à être déplacé). Le script filtre déjà ces chemins côté client : la poussée de la migration n'atteint jamais la garde du plugin, que les tests unitaires et le script de fumée exercent. (5) Répétition sur le site local, seul WordPress vivant, avec le vrai script (`sync-content.py --all --dry-run` puis réel) : 16 `updated`, 1 `unchanged` (la grille), 0 corbeille, 0 création ; chaque post porte `_portfolio_lang = fr`, `_portfolio_translation_key` égal à son slug et le rendu 1.2.0, les quinze liens du CV sont sous `/fr/projets/`, un second passage donne 17 `unchanged`. (6) Aucun site de production n'existait (aucun secret du dépôt, `voltz.dev` parqué) : l'ordre « déployer le plugin avant la migration » n'avait pas de cible. À la phase 11 : déployer le plugin de `main`, créer les trois secrets, puis lancer une fois le workflow à la main (*Run workflow*, tout le contenu), car un push n'envoie que son propre delta ; attendu : dix-sept `created`, la grille puis les seize documents. (7) Les corrections de la page CV et de la page À propos (cursus en DU, ligne de langue allemande) sont une PR de contenu séparée. Reste connu : `ContentType::path()` garde un paramètre de langue optionnel et un docblock d'avant ADR-020.

*Lot 2, pont Polylang (7 novembre 2026)* : (1) `Storage\PolylangLanguageGateway` réalise le port `LanguageGateway` et le port de lecture `LanguageInspector` ; il vérifie la présence de l'API de Polylang **à chaque appel** (le plugin se charge avant que `api.php` n'existe, et une requête publique sur un site sans langue n'a pas de contexte Polylang) et ne fait rien quand elle manque ; la racine de composition le câble une fois. Une langue que le site n'a pas lève `LanguageGatewayException` (un slug inconnu effacerait la langue en répondant `true`), après le post écrit : un rejeu guérit une fois les langues créées. (2) Les types `article` et `project` sont déclarés à Polylang par le filtre `pll_get_post_types` (`Storage\PolylangPostTypes`), du code plutôt que de la donnée : un site neuf n'a rien à régler, ce qui revient sur la ligne du spike du lot 0 (« `post_types` suffit, pas de filtre à écrire ») ; l'option reste tolérée. (3) Toutes les requêtes du plugin passent `lang => ''` (dépôt, catalogue public, lister de la réconciliation) : Polylang tient la requête pour déjà filtrée ; le catalogue sert toutes les langues jusqu'à ADR-021 (lot 3). (4) Git est le seul écrivain des traductions, au-delà du texte de l'ADR : le service refuse un document non français venu de l'admin ou d'un collage (dry-run compris, `ImportException::translationFromGit`), la boîte montre une traduction en lecture seule (textarea `readonly`, éditeur non éditable, pas de nonce, notice en français) et son handler refuse une sauvegarde par défense en profondeur ; la boîte de publication reste active : dépublier ou mettre à la corbeille une traduction depuis l'admin dérive de Git, risque accepté. (5) `wp portfolio translations reconcile [--dry-run] [--format=table|json]` est la garantie annoncée : une passe des langues puis une passe des groupes (règle `TranslationGroup`, corbeille exclue), un post sans langue stockée ignoré et jamais adopté depuis Polylang, deux posts vivants partageant clé et langue signalés et laissés, `--dry-run` en erreur dès qu'une différence existe ; c'est la seule lecture de Polylang par le plugin, pour constater et corriger, jamais pour décider. Faits de Polylang 3.9 vérifiés dans ses sources et sur la stack : `pll_set_post_language` répond `false` quand la langue est déjà la bonne ; `pll_save_post_translations` rejette au `save` un membre dont la langue n'est pas sa clé, s'ancre sur le premier membre et, pour un groupe d'un seul, ne fait que retirer les absents de la description en les détachant du terme (le membre retiré ne voit plus que lui-même, le survivant garde un terme à un membre) ; d'où les langues posées d'abord, le français en tête pour un ancrage déterministe, la relecture de la vue de chaque membre, et « une vue d'un seul = pas de groupe ». Un retrait par la porte Git ne délie pas le groupe : seule la réconciliation le dissout. (6) Les langues du site sont créées par un script suivi (`plugin/tools/polylang-setup-languages.php`, lancé par `bootstrap.sh` et une fois à la main en production avant la première synchronisation), pas par le plugin : `bootstrap.sh` annonçait le contraire, corrigé. (7) Vérifié sur la stack locale : synchronisation complète sans changement (17 `unchanged`), réconciliation à zéro différence sur les seize posts, une traduction allemande réelle importée par WP-CLI (langue `de`, groupe vu des deux côtés, URL `/de/projets/momocad-de/` en 200, la liste REST des projets la montrant avec les françaises), dérive de langue reposée par la commande, retrait par la porte Git puis groupe résiduel dissous ; les liens `hreflang` n'ont pas été observés dans ce passage (le spike du lot 0 les avait vus). Restent, documentés : la boîte de langue de Polylang elle-même n'est pas masquée ; un post fait à la main (sans langue stockée) qu'un collage français ferait basculer ; la collision de slug d'une page `de` et d'une page `fr` (suffixe `-2` du cœur, d'où les slugs naturels distincts).

*Lot 4, les libellés par langue (8 novembre 2026)* : (1) Les libellés de statut sont une table 4 × 3 dans `Domain\ProjectStatus::label(Language)` (En cours / In Arbeit / In progress, Stable / Stabil / Stable, Archivé / Archiviert / Archived, Prototype / Prototyp / Prototype) : du vocabulaire d'interface, donc du code, pas une traduction stockée qu'une synchronisation pourrait laisser derrière ; sans argument, le français, que l'admin et les termes de la taxonomie continuent d'afficher. (2) La grille `content/skills.yaml` porte les langues : `official` par référentiel (vrai pour 4.0.1, dont les intitulés français sont ceux de la feuille de suivi ; faux pour 4.0.2, écrits d'après la consigne du fil rouge) et un bloc `i18n` par compétence, `de` et `en` obligatoires (`name`, 200 caractères au plus, la taille d'un nom de terme). La grille est complète ou refusée, un intitulé traduit ne peut pas se dire `official` (le chargeur le refuse en toutes lettres) et toute clé inconnue est refusée, à tous les niveaux ; `tools/validate-content.php` passe la grille par le chargeur du plugin avant les documents. Les 47 intitulés allemands et anglais sont des traductions maison, à relire par Morgan. (3) `wp portfolio skills sync` écrit trois term metas de plus, `_portfolio_skill_name_de`, `_portfolio_skill_name_en` et `_portfolio_skill_official` (absent vaut faux ; rien sur un référentiel), les compare à la grille (`translations`, `official` dans les lignes du rapport) et les restaure si un terme les a perdus : 47 `updated` au premier passage après le déploiement, 49 `unchanged` ensuite. (4) Le catalogue lit ces metas dans le cache déjà amorcé pour les codes, sans requête de plus, et `Service\DocumentSkill` porte tous les intitulés d'une compétence ; le **présentateur** choisit celui de la **langue demandée** (`requested_language`), le long de sa chaîne de repli, français en dernier : un lecteur allemand servi en repli français voit un document français entouré de libellés allemands, parce que les libellés sont le vocabulaire de la page, pas le document ; un terme sans traduction (fait à la main, ou pas resynchronisé) montre son intitulé français. (5) Une seule clé ajoutée au contrat de §7.3 : `skills[].official`, vrai pour le seul intitulé français d'un référentiel officiel, jamais pour une traduction ; `label` et les cinq clés de langue ne bougent pas, ce qui est la lettre d'ADR-021 à une clé près, ajoutée pour que le front n'ait pas à deviner quels intitulés sont ceux de l'école. Les noms d'étiquettes (`tags[].name`) restent ceux des termes. Cela remplace l'hypothèse 8 d'ADR-021 (« `status.label`, `skills[].label` et noms d'étiquettes viennent des termes français ») pour les deux premiers ; elle reste vraie pour les noms d'étiquettes. (6) En production : déployer, puis `wp portfolio skills sync` (ou un push de la grille), sinon les lecteurs allemand et anglais lisent les intitulés français, sans erreur. Vérifié : 734 tests unitaires ; `skills-smoke` (metas, référentiel sans meta de langue, terme dépouillé restauré), `rest-smoke` (clés, libellés en allemand et en anglais, libellés de la langue demandée sur un repli français, liste allemande) et `i18n-smoke` verts sur la stack locale ; contrôle HTTP des trois langues sur les fiches réelles.

ADR-021 (lot 3, la langue dans les routes REST) : voir ci-dessous.

---

## ADR-021 — La langue dans les routes REST de lecture

**Date** : 2026-11-07
**Statut** : Accepté (hypothèse 8 remplacée le 8 novembre 2026 par le lot 4 d'ADR-020 pour `status.label` et `skills[].label`, servis dans la langue demandée ; voir la note d'implémentation du lot 4 sous ADR-020)

### Contexte

L'API publique de lecture (ADR-018) sert les trois collections et le sitemap sans notion de langue : le catalogue mêle les langues (`lang => ''`, lot 2 d'ADR-020) et la représentation ne dit pas dans quelle langue est un document. ADR-020 a fixé les principes : un paramètre explicite nommé `language` (jamais `lang`, que Polylang lit sur toutes les routes REST ; jamais `Accept-Language`), la langue dans toutes les clés de cache, un repli « de vers en puis fr » signalé comme tel, les bases d'URL `projets` et `articles` restant françaises. Il reste à fixer la forme exacte du contrat.

Vérifié dans les sources de Polylang 3.9.1 et sur la pile locale (contenu français seul) : sur une route REST, Polylang ne lit que `lang` (`PLL_REST_Request::set_language`), ne filtre aucune requête dans sa version gratuite (`PLL_Query` n'est jamais instanciée en REST), et `get_permalink` suit la langue du **document**, jamais celle de la requête. `?lang=de`, `?lang=xx` et `?language=de` donnent aujourd'hui la même réponse, octet pour octet. Ce que cela prouve : `lang` ne change ni les requêtes du plugin (qui passent `lang => ''`) ni ses liens. Ce que cela ne prouve pas : le comportement d'une requête sans `lang => ''` ; le plugin n'en dépend pas. Le lien d'un document **allemand** (`/de/projets/…`) n'est vérifié qu'avec l'import temporaire d'une traduction, prévu au contrôle local du lot. Aucun consommateur (API C#, front React) n'existe encore.

Ordre des lots : le brief plaçait le lot 4 (libellés par langue) avant le lot 3 ; le lot 3 passe ici en premier. Les libellés (`status.label`, `skills[].label`) restent donc français ; le lot 4 les traduira dans le même présentateur **sans changer les clés**.

### Décision

Les routes de collection et de détail acceptent `language=fr|de|en` (minuscules ; toute autre valeur, y compris vide ou `DE`, donne `400 rest_invalid_param`). **Paramètre absent = `fr`** : il n'existe plus de mode « toutes langues mêlées ». Chaque document porte cinq clés de plus, juste après `slug` : `lang`, `translation_key`, `translations` (langue → slug), `is_fallback`, `requested_language`. Une liste est ancrée sur les documents français (une entrée par groupe de traduction) et remplace chaque entrée par le membre de la langue demandée, ou à défaut par le repli `de → en → fr`, `en → fr`, `fr → fr`. Un détail ne répond jamais 404 pour un slug existant : il sert le membre du groupe dans la langue demandée, sinon le repli, sinon le document du slug lui-même, avec `is_fallback: true`. Le sitemap reste une route unique, sans paramètre ; ses lignes gagnent `lang` et `translation_key`. Le plugin n'ajoute aucun en-tête de cache ; les consommateurs mettent la langue dans toutes leurs clés. L'option `portfolio_md_published_languages` reste au lot 5.

Cette décision **précise** la note d'ADR-018 héritée d'ADR-020 (« les routes et les champs restent valables tels quels quand le paramètre est absent ») : les clés existantes sont inchangées et gardent leur ordre, les cinq clés sont un ajout pur, mais « tels quels » ne tient plus à la lettre, ni pour le contenu d'une liste dès qu'une traduction existe (voir Alternatives).

### Contrat détaillé

1. **Paramètre.** Schéma `enum` tiré de `Language::values()`, sans défaut dans le schéma (le contrôleur applique `fr` si absent). `language[]=de` donne 400 (`rest_invalid_type`). Le plugin ne lit jamais `lang` ni `Accept-Language` ; si les deux paramètres arrivent ensemble (`?lang=fr&language=de`), `language` seul compte. L'en-tête `Link` de pagination reprend `language` uniquement s'il figurait dans la requête. Le sitemap ignore `language` (paramètre non déclaré). Un chemin REST préfixé (`/de/wp-json/…`) renvoie une page 404 HTML (observé) : l'API C# utilise l'URL de base **sans préfixe de langue**.
2. **Représentation.** `lang` = langue du document servi ; `translation_key` = slug du document français (ADR-020) ; `translations` = objet `langue → slug` des documents publiés du groupe, document servi inclus, ordre `fr, de, en`, toujours un objet JSON (jamais `[]`) ; `is_fallback` = `lang` différent de `requested_language` ; `requested_language` = la langue demandée, toujours une chaîne. Le front compare `lang` et `requested_language` : un repli peut aller dans les deux sens (un document allemand sans original français, demandé en `fr`, sort avec `lang: "de"`, `is_fallback: true`). `link` reste le permalien WordPress du document servi, donc dans **sa** langue : un repli garde son préfixe `/fr/`. Le marqueur est dans le corps parce qu'il voyage avec le document mis en cache et reste lisible avec `_fields` (qui ne doit pas retirer les cinq clés côté consommateur) ; un en-tête n'est pas conservé avec le corps par un cache d'objets.
3. **Détail.** Le slug désigne un document, donc un groupe (type + `translation_key`). Membre dans la langue demandée, sinon premier membre existant de la chaîne, sinon le document du slug (`is_fallback: true`). Slug inconnu : `404 portfolio_not_found`. `momocad-de` demandé avec `language=fr` répond `momocad`, sans repli. Si le document servi a perdu le concours des doublons (ADR-020 : le plus ancien gagne), `translations` porte quand même son propre slug pour sa langue. L'unicité des slugs reste une règle d'écriture (ADR-020), vérifiée par le validateur ; en cas de collision `find()` garde son comportement actuel (premier résultat).
4. **Liste.** Une entrée par groupe, ancrée sur le document français : pagination, ordre (date du document **français**, décroissante) et `X-WP-Total` identiques dans toutes les langues. Les filtres `tag`, `status`, `skill` portent sur le document français. Un document sans original français publié n'apparaît dans **aucune** liste (cas anormal, signalé par `translations reconcile`), mais reste servi par son slug et présent au sitemap.
5. **Visibilité.** Seuls les documents ayant en base une `_portfolio_lang` **et** une `_portfolio_translation_key` non vides sont visibles de l'API, sitemap compris. Un document ancien sans ces métas (import d'avant le lot 1b) disparaît jusqu'à sa réimportation. **Ordre de déploiement : déployer le plugin, puis réimporter tout le contenu**, sinon toutes les listes sont vides.
6. **Sitemap.** Chaque ligne : `slug`, `updated`, `lang`, `translation_key`. Documents réels de toutes les langues, jamais les replis. C'est un flux d'invalidation de cache pour l'API C#, **pas** le sitemap SEO : celui-là est le sitemap XML de Yoast (lot 5), et les `hreflang` sont émis par Polylang dans le `<head>` (Yoast n'en émet pas, cf. spike). Règle côté consommateur : toute ligne ajoutée, modifiée **ou disparue** pour une `translation_key` invalide tout le groupe (détails et listes de toutes langues) ; les caches de listes sont invalidés par tout changement de la collection, pas seulement par la ligne du document français.
7. **Cache.** Aucun `Cache-Control`, `ETag` ni `Vary` côté plugin (ADR-018 maintenu). Clés des consommateurs : `(collection, language, slug)`, `(collection, language, page, per_page, filtres)`, TanStack `['article', language, slug]`. Un client qui sérialise une langue indéfinie en `language=` reçoit un 400 : à signaler dans 02 et 03. Un normaliseur d'URL qui retire les paramètres inconnus fusionnerait les langues.
8. **Hypothèses.** Polylang gratuit uniquement (si un jour Polylang filtre en REST, on garde `lang => ''` et `language` reste le seul pilote) ; `lang => ''` conservé dans toutes les requêtes ; `status.label`, `skills[].label` et noms d'étiquettes viennent des termes français ; le repli **ne saute pas** les langues non publiées au public (un visiteur allemand d'un site anglais pas encore ouvert peut recevoir de l'anglais) ; l'API sert toute langue qui a du contenu, publiée ou non (option : lot 5).

### Alternatives considérées

- **`language` absent = toutes les langues mêlées** (comportement antérieur). Compatible à la lettre avec la note d'ADR-018, mais aucun consommateur n'en profite, une liste sans paramètre compte les documents traduits deux fois (15 contre 14 avec un jumeau), et un client qui oublie le paramètre met en cache, sans signal, une liste mêlée sous une clé sans langue. Retour possible en une ligne du contrôleur.
- **`language` absent = langue par défaut de Polylang.** Couple le contrat à Polylang ; refusé par ADR-020.
- **Liste stricte (documents de la langue seulement).** Totaux et pages changent d'une langue à l'autre, une liste allemande serait presque vide pendant la traduction.
- **Repli en 404 avec les langues disponibles, ou redirection.** Un client REST ne suit pas une redirection, et le 404 force un second appel pour un contenu qui existe.
- **Marqueur en en-tête, `served_as` seul, `translations` en URL.** Le corps voyage avec le cache ; le couple `is_fallback` / `requested_language` est plus direct pour un bandeau « traduction indisponible » ; une URL figerait l'hôte de l'API dans la charge.
- **Sitemap par langue, ou avec entrées de repli, ou `ETag` maintenant.** Perd le lien de groupe nécessaire à l'invalidation, mélange documents et requêtes, rouvre ADR-018 sans consommateur.

### Conséquences

Le contrat §7.3 s'enrichit de cinq clés (ajout pur, tolérant pour un binder JSON ; les tests de forme exacte sont réécrits). Le catalogue paie une requête de plus par page et par détail (le groupe de traductions, en une requête `IN`). L'API C# (02) et le front (03) mettent la langue dans leurs clés de cache, et le DTO de 02 relaie `lang`, `translation_key`, `translations`, `is_fallback` et `requested_language` jusqu'au front. Cas limites assumés : une liste traduite est triée par la date française alors que l'entrée affiche la date de sa traduction ; les filtres portent sur le document français, donc les traductions gardent les mêmes étiquettes (règle d'écriture, `content/README.md`) ; un document sans original français est absent des listes mais servi par son slug ; les doublons (même type, clé, langue) suivent « le plus ancien gagne » ; un document sans langue stockée est invisible jusqu'à réimport ; le lot 4 traduira les libellés dans le même présentateur sans changer les clés. Les documents 01 (§7), 02, 03 et `content/README.md` sont mis à jour par le lot 3, qui n'est codé qu'une fois cette décision acceptée.

---

## ADR-022 — Ouvrir une langue au public : l'option de publication, le thème enfant et la configuration du site (lot 5 d'ADR-020)

**Date** : 2026-11-08
**Statut** : Accepté (proposé le 8 novembre 2026, accepté par Morgan le même jour avec les cinq recommandations ; les points 4 et 11 confirmés par la sonde du lot 5a, `spikes/2026-11-08-ouverture-des-langues.md`)

### Contexte

ADR-020 a laissé au lot 5 tout ce qui touche l'affichage public : option `portfolio_md_published_languages`, sélecteur de langue, un menu par langue, accueil et Contact par langue, badge « FR » sur les cartes de repli, liste WordPress avec repli (`portfolio_md_list_with_fallback`), x-default hors accueil, copie de `seo_description` vers Yoast, filtre de détection du navigateur, sort des archives de taxonomies non traduites. ADR-021 (point 8) a fixé que l'API sert toute langue qui a du contenu, publiée ou non, et que le repli ne saute pas les langues non publiées. L'objectif de calendrier est un portfolio trilingue présentable pour les candidatures de janvier 2027 ; la traduction (lot 6) est le goulot, pas ce lot.

État local vérifié (WP-CLI et HTTP, Polylang 3.9.1, Yoast 28.8, GeneratePress 3.6.1 sans thème enfant) : `force_lang=1` (mode répertoire), `browser=true`, `redirect_lang=false`, `taxonomies=[]`, `nav_menus=[]`, aucun menu, `show_on_front=posts`, pages `a-propos` et `cv` seules, 14 fiches françaises, 0 contenu allemand ou anglais. `WP_ENVIRONMENT_TYPE=local` dans le compose : Yoast ne persiste aucun indexable en local (reconstruction à la volée), il le fait en production. `bootstrap.sh` installe les extensions et active GeneratePress, ne règle ni accueil, ni menus, ni options Yoast, et n'active pas le plugin `portfolio-md` (activation manuelle, documentée dans `plugin/plugin-README.md`).

Ce que les sources et les sondes ont établi, et qui contraint la décision :

1. **Un seul point d'étranglement pour retirer une langue.** `pll_translation_url($url, $slug)` renvoyant `''` retire la langue de la boucle hreflang **avant** le test « au moins deux URL » (`frontend-filters-links.php`) et de l'`og:locale:alternate` de Yoast. `pll_the_language_link` ne peut rien retirer (l'élément retombe sur l'accueil de la langue) ; `pll_the_languages_args` et `pll_the_languages` sont dépréciés et hors du chemin des menus. `pll_rel_hreflang_attributes` passe après le test du nombre : il peut servir à ajouter x-default, pas à retirer proprement.
2. **Le sélecteur masque une langue sans contenu.** `hide_if_empty` (défaut `true`) compte les posts de la langue : aujourd'hui `de` et `en` disparaissent de tout sélecteur, et dès qu'**un** document allemand existe `de` apparaît, ouverte ou non. L'élément de menu `#pll_switcher` n'expose pas `hide_if_empty` (il n'est pas dans ses options) et exige un postmeta `_pll_menu_item` fabriqué à la main. `hide_if_no_translation=true` vide tout le sélecteur sur une page sans traduction (y compris les archives d'étiquettes) et ôterait à un visiteur sur une fiche française de repli le chemin de retour vers sa langue.
3. **Le cookie court-circuite la détection du navigateur.** `pll_languages_for_browser_preferences` ne couvre que le navigateur ; le cookie `pll_language` est lu d'abord, écrit sur toute requête non 404, y compris sur un `/de/` fermé. `pll_preferred_language($slug, $cookie)` couvre les deux ; `false` retombe sur la langue par défaut.
4. **Accueil statique par langue.** Avec `redirect_lang=false`, le lien d'accueil d'une langue traduite est le permalien de la page (`/de/startseite/`) et `/de/` reste une liste d'articles mince, doublon de la page, au canonique pointant sur la page. `page_on_front_query` ne fait servir la page par `/de/` que si `redirect_lang || hide_default` (au moment de la proposition, source seule : le cas `true` n'avait pas été exécuté ; la sonde du lot 5a l'a confirmé, point 11). Polylang exige que les pages d'accueil soient dans un même groupe de traductions (ce que `PolylangLanguageGateway::linkTranslations` fait déjà) et met en cache le `page_on_front` de chaque langue : sans `PLL()->model->clean_languages_cache()` après liaison, hreflang, canonique et accueil de langue restent périmés (vérifié).
5. **Menus.** GeneratePress n'a qu'un emplacement `primary`. Polylang stocke un menu par langue dans `polylang['nav_menus'][<stylesheet>]['primary'][<langue>]`, la clé étant le **thème actif** (l'enfant, pas le parent). Sous WP-CLI le crochet d'écriture de Polylang ne joue pas (pas de nonce) : `wp menu location assign` seul ne remplit pas `nav_menus`. Il faut aussi une clé `primary` dans le `theme_mod nav_menu_locations`. Une langue sans entrée n'affiche aucun menu (pas de repli par liste de pages). Écrire l'option par `PLL()->options->set('nav_menus', …)` est vérifié.
6. **Taxonomies non traduites.** `/tag/csharp/` et `/competences/4-0-1/` répondent 200 sans préfixe de langue, contenu suivant la langue du visiteur ; `/fr/tag/csharp/` et `/de/tag/csharp/` sont des 404 ; le sélecteur y renvoie vers l'accueil de langue ; forcer `pll_hide_archive_translation_url` produirait `/de/tag/csharp/`, 404. Traduire les 137 termes (84 `tech_tag`, 4 `project_status`, 49 `skill`) les orphelinerait jusqu'à ce que chacun reçoive une langue et un groupe, et ajouterait des règles `/fr/tag/…` avec redirections 301. L'option Yoast `noindex-tax-<taxonomie>` (`WPSEO_Options::set`) est lue en direct à chaque rendu et retire la taxonomie du `meta robots` **et** du sitemap.
7. **Yoast.** Un seul sitemap pour les trois langues ; Polylang y filtre les posts par langue **active** et y ajoute en XML brut les archives de types de contenu des autres langues actives (`/de/projets/`, même vides). Le drapeau `active` n'est lu que par Yoast ; la version gratuite n'a aucun écran pour le poser, et son effet n'est pas prouvé par HTTP. `wpseo_robots` (chaîne, appliqué à chaque rendu) est l'outil du noindex conditionnel ; `wpseo_sitemap_entry` est appelé pour les posts et les termes, pas pour les blocs XML bruts d'archives, que la sonde du lot 5a a appris à retirer autrement (point 4) ; le cache du sitemap n'est invalidé que sur modification de contenu. La clé de la meta description est `_yoast_wpseo_metadesc` (nettoyée par Yoast à l'écriture) ; Yoast reconstruit l'indexable en fin de requête quand cette meta change, y compris sous WP-CLI et sous la porte `/sync`. Yoast n'émet aucun hreflang ; les titres et descriptions d'accueil et d'archives sont des chaînes Polylang, pas des champs Yoast.
8. **Thème.** Un thème enfant minimal est `style.css` (`Theme Name`, `Template: generatepress`) et `functions.php` ; GeneratePress met lui-même en file la feuille de l'enfant. Le parent n'a ni `front-page.php`, ni modèle de taxonomie, ni `archive-<type>.php` ; ses crochets (`generate_after_entry_title`, `generate_after_entry_content`, `generate_menu_bar_items`) suffisent pour un badge, des puces et un sélecteur. Le compose monte le plugin par un montage supplémentaire sur `wordpress` **et** `wpcli` ; un thème versionné se monte de la même façon.
9. **Code existant.** La chaîne de repli est parcourue par `Service/GroupResolver` (appelé par `WordPressContentCatalog`), pas par `CollectionController`, qui ne construit qu'une `CatalogQuery`. Il n'existe pas d'accesseur `Plugin::catalog()` ; `composer.json` n'a que `psr-4`. La page Contact n'a aucun document source (ADR-017 la disait « faite à la main »).

### Décision

**Principe.** Git décrit, le plugin applique une seule option, WordPress rend avec la boucle standard. Le plugin reste maître de la langue : il ne lit jamais Polylang pour décider de la langue d'un **document** (la seule lecture de Polylang pour décider est la langue de l'**URL de la requête**, pour les vues qui ne sont pas un document ; elle est signalée au point 4). Tout ce qui se fait une fois par environnement va dans un script et un runbook ; le code est limité à ce qui doit tourner à chaque requête.

1. **L'option.** `portfolio_md_published_languages` : option WordPress, tableau de slugs, défaut `['fr']`. Un filtre `pre_update_option_portfolio_md_published_languages` normalise (slugs connus de `Language::values()` seulement, `fr` toujours présent, ordre `fr, de, en`). Le service `Service\PublishedLanguages` (`all()`, `isPublished(Language)`, lecteur d'option injectable pour les tests, service paresseux comme `languageGateway()`) est la seule porte de lecture. Les crochets `add_option_portfolio_md_published_languages` et `update_option_portfolio_md_published_languages` (WordPress tire le premier quand la première écriture crée la ligne, le second ensuite) appellent `WPSEO_Sitemaps_Cache::clear()` quand Yoast est présent (le cache du sitemap n'est sinon vidé qu'à une modification de contenu) ; le cache de langues de Polylang n'a pas à être vidé par ce crochet (la liste des langues ne change pas). Aucune page d'administration. L'option est un état d'**environnement** (le local peut rester fermé quand la production ouvre), pas du contenu : elle n'est jamais dans Git.
2. **Qui l'ouvre, et la grille de préparation.** Morgan seul, jamais automatiquement ; en production avec son accord explicite à chaque étape. Une commande `wp portfolio languages status|publish <lang>|unpublish <lang>` (sur le modèle de `wp portfolio translations reconcile`). `status` affiche la grille sans écrire : pour la langue, accueil, À propos, CV et Contact publiés avec `_portfolio_lang` et dans leur groupe de traductions, un menu rattaché dans `nav_menus`, et au moins **3** fiches projet traduites (le « fil rouge » d'ADR-020, seuil constant du code). `publish` refuse avec la liste lisible des manquants sauf `--force` ; `unpublish` ne demande rien. Un avertissement (pas un refus) signale qu'ouvrir `de` alors que `en` est fermé fait recevoir de l'anglais non annoncé aux visiteurs allemands par la chaîne de repli. `wp option update` direct reste possible et sûr (normalisation et vidage du cache).
3. **Ce que « fermé » veut dire : non annoncé, pas secret.** Une langue fermée reste servie (200) mais n'apparaît dans aucune surface publique et est `noindex` ; Morgan la prévisualise par URL et la fermeture est un retour arrière d'une commande. Une classe `Storage\PolylangPublication`, enregistrée en première instruction de `Plugin::boot()` sans garde (patron `PolylangPostTypes` : un filtre que seul Polylang applique est inoffensif sans lui), accroche :
   - `pll_languages_for_browser_preferences` : retire les langues fermées ;
   - `pll_preferred_language($slug, $cookie)` : renvoie `false` quand la valeur (cookie ou navigateur) nomme une langue fermée, de sorte qu'une visite à un `/de/` fermé ne fait jamais rebondir `/` ;
   - `pll_translation_url($url, $slug)` : renvoie `''` pour toute langue fermée, **langue courante comprise**, sans exception pour les éditeurs (une sortie dépendante de l'utilisateur fuirait avec un cache de pages). Cela retire la langue des hreflang et de `og:locale:alternate` ;
   - `pll_rel_hreflang_attributes` : ajoute `x-default` = l'entrée `fr` quand elle existe et qu'aucun `x-default` n'est présent. Sur l'accueil la valeur native de Polylang (racine du site) reste ; le test « au moins deux URL » passant avant le filtre, une page sans traduction n'émet rien.
4. **Robots et sitemap.** Une classe `Storage\YoastPublication` (crochets posés au boot, inoffensifs sans Yoast) :
   - `wpseo_robots` : `noindex, follow` pour toute vue d'une langue fermée. Pour un document, la langue vient de sa meta `_portfolio_lang` ; pour l'accueil, les archives et les autres vues, de la langue de l'URL de la requête (`pll_current_language()` gardé par `function_exists`) : seule lecture de Polylang pour décider, assumée et à accepter explicitement ;
   - `wpseo_robots` aussi : `noindex, follow` sur une archive de type (`project`, `article`) dont la langue ne possède **aucun** élément propre de ce type (la page n'est alors que des cartes françaises sous une URL étrangère) ; la règle se lève d'elle-même à la première traduction ;
   - `wpseo_sitemap_entry` : retire toute entrée dont l'URL commence par `home_url('/<slug fermé>/')` (le fournisseur de Yoast ignore une entrée vide, pour les posts comme pour les termes). Aucun drapeau `active` de Polylang n'est écrit (second point de vérité non prouvé) ;
   - les blocs XML bruts d'archives (`/de/projets/`) que Polylang ajoute au sitemap ne passent pas par `wpseo_sitemap_entry` : Polylang les concatène dans le filtre `wpseo_sitemap_<type>_content` (priorité 10) ; le plugin accroche le même filtre à la priorité 20 et retire tout bloc `<url>` dont le `<loc>` commence par le préfixe d'une langue fermée (vérifié par la sonde du lot 5a : les blocs `/de/projets/` et `/en/projets/` sont absents du `project-sitemap.xml` tant que leur langue est fermée et reviennent à son ouverture, `/fr/projets/` reste). Le drapeau `active` de Polylang n'est donc jamais écrit. **Un seul mécanisme, jamais les deux.**
5. **Sélecteur de langue rendu en PHP dans le thème enfant**, sur `generate_menu_bar_items`. Il appelle `pll_the_languages(['raw' => 1, 'hide_if_empty' => 0, 'hide_if_no_translation' => 0, 'hide_current' => 0, 'show_wrapper' => 0])` (gardé par `function_exists`), ne garde que les langues de `PublishedLanguages`, et n'affiche pas la langue courante comme lien. Sans traduction de la page, l'élément retombe sur l'accueil de la langue (classe `no-translation`) : acceptable, y compris sur les archives d'étiquettes. Aucun élément de menu `#pll_switcher`, aucun postmeta `_pll_menu_item`, aucun filtre `wp_nav_menu_objects`. Le même filtre par l'option vaut pour les éditeurs : leur aperçu des langues fermées se fait par l'URL.
6. **La liste avec repli côté WordPress.** Fonction globale `portfolio_md_list_with_fallback(string $type, string $language, int $limit = 50): list<ListItem>`, déclarée dans `plugin/src/template-functions.php` (chargée par un `require_once` du fichier principal du plugin, pas par Composer). Façade fine sur `ContentCatalog` : elle construit une `CatalogQuery` et appelle le même `list()` que `CollectionController`, donc la même chaîne (`GroupResolver`, `Language::fallbackChain()`), les mêmes requêtes `lang => ''`, les mêmes ancres françaises. Aucune logique de requête dupliquée, **aucune réécriture de `CollectionController`**. `ListItem` : objet de valeur en lecture seule sans HTML (`postId`, `title`, `excerpt`, `permalink` du document servi, `lang`, `requestedLanguage`, `isFallback`). Un test garantit que cette liste et la liste REST donnent les mêmes identifiants dans le même ordre pour `de` et `en` avec 0, partiel et plein de traductions. L'accès au catalogue passe par un petit accesseur partagé introduit à cette occasion (il n'existe pas aujourd'hui).
7. **La chaîne de repli et ADR-021 sont inchangés.** `de → en → fr`, `en → fr`, y compris vers des langues fermées ; l'API sert toute langue qui a du contenu (point 8 d'ADR-021 maintenu). Le risque d'incohérence (cartes anglaises sous un `/de/` ouvert pendant que `en` est fermé) est traité en exploitation, par l'avertissement du point 2, pas par un second comportement. Les listes REST ne dépendent donc pas de l'option : aucun cache consommateur n'est invalidé par une ouverture.
8. **Archives de projets.** Le thème enfant fournit `archive-project.php`, qui **ignore la boucle principale** et affiche `portfolio_md_list_with_fallback('project', <langue courante>)` : une page de 50 cartes au plus (14 fiches), sans pagination, qui garde l'ossature GeneratePress (`get_header()`, `generate_before_main_content`, `generate_do_attr`). `/de/projets/` liste donc les 14 fiches (les allemandes sans badge, les autres avec le badge `<span class="lang-badge" lang="fr">FR</span>` et un texte accessible tiré d'un tableau de chaînes fr/de/en du thème), dans l'ordre de `/fr/projets/`. Aucun `pre_get_posts` sur la requête principale (non prouvé : Polylang peut réinjecter sa requête de taxonomie). Le canonique est celui de Yoast sur l'URL de l'archive ; `pll_hide_archive_translation_url` n'est pas touché (le sélecteur va à l'accueil de langue, pas d'hreflang quand la langue cible n'a pas de projets). `archive-article.php` n'est écrit qu'à la première traduction d'article (aucun article n'existe).
9. **Taxonomies non traduites : noindex, pas de traduction.** `WPSEO_Options::set('noindex-tax-tech_tag' | 'noindex-tax-project_status' | 'noindex-tax-skill', true)`, une fois, par le script du point 12. Les archives restent des 200 sans préfixe (`/tag/csharp/`, `/competences/4-0-1/`), `noindex`, hors sitemap, rendues par `archive.php` de GeneratePress (aucun modèle). GeneratePress n'imprime aucune taxonomie personnalisée sur une fiche (sa méta d'entrée ne connaît que les catégories et les étiquettes natives, et seulement pour le type `post`) ; quand le thème montrera les `tech_tag` d'une fiche (phase 10), ce sera en **puces sans lien**, pour qu'un visiteur n'atterrisse jamais sur une page d'étiquette vide ; le filtrage par étiquette est l'affaire du front React. Le sélecteur y renvoie vers l'accueil de langue ; ne jamais forcer `pll_hide_archive_translation_url` (404 `/de/tag/…`), ne jamais utiliser `hide_if_no_translation`.
10. **Accueil, Contact et pages : des documents (amende la note d'ADR-017 « Contact reste faite à la main »).** Accueil : `content/<lang>/pages/{accueil,startseite,home}.md`, `translation_key: accueil`. Contact : `{contact,kontakt,get-in-touch}.md`, `translation_key: contact`, **sans formulaire** (lien `mailto:` et liens de profil, adresse dédiée aux candidatures) ; aucune extension de formulaire (minimalisme). Slugs des fichiers = slugs WordPress de la langue (condition de `RelativeLinks`). Raison : une page saisie à la main échappe à Git (seul écrivain), n'est pas reproductible en production, ne rejoint pas le groupe de traductions et ne peut pas être vérifiée par la grille du point 2. Le corps de l'accueil est l'introduction en Markdown ; le bloc « projets récents » (3 cartes via `portfolio_md_list_with_fallback`) est ajouté par le crochet `generate_after_entry_content` du thème quand `is_front_page()`, sans `front-page.php`. Les documents français `accueil.md` et `contact.md` sont écrits au lot 5 (brouillons relus par Morgan) ; les fichiers `de` et `en` relèvent du lot 6.
11. **Accueil par langue : `redirect_lang=true`, confirmé par la sonde du lot 5a.** Les accueils sont `/fr/`, `/de/`, `/en/` : la page d'accueil de la langue est servie à cette adresse (corps `home page`), son permalien (`/fr/accueil/`, `/de/startseite/`) redirige en 301 vers elle, `/` garde sa redirection 302 vers la langue préférée, le canonique de `/fr/` est `/fr/` ; une langue sans page d'accueil liée (`/en/` dans la sonde) montre la liste d'articles. Cela évite deux URL par accueil et un doublon mince. Toute étape (script, passerelle) qui lie ou délie une traduction d'accueil finit par `PLL()->model->clean_languages_cache()` ; `PolylangLanguageGateway::linkTranslations` gagne cette ligne (modification de code du lot 2).
12. **Configuration du site : un script rejouable.** `plugin/tools/site-setup.php` (`wp eval-file`, idempotent, `WP_CLI::log`, sans `declare(strict_types)` car `eval-file` passe par `eval()`, sur le modèle de `polylang-setup-languages.php`), dans cet ordre : vérifier que le thème actif est `portfolio-md-child` ; `add_option('portfolio_md_published_languages', ['fr'])` si absente ; `noindex-tax-*` ; `show_on_front=page` et `page_on_front` = identifiant de la page française de slug `accueil` trouvée par slug et `_portfolio_lang` (jamais un identifiant écrit en dur) ; `redirect_lang` selon le point 11 ; **pour chaque langue qui possède sa page d'accueil** (les autres sont journalisées « skip de : no home page » et traitées à leur ouverture), un menu « Menu <langue> » reconstruit seulement s'il est vide (Accueil, Projets en lien personnalisé vers `/<langue>/projets/` — la base reste française —, À propos, CV, Contact : pages trouvées par slug) ; `wp menu location assign` du menu français à `primary` (crée la clé du `theme_mod`) puis `PLL()->options->set('nav_menus', [get_option('stylesheet') => ['primary' => [...]]])` ; enfin `PLL()->model->clean_languages_cache()`. À **rejouer après tout changement de thème actif** (la clé est le slug du thème) et à l'ouverture d'une langue.
13. **Copie de `seo_description` vers Yoast.** Dans `WordPressContentRepository::writeMetas()`, juste après l'écriture de `_portfolio_seo_description`, pour `project`, `article` et `page` : `update_post_meta($id, '_yoast_wpseo_metadesc', $texte)` si la description est présente, `delete_post_meta` si elle est absente (Git seul écrivain, aucune valeur périmée). Aucune garde Yoast. Un test vérifie qu'une description de 160 caractères ou moins sort inchangée. Une réimportation des 14 fiches et des pages l'applique. Le titre Yoast n'est pas copié (le gabarit de Yoast suffit). Les titres et descriptions d'archives par langue (chaînes Polylang) sont différés.
14. **Le thème enfant.** `theme/portfolio-md-child/` : `style.css` (en-tête et ~30 lignes sobres : cartes, badge, puces, sélecteur ; aucun `wp_enqueue_style`) et `functions.php` (tableau de chaînes fr/de/en, crochets du badge, des puces, du bloc d'accueil, du sélecteur) plus `archive-project.php`. Montage ajouté sur **les deux** services `wordpress` et `wpcli` du compose : `./theme/portfolio-md-child:/var/www/html/wp-content/themes/portfolio-md-child:z`, puis `podman-compose up -d` ; `bootstrap.sh` n'a qu'une ligne changée (`wp theme activate portfolio-md-child`, après l'installation de GeneratePress). Le thème teste `function_exists` avant tout appel au plugin ou à Polylang (patron b). Le design réel (panneaux d'accueil, `single-project.php`, CSS final) est la phase 10 ; la section 13 de 01-plugin-php.md est corrigée : ces modèles viennent avec elle.
15. **Différé, avec le critère « ajoutable sans changer une URL ni un contrat ».** `GET /portfolio/v1/languages` (forme fixée dès maintenant : `{default: 'fr', languages: [{slug, published}]}` lue dans `PublishedLanguages` ; ajoutée quand l'API C# ou React en a besoin) ; bandeau « traduction indisponible » sur une fiche de repli ; titres et descriptions d'archives par langue ; données structurées, `robots.txt`, Search Console (phase 11) ; traduction des taxonomies (jamais prévue).
16. **Contrôles.** Les contrôles d'acceptation se font **déconnecté** (fenêtre privée) : le sélecteur et les hreflang ne dépendent pas de l'utilisateur, mais `noindex` et l'aperçu se jugent aussi hors session. Un script `plugin/tools/opening-check.sh` (curl, sur le modèle de `polylang-http-spike.sh`) rejoue les vérifications fermé puis ouvert.

### Alternatives considérées

- **404 pour le public sur une langue fermée** (porte `template_redirect`). Plus étanche, mais interdit de partager un aperçu, ajoute une porte avec exception d'éditeur, et fermer une langue déjà indexée produit des 404 que Google met des semaines à oublier. Écarté : 200 + `noindex` donne l'aperçu et le retour arrière gratuits.
- **Synchroniser le drapeau `active` de Polylang avec l'option.** Second point de vérité, écriture non documentée (`pll_language_metas` + `Languages::update`), effet sur le sitemap non prouvé, et aucun effet sur sélecteur, hreflang ou détection du navigateur. Gardé seulement comme repli unique du point 4.
- **Élément de menu `#pll_switcher`** avec `hide_if_no_translation` forcé. Postmeta fabriqué à la main, `hide_if_empty` non réglable, et un filtre supplémentaire pour retirer les langues fermées. Le sélecteur PHP est relisible par Morgan et suit l'option.
- **`hide_if_no_translation=true`.** Vide le sélecteur sur toute page sans traduction et coupe le chemin de retour d'un visiteur d'une fiche de repli.
- **Faire sauter les langues fermées à la chaîne de repli** (amender ADR-021 point 8). Les listes dépendraient de l'option, les caches de l'API C# et de React deviendraient périmés à chaque ouverture, et la logique devrait être injectée dans le domaine ou `GroupResolver`. Écarté ; l'avertissement de `publish` suffit.
- **Substituer la requête principale des archives** (`pre_get_posts`, `post__in`, `lang => ''`). Bataille avec Polylang, non prouvée ; le gabarit qui ignore la boucle est plus sûr.
- **Traduire les 137 termes** dans Polylang, ou **désactiver les archives** (`publicly_queryable=false`). Lourd et change les URL, ou casse `get_term_link` sur chaque fiche et le lien du fil rouge `competences/4-0-1/`.
- **Pages d'accueil et Contact saisies dans l'administration.** Hors Git, non reproductibles, non vérifiables par la grille. **Formulaire de contact** : extension (interdite par le minimalisme) ou code de formulaire dans le plugin (surface de spam et de sécurité hors lot).
- **Page d'administration à cases à cocher** pour l'option : plus de PHP et de tests pour un seul opérateur ; retenue seulement si WP-CLI est inaccessible chez o2switch.
- **Passer `x-default` partout vers `/`** : une redirection, pas un document. **Ne pas faire x-default** : poste nommé d'ADR-020, dix lignes.

### Conséquences

Le plugin gagne `Service\PublishedLanguages`, `Storage\PolylangPublication`, `Storage\YoastPublication`, une commande WP-CLI, la fonction `portfolio_md_list_with_fallback` et `ListItem` ; `WordPressContentRepository::writeMetas()` et `PolylangLanguageGateway::linkTranslations()` changent de quelques lignes chacun. Le dépôt gagne `theme/portfolio-md-child/`, un montage de compose, une ligne de `bootstrap.sh`, `plugin/tools/site-setup.php` et `opening-check.sh`, `content/fr/pages/{accueil,contact}.md`. Rien ne change pour les consommateurs : les routes REST et la chaîne de repli sont intactes, aucun cache n'est à purger à une ouverture ; l'API C# et React ne doivent pas déduire « langue ouverte » de la présence de contenu avant l'ajout de `GET /languages`, et leur sélecteur reste codé en dur jusque-là. Documents à mettre à jour (annoncés avant édition, après validation de Morgan) : note d'implémentation du lot 5 sous ADR-020, 01-plugin-php.md §7 (aucune route nouvelle) et §13 (modèles différés à la phase 10), 04-structure-wordpress.md §2 et §4, note d'ADR-017 sur Contact, `content/README.md` (nouvelles pages et slugs), `plugin-README.md` (runbook).

Risques assumés : une page de langue fermée reste accessible par URL devinée (choix) ; Yoast n'émet pas de canonique sur une page `noindex`, ce qui est son comportement normal et sans conséquence tant que la langue est fermée ; un `/de/projets/` ouvert, rempli de cartes françaises, est un doublon mince pour un visiteur même noindexé tant que les traductions manquent, ce que le badge rend honnête et que le seuil de la grille limite ; les indexables persistés de Yoast ne sont vérifiables qu'en production (test de fumée après la première importation, `wp yoast index --reindex` au besoin) ; les versions Polylang 3.9.1, Yoast 28.8 et GeneratePress 3.6.1 sont épinglées, `opening-check.sh` est rejoué après toute mise à jour ; le changement de thème actif casse les menus jusqu'au rejeu du script ; l'adresse `mailto:` de Contact est exposée aux robots (adresse dédiée).

Ordre de déploiement : plugin, thème, importation du contenu (réimport des 14 fiches pour la copie de la description), `site-setup.php`, test de fumée, puis seulement ouverture d'une langue quand la grille est verte. Fermer = `wp portfolio languages unpublish <lang>` ; le sitemap suit au même instant (cache vidé). Les cinq questions posées à Morgan le 8 novembre 2026 (accueils à `/<lang>/` par `redirect_lang`, Contact en document sans formulaire avec une adresse dédiée, langue fermée servie en `noindex` plutôt qu'en 404, grille d'ouverture imposée par la commande avec un seuil de trois fiches, ouverture de `en` avant `de`) ont reçu la réponse recommandée ; la disponibilité de WP-CLI chez o2switch reste à confirmer avant la production, pas avant le code.

**Notes d'implémentation.** *Lot 5a (8 novembre 2026)* : (1) la sonde `tests/Integration/wp-cli/opening-spike.php` + `tools/opening-spike.sh` (constats dans `spikes/2026-11-08-ouverture-des-langues.md`) a confirmé `redirect_lang = true`, la langue fermée servie en `noindex` sans boucle ni effet de bord quand `pll_translation_url` vide l'URL de la langue courante, les deux filtres de préférence, le retrait des blocs d'archives du sitemap par `wpseo_sitemap_<type>_content` à la priorité 20, le sélecteur brut filtré par l'option (appelé deux fois par GeneratePress), les menus par langue écrits par WP-CLI sous la clé du thème enfant et la description Yoast écrite par PHP ; tout ce qu'elle pose est annulé par son mode `cleanup`. (2) Le thème enfant `theme/portfolio-md-child/` existe (`style.css` avec l'en-tête `Template: generatepress`, `functions.php` vide de crochets, `theme/theme-README.md`), monté par `docker-compose.yml` sur les services `wordpress` et `wpcli` au même titre que le plugin, activé par `bootstrap.sh` à la place de GeneratePress, qui reste le parent installé. (3) `plugin/tools/site-setup.php` applique les réglages sûrs du point 12 : refus si le thème actif n'est pas l'enfant, `portfolio_md_published_languages` créée à `[fr]` si absente, `noindex-tax-tech_tag`, `noindex-tax-project_status` et `noindex-tax-skill` à vrai dans Yoast (cache du sitemap vidé) ; rejouable, une ligne par réglage. Vérifié sur la stack locale : `/tag/csharp/` porte `robots: noindex, follow`, le `sitemap_index.xml` ne liste plus que les pages et les projets. L'accueil statique, `redirect_lang`, les menus et `nav_menus` viennent avec le lot 5d, une fois les pages d'accueil importées.

*Lot 5b, l'option d'ouverture (8 novembre 2026)* : (1) `Service\PublishedLanguages` lit et écrit l'option par le port `PublishedLanguagesStore` (`Storage\WordPressPublishedLanguagesStore`) ; `normalize()` ne garde que les langues du site, le français toujours, dans l'ordre `fr, de, en`, chacune une fois, et une valeur cassée se lit comme « français seul » ; le store accroche `pre_update_option_portfolio_md_published_languages` (toute écriture, `wp option update` compris, est normalisée) et `add_option_…` + `update_option_…` (vidage de `WPSEO_Sitemaps_Cache` quand Yoast est là, création de l'option comprise ; constat de la relecture, le seul `update_option_…` ne joue pas quand `update_option()` crée la ligne). Le français ne se ferme pas (`InvalidArgumentException`). (2) `Storage\PolylangPublication` accroche au chargement, sans garde, les quatre filtres du point 3 ; un slug que le plugin ne connaît pas est laissé à Polylang. (3) `Storage\YoastPublication` accroche `wpseo_robots` (langue d'un document par sa meta `_portfolio_lang`, sinon langue de la requête par `pll_current_language()`, gardé par `function_exists`), `wpseo_sitemap_entry` (préfixe `home_url('/<lang>/')` d'une langue fermée) et `wpseo_sitemap_<type>_content` à la priorité 20 pour `article`, `project` et `post` (blocs d'archives et d'accueil par langue). (4) La grille du point 2 est `Service\LanguageReadiness`, qui lit le **catalogue public** (`ContentCatalog`), le même que les routes REST, pour que « prête » veuille dire « ce que le site servirait » : les quatre pages de `Domain\CorePages` (`accueil`/`startseite`/`home`, `a-propos`/`ueber-mich`/`about`, `cv`/`lebenslauf`/`resume`, `contact`/`kontakt`/`get-in-touch`) servies dans la langue, sous leur slug, dans leur groupe ; un menu lié (`Service\MenuBindings`, réalisé par `Storage\PolylangMenuBindings` sur `nav_menus[<thème actif>][primary][<lang>]`) ; au moins `LanguageReadiness::MINIMUM_PROJECTS = 3` fiches servies dans la langue, repli exclu, comptées page par page. (5) `Cli\LanguagesCommand` : `wp portfolio languages status [--format=table|json]`, `publish <lang> [--force]` (refus avec la liste des manquants sinon ; avertissement quand une langue de la chaîne de repli est fermée), `unpublish <lang>` ; la commande ne décide rien, elle traduit. (6) La règle « archive sans élément propre = noindex » du point 4 est venue avec le lot 5c, avec le gabarit. Vérifié : 793 tests unitaires ; `publication-smoke.php` (normalisation d'une écriture directe, filtres réellement accrochés, commande par `WP_CLI::runcommand`, option restaurée) ; `tools/opening-check.sh` sur la stack locale, allemand fermé puis ouvert de force puis refermé.
*Lot 5c, la liste avec repli, l'archive des projets et le sélecteur (8 novembre 2026)* : (1) `Service\ListWithFallback` et `Service\ListItem` (point 6) : la façade construit la même `CatalogQuery` que la route de collection pour `?language=<lang>&per_page=<limite>` et rend des objets de valeur sans HTML (`postId`, `title`, `excerpt` = sous-titre sinon description SEO, `permalink` du document servi, `lang`, `requestedLanguage`, `isFallback`) ; `plugin/src/template-functions.php`, chargé par un `require_once` du fichier principal, expose `portfolio_md_list_with_fallback(type, langue, limite = 50)` (langue inconnue = français ; type inconnu ou limite hors 1..100 = exception, c'est un gabarit qui les écrit) et `portfolio_md_published_languages()`. L'« accesseur partagé » du point 6 est `Plugin::catalog()`, paresseux, partagé par les routes REST, la commande des langues et l'adaptateur Yoast ; la fonction globale, qui ne voit pas l'instance du plugin, construit son catalogue une fois par requête (adaptateur sans état). (2) `ContentCatalog::countOwn(type, langue)`, une requête `COUNT`, et la seconde règle du point 4 dans `YoastPublication::robots()` : un `post-type-archive` de Yoast (sous-type le post type) dont la langue de la requête n'a aucun document propre est `noindex, follow` ; une langue fermée l'est avant tout comptage. Limite connue : un document orphelin (traduction sans original français) compte comme propre alors que les listes, ancrées sur le français, ne le montrent pas. (3) Thème enfant : `inc/strings.php` (textes fr/de/en), `inc/render.php` (fonctions pures : éléments bruts de Polylang 3.9, tableaux indexés par slug avec les clés historiques, réduits aux langues ouvertes ; sélecteur en `.menu-bar-item` de GeneratePress avec un appel à `pll_the_languages()` qui reprend les arguments du point 5 (`raw`, les trois `hide_*` à zéro, `show_wrapper` à zéro, sans effet en mode brut), langue courante rendue en ancre sans `href` (pas un lien, mais les règles de barre de menu de GeneratePress s'y appliquent comme aux voisines), rien tant qu'une seule langue est ouverte, aucun `id` parce que le crochet joue deux fois ; cartes au balisage de `content.php`, `lang` et `hreflang` sur une carte servie dans une autre langue, et le badge, dont le balisage précise celui du point 8 : `<span class="lang-badge" title="…">` contenant `<span lang="fr" aria-hidden="true">FR</span>` et la phrase de la table de chaînes en `screen-reader-text` dans la langue de la page), `functions.php` (crochet `generate_menu_bar_items` ; `pre_get_posts` qui aligne la taille de page de la boucle principale de l'archive des projets sur la liste, pour qu'un `/projets/page/2/` n'existe pas et réponde 404 au lieu de répéter les cartes), `archive-project.php` (ossature d'`archive.php` avec ses crochets, titre du thème dans la langue de la page plutôt que `the_archive_title()` et son préfixe « Archives : », aucune pagination). Le découpage du point 14 (tout dans `functions.php`, une trentaine de lignes de CSS) est remplacé par `inc/strings.php` et `inc/render.php`, le badge est rendu par le gabarit et non par un crochet, et les puces `tech_tag` sans lien restent à la phase 10 (point 9). (4) Le sitemap dit la même chose que les robots : `wpseo_sitemap_<type>_content` retire aussi le bloc d'archive d'une langue ouverte sans document propre de ce type (le type est lu dans le nom du filtre), puisque Polylang l'ajoute pour toute langue ouverte ; un `nofollow` posé dans Yoast survit au `noindex`. Vérifié : 834 tests unitaires ; `list-smoke.php` 30 PASS (comptes en écart sur ce que le site tient, brouillon allemand hors des listes et du compte) ; `opening-check.sh` : 14 cartes et 0 badge sur `/fr/projets/`, 14 cartes et 14 badges sur `/de/projets/` (`noindex` ouvert comme fermé, faute de fiche allemande, et absent du sitemap), `/fr/projets/page/2/` en 404, sélecteur `[fr] [de]` sur chaque page dès l'ouverture, aucun avec le seul français.
*Lot 5d, les pages, les menus et la description Yoast (8 novembre 2026)* : (1) `content/fr/pages/accueil.md` et `contact.md` (point 10), brouillons à relire par Morgan ; la page Contact porte un lien `mailto:` vers `bonjour@voltz.dev`, **adresse à confirmer ou à remplacer par Morgan avant toute mise en production**, et le lien GitHub, sans LinkedIn (aucune URL connue du dépôt) ; `Validation\ContentValidator` exempte de sa règle « aucune donnée de contact dans une page » la seule page Contact : clé de traduction validée `contact` (le slug lui-même pour la page française) **et** slug de Contact dans sa langue (`kontakt`, `get-in-touch`) ; une page seulement clé `contact` reste soumise à la règle. (2) `WordPressContentRepository::writeMetas()` recopie `seo_description` dans `_yoast_wpseo_metadesc` et supprime cette meta quand la description est absente (point 13), sans garde Yoast ; **un réimport seul ne suffisait pas** : un document dont le Markdown n'a pas changé est « inchangé » et ses metas ne sont pas réécrites, d'où `ContentRenderer::VERSION` 1.3.0, qui fait réécrire chaque document à son prochain import (c'est le levier prévu quand ce que le pipeline stocke change). (3) `PolylangLanguageGateway::linkTranslations()` finit par `PLL()->model->clean_languages_cache()` quand un groupe est enregistré ou dissous (point 11), dans un `finally` : un groupe que Polylang n'a gardé qu'en partie laisse le cache aussi périmé qu'un groupe entier ; gardé par la présence de Polylang. (4) `tools/site-setup.php` complet (point 12), dans l'ordre du point, avec ces précisions : les menus s'appellent « Menu FR », « Menu DE », « Menu EN » et ne sont jamais reconstruits quand ils tiennent quoi que ce soit (brouillons et éléments orphelins compris, comptés par `get_objects_in_term`) ; le lien « Projets » est écrit racine-relatif (`/fr/projets/`), pour survivre à un changement d'hôte ou de schéma ; chaque ajout d'élément est vérifié, une erreur arrête le script ; une liaison (langue dans `nav_menus`, emplacement `primary` dans l'option brute `theme_mods_<thème>`) n'est écrite que si elle est vide ou pointe sur un menu disparu, un autre menu choisi dans l'admin est laissé ; l'option de Polylang est écrite et sauvée avant l'emplacement du thème, et seule une valeur non gardée est bloquante ; l'accueil statique et `redirect_lang`, eux, sont imposés à chaque passage (c'est la décision, pas un réglage) ; une langue sans accueil est journalisée et ignorée. (5) Le bloc « projets récents » de l'accueil (point 10) est accroché à `generate_after_content`, pas à `generate_after_entry_content` : sur une page GeneratePress ne joue que le premier (`content-page.php`), le second n'existe que sur les articles ; il montre trois cartes de la liste avec repli et un lien vers l'archive de la langue (`get_post_type_archive_link('project')`, que Polylang traduit), rien quand la liste est vide. Vérifié : 840 tests unitaires ; `import-smoke.php` (meta Yoast présente, suivant un changement, supprimée avec la description, absente sans description), `setup-smoke.php` 16 PASS sur une stack sans menu (création, cinq éléments visant les bonnes pages, lien racine-relatif) et 14 quand le menu existe déjà (laissé tel quel), le second passage ne changeant rien, `list-smoke.php` et `publication-smoke.php` rejoués ; stack locale : `/` → 302 `/fr/`, `/fr/accueil/` → 301 `/fr/`, accueil avec trois cartes et le menu français de cinq entrées, `<meta name="description">` des fiches et des pages égale à leur `seo_description` ; `de` et `en` journalisées « skip » faute d'accueil.

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
```

Le nouveau numéro est toujours le suivant dans la séquence, jamais réutilisé. Si une décision ancienne est révisée, la nouvelle entrée explique la révision et l'ancienne est marquée `Révisée (voir ADR-NNN)` sans être supprimée.

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

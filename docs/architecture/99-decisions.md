# Journal des décisions architecturales

**Fichier** : `docs/architecture/99-decisions.md`
**Rôle** : trace des décisions structurantes, avec leur contexte et leur justification
**Format** : inspiré d'ADR (Architecture Decision Records) de Michael Nygard
**Lectorat** : contributeur, Morgan lui-même à froid, recruteur attentif
**Dernière mise à jour** : 26 octobre 2026

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

La taxonomie `skill` est **hiérarchique**, attachée au seul post type `project`, avec l'URL de base `/competences/`. Les termes **parents** sont les référentiels (`4-0-1` → « Compétences 4.0.1 », `4-0-2` → « Compétences 4.0.2 »), auto-insérés à l'activation du plugin comme les statuts de projet (`TaxonomyRegistrar::ensureSkillReferentialTerms()`). Les termes **enfants** sont les compétences ; leur nom est l'intitulé officiel, leur slug est dérivé du code (`C1.3` → `c1-3`, WordPress suffixe automatiquement en cas de doublon entre référentiels), et le **code officiel complet** (`4.0.1/C1.3`) est stocké dans le term meta `_portfolio_skill_code`, exposé en REST et sanitizé comme texte court. Les compétences sont créées par le pipeline Markdown à partir du frontmatter (`skills: [4.0.1/C1.3, …]`) ou à la main dans l'admin ; la liste des codes et intitulés est du **contenu**, fourni par Morgan depuis le référentiel officiel, pas du code.

### Alternatives considérées

**Taxonomie plate avec slug dérivé du code complet** (`4-0-1-c1-3`) : la plus simple à implémenter, mais le regroupement par référentiel devrait être recodé dans le thème et dans l'API C# en analysant les slugs, et une archive « tous les projets du référentiel 4.0.1 » n'existerait pas nativement.

**Deux taxonomies** (`skill_401`, `skill_402`) : duplication de la structure, et un troisième référentiel un jour imposerait du code.

**Code officiel dans la description du terme** : pas requêtable proprement (`get_terms` avec `meta_query` fonctionne sur les term metas, pas sur la description), et mélange une donnée technique avec un texte éditorial.

### Conséquences

Le pipeline Markdown résout un code en deux temps : le préfixe (`4.0.1`) désigne le parent, le code complet retrouve l'enfant via `meta_key = _portfolio_skill_code` ; s'il n'existe pas, il est créé sous le bon parent. Les archives WordPress fournissent gratuitement `/competences/4-0-1/` (tous les projets démontrant au moins une compétence du référentiel) et `/competences/4-0-1/c1-3/` (une compétence précise). L'API C# expose pour chaque compétence `{ slug, code, label }` comme prévu dans `02-api-csharp.md`, le code venant du term meta. ADR-011 reste accepté ; seule la forme concrète (hiérarchie, slug, term meta) est fixée ici. Reste à faire côté contenu : saisir ou importer la liste officielle des compétences des deux référentiels.

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

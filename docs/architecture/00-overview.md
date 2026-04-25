# Vue d'ensemble — Portfolio Double

**Fichier** : `docs/architecture/00-overview.md`
**Rôle** : document d'entrée dans l'architecture, porte d'accès aux documents détaillés
**Lectorat** : contributeur, recruteur, étudiant, Morgan lui-même à froid
**Temps de lecture** : environ quinze minutes
**Dernière mise à jour** : 22 avril 2026

---

## À quoi sert ce document

Ce document est le premier qu'on lit pour comprendre le projet. Son objectif est de transmettre la *forme* de l'architecture sans entrer dans les détails d'implémentation. Après l'avoir lu, un lecteur sait ce qu'on construit, quels composants existent, comment ils s'articulent, et vers quel document spécialisé aller pour approfondir tel ou tel aspect.

Ce document ne contient donc volontairement ni code, ni schéma de base de données détaillé, ni arborescence complète de dossiers. Toutes ces matières techniques vivent dans les documents dédiés référencés en section dix.

---

## 1. Le projet en une page

Portfolio Double est un projet qui produit simultanément deux interfaces web pour un contenu unique. La première interface est un **site WordPress classique**, destiné au jury académique d'une formation Licence Informatique et au référencement naturel sur les moteurs de recherche. La seconde est un **portfolio technique moderne**, construit sur une API C# ASP.NET Core et un front React, destiné aux recruteurs et à l'expérimentation personnelle.

La difficulté du projet n'est pas technique au sens où aucune technologie n'est exotique : c'est de l'architecture. Il faut faire coexister deux objectifs potentiellement contradictoires — un WordPress authentique (Gutenberg fonctionnel, Yoast SEO utilisable, plugins standards interopérables) et une stack technique moderne (Clean Architecture, séparation des préoccupations, format de contenu portable) — sans que l'un sacrifie l'autre.

Le piège central à éviter est la **duplication de contenu**. Si chaque site avait son propre stockage et son propre workflow d'édition, le contenu dériverait inévitablement entre les deux avec le temps. L'architecture retenue garantit qu'il y a *une seule source de vérité* et que les deux interfaces en sont des projections cohérentes.

Le principe directeur est le suivant : **WordPress est le CMS maître, mais le contenu est écrit en Markdown**. Un plugin custom (`portfolio-md`) accepte du Markdown en entrée, le stocke tel quel comme source canonique, et en dérive en parallèle une version HTML en blocs Gutenberg pour alimenter le thème WordPress, Yoast et le reste de l'écosystème. Le Markdown ainsi conservé peut aussi être servi en tant que tel à l'API C#, qui le consomme pour alimenter le front React.

Cette double représentation du même contenu — Markdown source et HTML dérivé — est la clé de voûte de l'architecture. Le HTML Gutenberg est une dérivée consommée par le thème et les plugins WordPress. Le Markdown est la source : éditable, versionnable, exportable, consommable par n'importe quel renderer externe.

---

## 2. Les trois composants applicatifs

### 2.1. Plugin WordPress `portfolio-md`

Le plugin est le composant central du système. Il enrichit WordPress d'un éditeur Markdown dans l'admin, il enregistre un type de contenu custom pour les projets (en complément des articles de blog standards), il contient le pipeline de conversion qui transforme le Markdown source en HTML Gutenberg, et il expose une API REST custom qui permet aux consommateurs externes (l'API C# notamment) d'accéder au Markdown et aux métadonnées associées.

Le plugin est écrit en **PHP 8.2+** avec une organisation moderne en PSR-4 via Composer. Son architecture interne suit les principes de la Clean Architecture adaptés à PHP et à WordPress : séparation des objets métier purs, de la logique applicative, et des adaptateurs qui font le pont avec l'écosystème WordPress.

Détails complets dans [`01-plugin-php.md`](01-plugin-php.md).

### 2.2. API C# ASP.NET Core

L'API joue trois rôles simultanément. Elle est d'abord une *gateway* entre WordPress et le front React : elle consomme l'API REST du plugin, met en cache les réponses, et expose sa propre API publique dont la forme est stable et contrôlée indépendamment du backend WordPress. Elle est ensuite un *transformer* : elle peut parser le Markdown côté C#, enrichir les données retournées, calculer des champs dérivés. Elle est enfin un *laboratoire d'apprentissage* pour les technologies .NET modernes que Morgan souhaite approfondir.

L'API est écrite en **C# sur .NET 10** avec ASP.NET Core en Minimal APIs. Elle est organisée en trois projets distincts selon la Clean Architecture : `Portfolio.Api` pour les endpoints HTTP, `Portfolio.Application` pour la logique métier pure, et `Portfolio.Infrastructure` pour les implémentations concrètes (client HTTP vers WordPress, cache, parsing Markdown).

Cette structure en trois projets n'est pas choisie pour la complexité du projet tel qu'il existe aujourd'hui — elle serait presque sur-dimensionnée. Elle est choisie pour préparer l'évolution future : la partie `Portfolio.Application`, étant indépendante de toute technologie d'interface, pourra être réutilisée telle quelle par d'éventuels futurs frontends (desktop en Avalonia, mobile en MAUI, CLI). Ce point est développé en section quatre.

Détails complets dans [`02-api-csharp.md`](02-api-csharp.md).

### 2.3. Front React TypeScript

Le front est le portfolio technique destiné aux recruteurs. Il est volontairement séparé du site WordPress pour démontrer une compétence fullstack moderne, et il ne parle qu'à l'API C# — il n'a aucune connaissance de l'existence de WordPress dans le système.

Le stack est **React 18 avec TypeScript**, bundlé par **Vite**, avec **React Router** pour la navigation et **TanStack Query** pour la gestion du cache client et des états de chargement. Le SEO n'étant pas critique sur ce site (il est assuré par le site WordPress parallèle), le choix d'une SPA pure plutôt qu'un framework de rendu serveur comme Next.js se justifie par la simplicité et la vitesse de développement.

Les enrichissements techniques des articles (diagrammes Mermaid, formules mathématiques KaTeX) sont chargés en *lazy loading* en fonction de flags dans le frontmatter de chaque article, ce qui garantit que les articles qui n'en ont pas besoin restent ultra-légers.

Détails complets dans [`03-frontend-react.md`](03-frontend-react.md).

---

## 3. Le format pivot : Markdown GFM avec frontmatter YAML

Le contenu canonique du projet est écrit en **GitHub Flavored Markdown** (GFM), le dialecte Markdown utilisé par GitHub, GitLab, Obsidian, Hugo, et la grande majorité des outils techniques modernes. Ce choix garantit la portabilité : si Morgan migre un jour hors de WordPress, ses fichiers fonctionnent sans conversion dans n'importe quel environnement compatible.

Chaque article commence par un bloc de **frontmatter YAML** délimité par des triples tirets, qui contient les métadonnées structurées. Un exemple minimal :

```markdown
---
title: "Pourquoi Rust a changé ma façon de penser la mémoire"
slug: "rust-ownership-intuition"
date: 2026-04-22
type: "article"
category: "Architecture"
tags: [rust, systems, ownership]
seo_description: "Une intuition visuelle du borrow checker."
reading_time: 8
needs_mermaid: true
---

# Le vrai problème que Rust résout

Le contenu de l'article commence ici, en Markdown GFM standard.
```

Le champ `type` distingue un article de blog (`"article"`) d'une étude de cas projet (`"project"`), ce qui guide le plugin vers le bon type de post WordPress. Les champs `needs_mermaid` et `needs_katex` permettent au renderer de charger les librairies correspondantes uniquement sur les pages qui en ont besoin, selon le principe de *progressive enhancement*.

Le schéma complet du frontmatter pour les articles et pour les projets, ainsi que les règles de mapping vers les champs WordPress, est documenté en détail dans [`01-plugin-php.md`](01-plugin-php.md).

---

## 4. Le principe directeur architectural : un cœur, plusieurs interfaces

L'idée la plus structurante de l'architecture, dont découlent la plupart des choix techniques, est que **la logique métier doit être écrite une seule fois et réutilisable par plusieurs interfaces**. Cette idée porte un nom dans la littérature : c'est la *Clean Architecture* telle que formalisée par Robert C. Martin, proche cousine de l'*Hexagonal Architecture* et de l'*Onion Architecture*.

Concrètement, dans le projet actuel, la seule interface existante est l'API HTTP servie à React. Mais la structure en trois projets de l'API C# (et, de manière analogue, la structure en couches du plugin PHP) est conçue pour que le cœur métier — les cas d'usage « lister les articles », « récupérer un projet par slug », « filtrer les articles par tag » — soit indépendant de l'interface qui les consomme.

Cette propriété d'indépendance n'a aucun coût visible aujourd'hui, mais elle ouvre des évolutions futures à moindre effort. Le jour où Morgan souhaitera construire un outil desktop pour alimenter son portfolio, il pourra ajouter un projet `Portfolio.Desktop` en Avalonia (framework MVVM cross-platform qui tourne notamment sur Linux) qui référencera `Portfolio.Application` sans aucune duplication de code. De même pour une éventuelle app mobile en MAUI ou un outil CLI d'administration.

L'investissement initial dans cette architecture se justifie donc autant par la propreté du code actuel que par la flexibilité qu'elle préserve pour l'avenir.

---

## 5. Diagramme de flux global

Le diagramme suivant résume le parcours d'un contenu depuis sa création par Morgan jusqu'à son affichage sur les deux sites.

```
                    Morgan écrit du Markdown
                             │
            ┌────────────────┼────────────────┐
            │                │                │
      Admin WP          Webhook Git     Import manuel
            │                │                │
            └────────┬───────┴────────┬───────┘
                     │                │
                     ▼                ▼
           ┌──────────────────────────────┐
           │  Plugin portfolio-md (PHP)   │
           │  Parse → stocke MD + HTML    │
           └──────────────┬───────────────┘
                          │
               ┌──────────┴──────────┐
               ▼                     ▼
      wp_posts.post_content    wp_postmeta
      (HTML Gutenberg)         (Markdown source)
               │                     │
               ▼                     ▼
       ┌───────────────┐      ┌──────────────┐
       │ Thème WP      │      │ REST custom  │
       │ (portfolio    │      │ /portfolio/  │
       │  sobre + SEO) │      │  v1/...      │
       └───────────────┘      └──────┬───────┘
                                     │
                                     ▼
                             ┌───────────────┐
                             │ API C# .NET   │
                             │ (gateway)     │
                             └───────┬───────┘
                                     │
                                     ▼
                             ┌───────────────┐
                             │ Front React   │
                             │ (portfolio    │
                             │  technique)   │
                             └───────────────┘
```

On lit le diagramme de haut en bas. Morgan rédige du Markdown. Ce Markdown entre dans le système par l'une des trois portes d'entrée (admin WordPress, webhook Git, import manuel). Le plugin le parse et le stocke à deux endroits : en HTML Gutenberg dans `post_content` pour les consommateurs WordPress, et en Markdown brut dans `post_meta` pour les consommateurs externes. Le thème WordPress lit le HTML et affiche le site sobre. L'API C# lit le Markdown via l'endpoint REST, le transforme selon ses besoins, et sert le front React.

---

## 6. Stratégie de déploiement

Le projet est pensé pour être déployé en production à faible coût, sur des infrastructures classiques qu'un étudiant peut assumer.

Le **site WordPress** sera hébergé sur un hébergement mutualisé classique — OVH, o2switch, Infomaniak ou équivalent — qui garantit la compatibilité PHP/MariaDB et les fonctionnalités WordPress attendues (crons, email sortant, upload de médias). Le coût d'entrée est de quelques euros par mois.

L'**API C#** sera déployée sur un VPS Linux modeste (Hetzner, Scaleway, ou équivalent, pour rester dans l'écosystème européen cohérent avec les valeurs du projet). ASP.NET Core tourne nativement sur Linux via .NET 10, avec un reverse proxy Nginx en frontal pour le HTTPS et le routage. Le coût d'entrée est d'environ cinq à dix euros par mois.

Le **front React** sera déployé sur une plateforme de JAMstack : Netlify, Cloudflare Pages, ou un service équivalent. Le build se fait à partir du repo Git, et le résultat statique est servi via un CDN mondial. Le coût d'entrée est nul dans les tiers gratuits de ces plateformes, largement suffisants pour le trafic d'un portfolio.

Les détails précis de configuration (DNS, certificats SSL, variables d'environnement, secrets) seront documentés au moment du passage en production, dans un document `04-deployment.md` qui sera ajouté à ce dossier quand la phase onze de la feuille de route sera atteinte.

---

## 7. Feuille de route

Le projet est découpé en douze phases indépendantes, conçues pour être attaquées dans l'ordre (à quelques exceptions près où l'on peut bifurquer), avec un livrable fonctionnel à chaque étape. Ce découpage permet de progresser en continu sans jamais laisser le projet dans un état cassé.

**Phase 0** : mise en place de l'environnement de développement. WordPress local fonctionnel, repo GitHub initialisé avec la structure de documentation, outillage en place.

**Phase 1** : plugin squelette. Le plugin s'active, enregistre son Custom Post Type `portfolio_project` et la taxonomie `tech_stack`. Rien ne parse encore de Markdown, mais la structure est prête.

**Phase 2** : parser Markdown et stockage. Intégration de `league/commonmark` et `symfony/yaml`. Un script WP-CLI permet d'importer un fichier `.md` et de créer un article WordPress correctement rempli.

**Phase 3** : conversion HTML vers blocs Gutenberg. Le `post_content` résultant est reconnu par Gutenberg comme un article éditable en mode bloc.

**Phase 4** : éditeur Markdown dans l'admin WordPress. Morgan peut écrire en Markdown directement depuis l'admin et voir le rendu immédiatement.

**Phase 5** : endpoint REST de lecture. L'API custom `/wp-json/portfolio/v1/*` expose articles et projets au format JSON.

**Phase 6** : API C# ASP.NET Core. Un service .NET consomme le WordPress, met en cache, expose une API publique.

**Phase 7** : front React. Un site fonctionnel consomme l'API C# et affiche les articles et projets.

**Phase 8** : enrichissements à la demande. Mermaid et KaTeX fonctionnent avec lazy loading, côté WordPress comme côté React.

**Phase 9** : webhook Git. Un push sur le repo d'articles déclenche une synchronisation automatique dans WordPress.

**Phase 10** : thème WordPress sobre et SEO. Child theme configuré, Yoast SEO opérationnel, le site WordPress est prêt pour la soutenance académique.

**Phase 11** : déploiement. Les deux portfolios sont en ligne, accessibles publiquement sous leurs domaines respectifs.

Une phase supplémentaire hypothétique — ajouter un frontend desktop en Avalonia qui consommerait `Portfolio.Application` — est une évolution possible si Morgan souhaite approfondir MVVM et le desktop C#. Elle n'est pas planifiée mais rendue possible par la forme de l'architecture.

---

## 8. Ce que l'architecture garantit et ne garantit pas

Comme toute architecture, celle-ci a des points forts délibérés et des compromis assumés.

Elle **garantit** la portabilité du contenu (le Markdown est la source, exportable à tout moment vers n'importe quel système compatible), l'indépendance des composants (le plugin, l'API et le front peuvent être remplacés indépendamment), la compatibilité avec l'écosystème WordPress (le HTML Gutenberg généré est natif, Yoast et les plugins standards fonctionnent), et la possibilité d'ajouter de nouveaux frontends sans dupliquer la logique métier.

Elle **ne garantit pas** la simplicité maximale (trois composants applicatifs plus le plugin, c'est plus qu'un WordPress pur où tout vivrait dans un seul thème), ni la meilleure performance théorique atteignable (le passage par l'API C# ajoute une latence qu'un accès direct au WordPress éviterait), ni l'absence totale de duplication (le Markdown est parsé une fois côté PHP pour générer les blocs Gutenberg, et potentiellement une seconde fois côté C# avec Markdig pour générer le HTML consommé par React).

Ces compromis sont assumés parce qu'ils servent l'objectif principal du projet : apprendre en construisant une architecture moderne et évolutive, tout en respectant la contrainte pédagogique qui impose WordPress.

---

## 9. Stack technologique de référence

Pour consultation rapide, les technologies utilisées dans chaque composant.

| Composant | Technologies principales                                                               |
|---|----------------------------------------------------------------------------------------|
| Plugin WordPress | PHP 8.2+, Composer, PSR-4, `league/commonmark`, `symfony/yaml`, `scrivo/highlight.php` |
| WordPress | WordPress 6.x, MariaDB, thème GeneratePress (child theme custom)                       |
| API C# | .NET 10, ASP.NET Core Minimal APIs, Markdig, IMemoryCache, HttpClient                   |
| Front React | React 18, TypeScript, Vite, React Router, TanStack Query                               |
| Outillage | Git, GitHub, Conventional Commits, PHPUnit, xUnit, Vitest                              |
| Hébergement | Mutualisé pour WP, VPS Linux pour API, JAMstack pour front                             |

---

## 10. Navigation dans la documentation

Pour approfondir, les documents suivants sont à consulter selon le besoin.

| Document | Contenu |
|---|---|
| [`00-overview.md`](00-overview.md) | Ce document |
| [`01-plugin-php.md`](01-plugin-php.md) | Architecture interne du plugin WordPress, pipeline de conversion, API REST exposée, stockage |
| [`02-api-csharp.md`](02-api-csharp.md) | Structure en trois projets, Clean Architecture, cas d'usage détaillés, évolution multi-frontend |
| [`03-frontend-react.md`](03-frontend-react.md) | Structure du front, routes, consommation de l'API, enrichissements |
| [`99-decisions.md`](99-decisions.md) | Journal chronologique des décisions architecturales et de leur justification |

Pour la documentation pédagogique en langage naturel, voir le dossier [`../pedagogie/`](../pedagogie/).

Pour les règles de collaboration avec Claude Code, voir [`../../CLAUDE.md`](../../CLAUDE.md) à la racine du repo.

---

*Ce document est destiné à évoluer à chaque changement structurant du projet. Chaque modification est consignée dans `99-decisions.md`.*

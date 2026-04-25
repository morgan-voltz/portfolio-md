# Architecture du plugin WordPress `portfolio-md`

**Fichier** : `docs/architecture/01-plugin-php.md`
**Rôle** : référence technique complète du plugin WordPress
**Prérequis** : avoir lu [`00-overview.md`](00-overview.md)
**Lectorat** : contributeur PHP, Morgan en phase d'implémentation, relecteur technique
**Temps de lecture** : environ trente minutes
**Dernière mise à jour** : 22 avril 2026

---

## À quoi sert ce document

Ce document détaille l'architecture interne du plugin `portfolio-md`. Il couvre l'organisation du code source, le pipeline de transformation du Markdown vers WordPress, le schéma de stockage en base de données, les trois portes d'entrée qui alimentent le système, et l'API REST que le plugin expose aux consommateurs externes.

Il ne répète pas le contexte général du projet, déjà posé dans l'overview. Il ne contient pas non plus d'implémentation complète prête à copier — les exemples de code sont des signatures, des squelettes ou des fragments illustratifs destinés à clarifier l'architecture, pas à être collés tels quels.

---

## 1. Philosophie d'architecture

Le plugin applique à PHP et à WordPress les principes de la Clean Architecture, dans une version adaptée aux contraintes de l'environnement. Trois idées structurent tout le reste.

La première idée est que **la logique métier ne connaît pas WordPress**. Une classe qui parse du Markdown, une classe qui représente un article, un service qui orchestre l'import : aucune de ces unités ne doit importer une seule fonction WordPress. L'intérêt est double. D'un côté, la logique devient testable sans instancier WordPress, ce qui transforme les tests unitaires en opérations de quelques millisecondes au lieu de plusieurs secondes. De l'autre, si un jour WordPress change radicalement (WordPress 7 avec une API différente, ou migration vers un autre CMS) ou si on veut exposer la même logique hors du contexte WordPress (un outil CLI autonome, par exemple), le cœur du code reste réutilisable sans refonte.

La deuxième idée est que **les hooks WordPress sont des traducteurs, pas des lieux de logique**. Quand WordPress appelle un handler sur un hook `save_post` ou `rest_api_init`, ce handler ne fait rien de métier lui-même. Il extrait les données du contexte WordPress (variables globales, arguments passés par WordPress), les transforme en objets métier propres, et appelle un service applicatif. Le service contient la vraie logique et ne sait pas qu'il a été appelé depuis un hook. Cette discipline est ce qui rend possible d'avoir le même service déclenché par trois portes d'entrée différentes (admin, webhook, import manuel) sans duplication.

La troisième idée est que **les dépendances sont explicites et injectées**. Une classe qui a besoin d'une autre classe la reçoit en paramètre de constructeur, elle ne l'instancie pas elle-même et n'utilise pas de singleton global. C'est le principe d'*injection de dépendances* appliqué sans framework — on n'a pas besoin d'un conteneur DI complet pour un plugin de cette taille, une instanciation manuelle dans `Plugin.php` suffit. Cette explicitation rend les dépendances visibles et le graphe de responsabilités compréhensible en cinq minutes.

---

## 2. Structure de dossiers

L'organisation des fichiers reflète directement l'architecture. Voici l'arborescence complète, avec un commentaire par zone.

```
portfolio-md/
│
├── portfolio-md.php                   # En-tête plugin WordPress + amorçage
├── composer.json                      # Dépendances + autoload PSR-4
├── composer.lock                      # Versions figées (commité)
├── uninstall.php                      # Nettoyage à la désinstallation
├── readme.txt                         # Format wordpress.org (optionnel)
├── .gitignore                         # Exclut vendor/, node_modules/, etc.
│
├── src/                               # Code métier, namespace Morgan\PortfolioMd
│   │
│   ├── Plugin.php                     # Orchestrateur, point d'entrée unique
│   │
│   ├── Domain/                        # Objets métier purs, zéro WordPress
│   │   ├── Article.php                # Value object représentant un article
│   │   ├── Project.php                # Value object pour un projet
│   │   ├── Frontmatter.php            # Métadonnées parsées depuis le YAML
│   │   └── ContentSource.php          # Enum : Admin, Git, Paste
│   │
│   ├── Parsing/                       # Transformations de contenu
│   │   ├── FrontmatterParser.php      # YAML → Frontmatter
│   │   ├── MarkdownParser.php         # Wrapper sur league/commonmark
│   │   └── GutenbergTransformer.php   # HTML → HTML commenté Gutenberg
│   │
│   ├── Storage/                       # Abstraction sur wp_posts et wp_postmeta
│   │   ├── ArticleRepository.php
│   │   ├── ProjectRepository.php
│   │   └── MetaKeys.php               # Constantes des clés meta
│   │
│   ├── PostTypes/                     # Enregistrement CPT et taxonomies
│   │   ├── ProjectPostType.php
│   │   └── TechStackTaxonomy.php
│   │
│   ├── Admin/                         # Interface d'administration WordPress
│   │   ├── EditorMetaBox.php          # Métabox Markdown sur les écrans
│   │   └── ImportPage.php             # Page de collage manuel
│   │
│   ├── Rest/                          # API REST exposée
│   │   ├── RouteRegistrar.php         # Enregistre toutes les routes
│   │   ├── TokenAuthentication.php    # Vérification bearer token pour /sync
│   │   └── Controller/
│   │       ├── ArticleController.php
│   │       ├── ProjectController.php
│   │       └── SyncController.php
│   │
│   └── Service/                       # Cas d'usage applicatifs
│       ├── ContentImportService.php   # Orchestre le pipeline complet
│       ├── MediaImportService.php     # Gère l'import des images
│       └── ConflictResolver.php       # Applique la règle source_of_truth
│
├── assets/                            # Ressources statiques côté navigateur
│   ├── js/
│   │   └── admin-editor.js            # Éditeur Markdown (EasyMDE/CodeMirror)
│   └── css/
│       └── admin-editor.css
│
└── tests/                             # Tests automatisés (PHPUnit)
    ├── Unit/                          # Sans WordPress, ultra-rapides
    │   ├── Parsing/
    │   ├── Domain/
    │   └── Service/
    └── Integration/                   # Avec WP chargé, plus lents
        ├── Rest/
        └── Admin/
```

Le mapping vers la Clean Architecture se lit comme suit. Les dossiers `Domain/` et `Service/` forment le cœur métier — ils sont l'équivalent PHP de ce que serait `Portfolio.Application` en C#. Les dossiers `Parsing/`, `Storage/`, `PostTypes/`, `Admin/`, `Rest/` sont les adaptateurs, équivalent de `Portfolio.Infrastructure` et `Portfolio.Api` combinés. La frontière entre cœur et adaptateurs n'est pas matérialisée par des projets séparés comme en .NET (PHP ne fonctionne pas ainsi) mais par une discipline de namespaces et de dépendances.

La règle que la discipline doit faire respecter : aucun fichier de `Domain/` ou `Service/` ne doit contenir `use WP_Post;` ni appeler de fonction WordPress globale. Si tu vois un `get_post()` dans `Service/ContentImportService.php`, c'est une alarme : cette logique devrait vivre dans un `Storage/ArticleRepository.php` qui, lui, a le droit de parler WordPress.

---

## 3. Le point d'entrée et l'orchestrateur

Le fichier `portfolio-md.php` à la racine contient exclusivement deux choses : l'en-tête de plugin que WordPress reconnaît pour lister le plugin dans l'admin, et l'amorçage minimal qui charge Composer et instancie la classe `Plugin`.

L'en-tête suit le format standard WordPress :

```php
<?php
/**
 * Plugin Name: Portfolio MD
 * Description: Markdown pivot format for articles and projects.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * Requires at least: 6.4
 * Author: Morgan
 * License: GPL-2.0-or-later
 */
```

L'amorçage charge l'autoloader Composer et démarre le plugin via une seule ligne significative. L'intégralité de la logique d'initialisation vit ensuite dans `src/Plugin.php`.

La classe `Plugin` joue le rôle de *composition root* : c'est l'unique endroit du code où sont instanciés les services et où sont accrochés les hooks WordPress. En pratique, son constructeur reçoit les dépendances Composer (comme un environnement de test qui voudrait injecter des mocks), instancie en cascade tous les services du plugin, puis enregistre les hooks WordPress vers les méthodes appropriées des objets construits. Cette centralisation évite l'anti-pattern qu'on voit dans beaucoup de plugins WordPress où chaque fichier enregistre ses propres hooks globalement, créant un graphe de dépendances invisible.

---

## 4. Le pipeline de conversion

C'est le cœur technique du plugin. Il transforme un document Markdown complet (frontmatter plus corps) en un article WordPress pleinement stocké. Le pipeline est séquentiel et chacune de ses phases est pure et testable indépendamment.

Vu de loin, le pipeline ressemble à ceci.

```
  Markdown source complet (avec frontmatter)
                │
                ▼
     [FrontmatterParser]
                │
                ├──► Frontmatter (title, tags, etc.)
                │
                ▼
       Corps Markdown seul
                │
                ▼
       [MarkdownParser]
                │
                ▼
       HTML standard (code déjà colorisé)
                │
                ▼
    [GutenbergTransformer]
                │
                ▼
       HTML commenté Gutenberg
                │
                ▼
   [ArticleRepository]  ou  [ProjectRepository]
                │
                ▼
     wp_posts + wp_postmeta + taxonomies
```

### 4.1. Phase un : extraction du frontmatter

La première phase sépare le frontmatter YAML (entre les deux triples tirets en tête de fichier) du corps Markdown. Le frontmatter est parsé via `symfony/yaml` en tableau associatif PHP, puis transformé en objet `Frontmatter` qui valide et normalise les champs (conversion des dates en `DateTimeImmutable`, des enums en leur type propre, des tags en tableau de strings trimmés).

La classe `FrontmatterParser` a une responsabilité unique : prendre un string Markdown en entrée, renvoyer un tuple composé d'un `Frontmatter` et d'un string contenant le corps sans frontmatter. Si le frontmatter est absent ou mal formé, elle lève une exception typée que les couches supérieures pourront attraper pour produire un message d'erreur utile dans l'admin WordPress ou dans la réponse du webhook Git.

### 4.2. Phase deux : Markdown vers HTML avec coloration syntaxique

La deuxième phase transforme le corps Markdown en HTML standard. Elle utilise `league/commonmark` configuré avec les extensions GFM (tableaux, listes de tâches, barré, liens auto). La coloration syntaxique des blocs de code est faite à cette étape, côté serveur, par une extension custom qui intercepte les nœuds `CodeBlock` de l'AST CommonMark et y applique `scrivo/highlight.php`. Le HTML produit contient déjà les balises `<span>` colorées et les classes appropriées pour que le CSS du thème (côté WordPress) et du front React les stylent correctement.

Ce choix de colorer côté serveur plutôt qu'avec une librairie JavaScript côté client a trois conséquences. Le HTML livré est immédiatement stylisé, donc il n'y a pas de *flash of unstyled content* au chargement. Les lecteurs d'écran et les robots d'indexation voient le code comme du texte propre, sans JavaScript à exécuter. Et les pages sont plus légères puisqu'aucune librairie JS de coloration n'est téléchargée chez le visiteur.

La classe `MarkdownParser` expose une méthode unique `render(string $markdown): string` qui prend le corps Markdown et retourne le HTML standard. Aucune connaissance de WordPress ni de Gutenberg à cette étape.

### 4.3. Phase trois : HTML vers blocs Gutenberg

La troisième phase est la plus spécifique à WordPress. Le HTML produit par la phase précédente est du HTML valide W3C, mais Gutenberg ne sait pas l'éditer tel quel : il attend des commentaires HTML qui délimitent des blocs, comme `<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->`.

La classe `GutenbergTransformer` parcourt le HTML d'entrée élément par élément via un parser DOM (l'extension PHP native `DOMDocument` suffit) et enveloppe chaque élément reconnu dans les commentaires Gutenberg correspondants. La table de correspondance implémentée pour la version initiale couvre les cas les plus courants.

| Élément HTML source | Bloc Gutenberg cible |
|---|---|
| `<h1>` à `<h6>` | `wp:heading` avec attribut `level` |
| `<p>` | `wp:paragraph` |
| `<ul>` | `wp:list` avec attribut `ordered: false` |
| `<ol>` | `wp:list` avec attribut `ordered: true` |
| `<pre><code>` | `wp:code` |
| `<blockquote>` | `wp:quote` |
| `<img>` | `wp:image` |
| `<table>` | `wp:table` |
| `<hr>` | `wp:separator` |
| autre | `wp:html` (bloc HTML brut, reste éditable) |

Les éléments non reconnus tombent dans le bloc `wp:html` qui reste un bloc Gutenberg valide mais sans sémantique spécifique. L'éditeur permettra de le modifier en mode HTML. C'est un filet de sécurité qui garantit qu'un article non-standard ne casse jamais le pipeline.

### 4.4. Phase quatre : distribution des métadonnées vers WordPress

La quatrième phase prend le `Frontmatter` parsé à la phase un et distribue ses champs vers les bons endroits de WordPress. Cette logique vit dans les classes `ArticleRepository` et `ProjectRepository`, qui encapsulent les appels à `wp_insert_post()`, `wp_update_post()`, `wp_set_post_terms()`, et `update_post_meta()`.

Le principe de distribution a été posé dans l'overview : chaque fois qu'il existe un champ natif WordPress qui correspond à un concept du frontmatter, on l'utilise plutôt que d'inventer un champ meta. Cela garantit la compatibilité avec l'écosystème WordPress (plugins, thèmes, outils de migration). Le tableau complet du mapping :

| Champ frontmatter | Destination WordPress | Notes |
|---|---|---|
| `title` | `wp_posts.post_title` | Obligatoire |
| `slug` | `wp_posts.post_name` | Obligatoire, unique |
| `date` | `wp_posts.post_date` | Si absent, date de création |
| `updated` | `wp_posts.post_modified` | Géré automatiquement sinon |
| `category` | taxonomie `category` (term) | Unique par article |
| `tags` | taxonomie `post_tag` (terms) | Liste |
| `stack` (projets) | taxonomie custom `tech_stack` | Liste |
| `seo_description` | meta `_yoast_wpseo_metadesc` | Compatible Yoast |
| `featured` | meta `_portfolio_featured` | Booléen |
| `reading_time` | meta `_portfolio_reading_time` | Entier, minutes |
| `needs_mermaid` | meta `_portfolio_needs_mermaid` | Booléen |
| `needs_katex` | meta `_portfolio_needs_katex` | Booléen |
| `status` (projets) | meta `_portfolio_project_status` | Enum string |
| `role` (projets) | meta `_portfolio_project_role` | String libre |
| `period_start` (projets) | meta `_portfolio_project_period_start` | Date ISO |
| `period_end` (projets) | meta `_portfolio_project_period_end` | Date ISO ou null |
| `repo_url` (projets) | meta `_portfolio_project_repo_url` | URL |
| `demo_url` (projets) | meta `_portfolio_project_demo_url` | URL ou null |
| `client` (projets) | meta `_portfolio_project_client` | String libre |
| `gallery` (projets) | meta `_portfolio_project_gallery` | Array sérialisé |

### 4.5. Phase cinq : métadonnées internes de gestion

La cinquième phase stocke les champs dont le plugin a besoin pour son propre fonctionnement et qui ne sont pas visibles de l'extérieur. Ces champs sont toujours préfixés par `_portfolio_` pour signaler leur caractère interne et suivre la convention WordPress qui cache les metas à préfixe underscore de l'interface « Custom Fields » de l'admin.

| Clé meta | Contenu | Utilité |
|---|---|---|
| `_portfolio_md_source` | Markdown brut complet (avec frontmatter) | Source canonique du contenu |
| `_portfolio_md_hash` | SHA-256 du MD source | Détection de changement, idempotence |
| `_portfolio_source_type` | `admin` / `git` / `paste` | Provenance de la dernière édition |
| `_portfolio_last_sync_at` | Timestamp UTC | Dernière synchro réussie |
| `_portfolio_render_version` | Version du renderer | Invalider le HTML cache en cas d'upgrade |

---

## 5. La règle du `source_of_truth`

Puisque le contenu peut être modifié depuis trois sources distinctes (admin WordPress, webhook Git, import manuel), il faut prévoir les cas de collision. La règle implémentée est volontairement conservative.

À chaque écriture, le champ `_portfolio_source_type` est mis à jour avec la provenance. Quand un webhook Git arrive pour un article existant, la classe `ConflictResolver` compare le hash actuel stocké en base avec le hash du contenu que le webhook voudrait écrire. Si les hashes diffèrent et que `_portfolio_source_type` vaut autre chose que `git`, le plugin considère qu'il y a eu une édition « hors Git » depuis la dernière synchro, et refuse l'écrasement silencieux. Le webhook retourne alors un statut HTTP 409 pour cet article, avec un message descriptif, et Morgan résout manuellement le conflit via l'admin en choisissant quelle version préserver.

Cette logique s'inspire directement du comportement Git lui-même (un push non fast-forward est rejeté, il faut forcer explicitement ou résoudre). Elle protège contre la perte silencieuse de travail, au prix d'une friction occasionnelle quand les sources divergent.

Un mécanisme de *force update* est prévu pour les cas où Morgan veut délibérément écraser une édition admin par un contenu Git (ou vice-versa). Ce mécanisme passe par un paramètre explicite dans la requête et n'est jamais activé par défaut.

---

## 6. Les trois portes d'entrée

Les trois chemins par lesquels du contenu peut entrer dans le plugin convergent tous vers le même service `ContentImportService`. Cette convergence est la raison pour laquelle le code reste compact malgré la flexibilité offerte.

### 6.1. Éditeur Markdown dans l'admin

La classe `Admin\EditorMetaBox` ajoute une métabox custom sur les écrans d'édition des posts et des projets. Cette métabox remplace fonctionnellement l'éditeur Gutenberg natif pour ces types de posts — Gutenberg est désactivé via le filtre `use_block_editor_for_post_type`. L'interface d'édition montre à Morgan un éditeur Markdown riche (basé sur EasyMDE ou CodeMirror 6) avec coloration syntaxique du Markdown lui-même, prévisualisation en parallèle, et insertion facilitée d'images depuis la Media Library WordPress standard.

Au moment où Morgan clique sur « Publier » ou « Mettre à jour », le contenu du textarea est envoyé via le cycle de sauvegarde WordPress standard. La classe `EditorMetaBox` accroche un handler sur le hook `save_post` qui extrait ce contenu, construit un objet `Frontmatter` et un corps Markdown, et appelle `ContentImportService::import($frontmatter, $markdown, ContentSource::Admin)`. Le service applique le pipeline complet, et la fonction retourne juste à temps pour que WordPress complète son cycle de sauvegarde.

Le Markdown source reste visible et modifiable dans la métabox à tout moment, puisqu'il est rechargé depuis `_portfolio_md_source` à chaque rendu de l'écran d'édition.

### 6.2. Endpoint REST `/sync` pour webhook Git

La classe `Rest\Controller\SyncController` gère l'endpoint `POST /wp-json/portfolio/v1/sync`. Il accepte un payload JSON qui décrit une opération de synchronisation depuis un repo Git. La forme typique :

```json
{
  "source": "git",
  "commit_sha": "abc123def456",
  "files": [
    {
      "path": "articles/rust-ownership-intuition.md",
      "content": "---\ntitle: ...\n---\n\nLe vrai problème...",
      "deleted": false
    }
  ]
}
```

L'authentification est faite par la classe `Rest\TokenAuthentication` qui vérifie la présence d'un en-tête `Authorization: Bearer <token>` et la correspondance avec la valeur stockée dans la constante `PORTFOLIO_GIT_SYNC_TOKEN` définie dans `wp-config.php`. Le stockage du secret dans `wp-config.php` (hors web root dans une configuration standard) évite d'exposer le token dans la base ou le code source.

Chaque fichier du payload est traité indépendamment via `ContentImportService`. Les conflits détectés par `ConflictResolver` retournent un statut 409 pour ce fichier spécifique, sans bloquer les autres fichiers du batch. La réponse globale liste le statut de chaque fichier traité.

Le webhook côté Git (GitHub Actions ou équivalent) est configuré pour appeler cet endpoint à chaque push sur la branche principale du repo d'articles. La détection des fichiers modifiés peut être faite côté CI via `git diff --name-only` entre le commit précédent et le nouveau HEAD, ce qui évite d'envoyer tous les articles à chaque push.

### 6.3. Import manuel par collage

La classe `Admin\ImportPage` ajoute une page d'administration dédiée sous le menu du plugin. Cette page présente un textarea large où Morgan peut coller du Markdown (un article complet avec frontmatter) et un bouton d'import. Le traitement est identique aux deux autres entrées : même validation, même pipeline, même service. La différence est que `ContentSource::Paste` est passé au service.

Cette porte d'entrée est utile pour deux cas concrets : la migration de contenu existant (par exemple importer une série d'articles depuis un ancien blog), et les expérimentations ponctuelles où l'on veut tester un article sans l'enregistrer dans le repo Git.

---

## 7. L'API REST exposée

Le plugin expose plusieurs endpoints sous le namespace `/wp-json/portfolio/v1/`, enregistrés par la classe `Rest\RouteRegistrar` au moment du hook `rest_api_init`.

### 7.1. Endpoints publics en lecture

Les endpoints publics ne requièrent pas d'authentification. Cette décision simplifie la consommation par l'API C# et reste sans risque parce que le contenu est, par nature, destiné à être lu.

`GET /articles` retourne la liste paginée des articles, avec filtres optionnels par tag et catégorie via query params. Chaque article du tableau est une représentation complète (Markdown source inclus).

`GET /articles/{slug}` retourne un article spécifique identifié par son slug, 404 si absent.

`GET /projects` et `GET /projects/{slug}` font la même chose pour les projets.

`GET /sitemap` retourne une liste complète (non paginée) des slugs d'articles et de projets avec leurs timestamps de modification. Cet endpoint est destiné aux consommateurs qui implémentent une invalidation de cache intelligente — l'API C# appelle `/sitemap` périodiquement pour détecter quels articles ont changé et n'invalider que ceux-là dans son cache local.

### 7.2. Endpoint `/sync` en écriture

`POST /sync` est documenté en section 6.2.

### 7.3. Forme de la réponse pour un article

La réponse d'un appel `GET /articles/{slug}` ressemble à :

```json
{
  "id": 42,
  "type": "article",
  "slug": "rust-ownership-intuition",
  "title": "Pourquoi Rust a changé ma façon de penser la mémoire",
  "date": "2026-04-22T10:30:00Z",
  "updated": "2026-04-22T10:30:00Z",
  "category": { "slug": "architecture", "name": "Architecture" },
  "tags": [
    { "slug": "rust", "name": "Rust" },
    { "slug": "systems", "name": "Systems" }
  ],
  "seo_description": "Une intuition visuelle du borrow checker.",
  "reading_time": 8,
  "featured": false,
  "needs_mermaid": true,
  "needs_katex": false,
  "markdown": "---\ntitle: ...\n---\n\n# Le vrai problème..."
}
```

Point important : le HTML Gutenberg n'est **pas** retourné par cet endpoint. Les consommateurs externes reçoivent le Markdown brut (frontmatter inclus) et font leur propre rendu selon leurs conventions. Cette décision garantit que le format canonique traverse tout le système sans transformation intermédiaire, et que l'API C# peut appliquer ses propres règles (ancres automatiques sur les titres, enrichissements, etc.).

---

## 8. Import des images et Media Library

Les images référencées dans le Markdown par la syntaxe standard `![alt](chemin/image.png)` posent un problème particulier selon l'origine du contenu. Si le chemin est relatif (cas typique du workflow Git où les images vivent à côté du `.md` dans le repo), le plugin doit uploader les images dans la Media Library WordPress et réécrire les URLs dans le Markdown stocké et dans le HTML dérivé. Si le chemin est absolu et pointe déjà vers `/wp-content/uploads/`, le plugin ne fait rien. Si c'est une URL externe, le plugin la laisse telle quelle (choix initial — on pourra plus tard ajouter un rapatriement automatique si besoin).

La classe `Service\MediaImportService` encapsule cette logique. Elle utilise les fonctions WordPress standard `wp_insert_attachment()` et `wp_generate_attachment_metadata()` pour uploader un fichier et créer les miniatures associées. Elle maintient une table de correspondance entre chemin relatif d'origine et URL finale WordPress, pour éviter de ré-uploader la même image à chaque synchro.

Pour les projets avec une galerie (`gallery` dans le frontmatter), la logique est similaire : chaque entrée de la galerie est un chemin vers une image, et le service importe chacune d'elles.

---

## 9. Custom Post Type et taxonomies

### 9.1. Le CPT `portfolio_project`

La classe `PostTypes\ProjectPostType` enregistre le type de post custom au démarrage de WordPress, via le hook `init`. L'enregistrement configure les capabilities (qui peut éditer, supprimer, publier), les supports de fonctionnalités standards (titre, éditeur, révisions, thumbnail, custom fields), l'icône du menu admin, le slug de l'archive publique (`/projets/`), et les labels traduisibles.

Le choix d'un CPT plutôt que des posts standards avec une catégorie spéciale se justifie par plusieurs raisons déjà évoquées dans l'overview : sémantique distincte (un projet raconte une réalisation, un article discute un sujet), méta-données spécifiques (stack, client, période), organisation par statut plutôt que par date, template de thème dédié.

### 9.2. La taxonomie `tech_stack`

La classe `PostTypes\TechStackTaxonomy` enregistre une taxonomie non-hiérarchique (plate comme les tags, pas arborescente comme les catégories) attachée exclusivement au CPT `portfolio_project`. Elle permet de regrouper les projets par technologies utilisées — tous les projets Rust, tous les projets React, tous les projets qui utilisent PostgreSQL — ce qui alimentera les filtres du front React.

Le choix d'une taxonomie custom plutôt que de réutiliser `post_tag` est une décision d'hygiène : les tags sont partagés entre tous les types de posts, et mélanger les tags conceptuels des articles (« architecture », « tutorial ») avec les tags techniques des projets (« rust », « postgres ») créerait de la confusion.

---

## 10. Dépendances Composer

Le fichier `composer.json` déclare un petit nombre de dépendances soigneusement choisies.

`league/commonmark` en version 2.x est le parser Markdown. Il est maintenu, performant, et modulaire par extensions. Les extensions activées incluent celle pour GFM (tableaux, tâches, barré, liens auto) et une extension custom locale qui plugge `scrivo/highlight.php` sur les blocs de code.

`symfony/yaml` est utilisé uniquement pour parser le frontmatter. C'est une librairie stable, maintenue par Symfony, parfaitement adaptée à ce cas d'usage précis.

`scrivo/highlight.php` fournit la coloration syntaxique côté serveur. C'est un portage PHP de highlight.js qui supporte une centaine de langages, dont tous ceux que Morgan utilisera (Rust, C#, TypeScript, PHP, SQL, JSON, etc.).

En dépendances de développement, `phpunit/phpunit` pour les tests, `yoast/phpunit-polyfills` pour la compatibilité avec les conventions WordPress, et `phpstan/phpstan` pour l'analyse statique qui attrapera les erreurs de typage avant l'exécution.

L'autoload PSR-4 est configuré pour mapper le namespace `Morgan\PortfolioMd\` vers le dossier `src/` :

```json
"autoload": {
    "psr-4": {
        "Morgan\\PortfolioMd\\": "src/"
    }
},
"autoload-dev": {
    "psr-4": {
        "Morgan\\PortfolioMd\\Tests\\": "tests/"
    }
}
```

Avec cette configuration, une classe `Morgan\PortfolioMd\Parsing\MarkdownParser` sera automatiquement chargée depuis `src/Parsing/MarkdownParser.php` à la première utilisation. Aucun `require` manuel n'est nécessaire nulle part dans le code.

---

## 11. Sécurité

Trois surfaces d'attaque principales sont à considérer.

La première est l'**endpoint `/sync`** authentifié par bearer token. La sécurité repose sur la confidentialité du token. Il doit être suffisamment long et aléatoire (au moins trente-deux caractères), stocké dans `wp-config.php` hors du web root, transmis uniquement via HTTPS, et renouvelable facilement par redéfinition de la constante. La comparaison du token côté serveur utilise `hash_equals()` pour se prémunir contre les timing attacks.

La deuxième est l'**éditeur admin** qui accepte du Markdown brut. Un contributeur malveillant avec les droits d'édition pourrait tenter d'injecter du HTML dangereux via Markdown (certaines implémentations Markdown autorisent le HTML inline). La configuration de `league/commonmark` désactive l'interprétation du HTML inline (`html_input: 'strip'` ou `'escape'`), et le HTML produit par le pipeline passe par `wp_kses_post()` avant stockage — cette fonction WordPress standard supprime les balises et attributs non autorisés (scripts, iframes, event handlers).

La troisième est l'**import d'images**. Les fichiers uploadés passent par le flux standard WordPress qui applique ses propres vérifications (extension, type MIME, taille max). On s'appuie ici sur la robustesse de WordPress plutôt que de réimplémenter des vérifications.

Les capabilities WordPress sont respectées partout. Seuls les utilisateurs disposant de `edit_posts` peuvent déclencher l'éditeur admin et la page d'import manuel. L'endpoint `/sync`, étant authentifié par token uniquement, opère en mode « super-admin » — le token étant un secret partagé entre le repo Git et le serveur, sa possession équivaut à une autorisation complète sur le plugin.

---

## 12. Tests

La stratégie de tests exploite la séparation claire entre code métier et code couplé à WordPress.

Les **tests unitaires** couvrent tous les fichiers des dossiers `Domain/`, `Parsing/`, et `Service/`. Ces tests n'ont besoin d'aucun environnement WordPress : ils instancient directement les classes, injectent des doubles de tests pour les dépendances, et vérifient les comportements. Ils s'exécutent en quelques millisecondes chacun. Exemples de tests : « étant donné un frontmatter YAML valide, le FrontmatterParser retourne un objet Frontmatter avec les bons champs typés » ; « étant donné un paragraphe HTML, le GutenbergTransformer l'enveloppe dans les commentaires `wp:paragraph` attendus » ; « étant donné un article avec hash H stocké en base et une tentative d'écrasement par un contenu Git, si le type de source stocké est admin, ConflictResolver refuse l'écriture ».

Les **tests d'intégration** couvrent les fichiers qui touchent WordPress directement : `Storage/`, `Rest/`, `Admin/`. Ils nécessitent une instance WordPress de test chargée en mémoire, fournie par le framework de tests WordPress officiel (`wp-phpunit`). Ils sont plus lents (quelques secondes par test) et couvrent les cas où la logique s'entrelace avec les mécaniques WordPress : enregistrement correct d'un CPT, persistance d'un article via les fonctions WP, validation d'une route REST et de sa réponse.

La commande `composer test` orchestre les deux suites. En développement, Morgan peut lancer seulement les tests unitaires (`composer test:unit`) pour un cycle de feedback rapide, et réserver la suite complète pour les moments de validation ou la CI.

---

## 13. Relation avec le thème WordPress

Le plugin est volontairement découplé du thème, mais certains points de coordination sont nécessaires.

Le plugin produit du HTML Gutenberg standard. Tout thème compatible avec les blocs Gutenberg natifs affichera correctement les articles produits par le plugin, y compris les thèmes de base comme GeneratePress, Kadence ou les thèmes officiels WordPress (Twenty Twenty-Four, etc.).

Le thème peut avoir besoin de templates dédiés pour le CPT `portfolio_project`. La convention WordPress de hiérarchie des templates permet de créer `single-portfolio_project.php` et `archive-portfolio_project.php` dans le child theme pour customiser l'affichage des projets sans toucher au plugin.

Pour les enrichissements à la demande (Mermaid, KaTeX), le thème doit lire les flags `_portfolio_needs_mermaid` et `_portfolio_needs_katex` en début de rendu et charger conditionnellement les assets correspondants. Cette logique vit dans le thème, pas dans le plugin, parce qu'elle concerne le rendu et non le stockage.

La documentation du child theme sera produite séparément quand la phase dix de la feuille de route sera atteinte.

---

## 14. Navigation

Pour plus de contexte ou pour approfondir un aspect spécifique :

| Document | Contenu |
|---|---|
| [`00-overview.md`](00-overview.md) | Vue d'ensemble et principes directeurs du projet |
| [`02-api-csharp.md`](02-api-csharp.md) | L'API C# qui consomme cette API REST exposée par le plugin |
| [`03-frontend-react.md`](03-frontend-react.md) | Le front qui consomme indirectement ce plugin via l'API C# |
| [`99-decisions.md`](99-decisions.md) | Journal des décisions architecturales |
| [`../../CLAUDE.md`](../../CLAUDE.md) | Règles de collaboration avec Claude Code |

---

*Ce document évolue avec le plugin lui-même. Chaque changement structurant du code doit être reflété ici et consigné dans le journal des décisions.*

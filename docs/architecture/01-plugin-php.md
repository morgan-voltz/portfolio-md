# Architecture du plugin WordPress `portfolio-md`

**Fichier** : `docs/architecture/01-plugin-php.md`
**Rôle** : référence technique complète du plugin WordPress
**Prérequis** : avoir lu [`00-overview.md`](00-overview.md)
**Lectorat** : contributeur PHP, Morgan en phase d'implémentation, relecteur technique
**Temps de lecture** : environ trente minutes
**Dernière mise à jour** : 26 octobre 2026 (pipeline Markdown → Gutenberg et import WP-CLI implémentés ; restauration et mise en cohérence avec le code le même jour ; version d'origine du 22 avril 2026)

---

## À quoi sert ce document

Ce document détaille l'architecture interne du plugin `portfolio-md`. Il couvre l'organisation du code source, le pipeline de transformation du Markdown vers WordPress, le schéma de stockage en base de données, les trois portes d'entrée qui alimentent le système, et l'API REST que le plugin expose aux consommateurs externes.

Il ne répète pas le contexte général du projet, déjà posé dans l'overview. Il ne contient pas non plus d'implémentation complète prête à copier — les exemples de code sont des signatures, des squelettes ou des fragments illustratifs destinés à clarifier l'architecture, pas à être collés tels quels.

> **Note de restauration (26 octobre 2026).** Ce document avait été supprimé du dépôt par erreur le 27 avril 2026 (commit `484aebc`), alors qu'une vingtaine de liens continuaient de pointer vers lui. Il a été restauré depuis l'historique git et mis en cohérence avec le code réel : namespace `Voltz\PortfolioMd`, post types `article` et `project`, taxonomies `tech_tag`, `project_status` et `skill`, champs personnalisés tels qu'implémentés. Pour le **modèle de contenu** (types, taxonomies, champs et leurs règles de sanitization), [`04-structure-wordpress.md`](04-structure-wordpress.md) est la référence ; ce document s'y réfère plutôt que de dupliquer ses tableaux. Les parties non encore implémentées (pipeline, portes d'entrée, API REST) décrivent toujours la cible et sont marquées ⏳ dans l'arborescence ci-dessous.

---

## 1. Philosophie d'architecture

Le plugin applique à PHP et à WordPress les principes de la Clean Architecture, dans une version adaptée aux contraintes de l'environnement. Trois idées structurent tout le reste.

La première idée est que **la logique métier ne connaît pas WordPress**. Une classe qui parse du Markdown, une classe qui représente un article, un service qui orchestre l'import : aucune de ces unités ne doit importer une seule fonction WordPress. L'intérêt est double. D'un côté, la logique devient testable sans instancier WordPress, ce qui transforme les tests unitaires en opérations de quelques millisecondes au lieu de plusieurs secondes. De l'autre, si un jour WordPress change radicalement (WordPress 7 avec une API différente, ou migration vers un autre CMS) ou si on veut exposer la même logique hors du contexte WordPress (un outil CLI autonome, par exemple), le cœur du code reste réutilisable sans refonte.

La deuxième idée est que **les hooks WordPress sont des traducteurs, pas des lieux de logique**. Quand WordPress appelle un handler sur un hook `save_post` ou `rest_api_init`, ce handler ne fait rien de métier lui-même. Il extrait les données du contexte WordPress (variables globales, arguments passés par WordPress), les transforme en objets métier propres, et appelle un service applicatif. Le service contient la vraie logique et ne sait pas qu'il a été appelé depuis un hook. Cette discipline est ce qui rend possible d'avoir le même service déclenché par trois portes d'entrée différentes (admin, webhook, import manuel) sans duplication.

La troisième idée est que **les dépendances sont explicites et injectées**. Une classe qui a besoin d'une autre classe la reçoit en paramètre de constructeur, elle ne l'instancie pas elle-même et n'utilise pas de singleton global. C'est le principe d'*injection de dépendances* appliqué sans framework — on n'a pas besoin d'un conteneur DI complet pour un plugin de cette taille, une instanciation manuelle dans `Plugin.php` suffit. Cette explicitation rend les dépendances visibles et le graphe de responsabilités compréhensible en cinq minutes.

---

## 2. Structure de dossiers

L'organisation des fichiers reflète directement l'architecture. Voici l'arborescence complète, avec un commentaire par zone. ✅ marque ce qui existe, ⏳ ce qui reste à construire.

```
portfolio-md/
│
├── portfolio-md.php                   # En-tête plugin WordPress + amorçage              ✅
├── composer.json                      # Dépendances + autoload PSR-4 + scripts de test   ✅
├── composer.lock                      # Versions figées (commité)                        ✅
├── phpunit.xml.dist                   # Configuration de la suite unitaire               ✅
├── uninstall.php                      # Nettoyage à la désinstallation                   ⏳
├── readme.txt                         # Format wordpress.org (optionnel)                 ⏳
│
├── src/                               # Code du plugin, namespace Voltz\PortfolioMd
│   │
│   ├── Plugin.php                     # Composition root, point d'entrée unique          ✅
│   │
│   ├── Domain/                        # Objets métier purs, zéro WordPress
│   │   ├── ContentSource.php          # Enum : Admin, Git, Paste                         ✅
│   │   ├── Frontmatter.php            # Métadonnées typées et validées du YAML           ✅
│   │   ├── ContentType.php            # Enum : Article, Project, Page                    ✅
│   │   ├── ProjectStatus.php          # Enum des statuts de projet (= taxonomie)         ✅
│   │   ├── Skill.php, Referential.php, SkillsGrid.php   # Grille de compétences (ADR-014)  ✅
│   │   └── Patterns.php               # Formes partagées : slug, codes de compétence       ✅
│   │
│   ├── PostTypes/
│   │   └── PostTypeRegistrar.php      # CPT article et project, constantes de slug       ✅
│   │
│   ├── Taxonomies/
│   │   └── TaxonomyRegistrar.php      # tech_tag, project_status, skill + termes fermés  ✅
│   │
│   ├── Meta/                          # Champs personnalisés (post metas, term metas)
│   │   ├── Sanitizer.php              # Primitives de sanitization pures, partagées      ✅
│   │   ├── MetaRegistration.php       # Helpers d'enregistrement + auth_callback         ✅
│   │   ├── MetaWriter.php             # Écriture d'une meta depuis PHP (wp_slash)        ✅
│   │   ├── CommonMetaRegistrar.php    # 4 metas publiques, article + project             ✅
│   │   ├── ProjectMetaRegistrar.php   # 8 metas publiques, project                       ✅
│   │   └── InternalMetaRegistrar.php  # 5 metas internes, verrouillées, hors REST        ✅
│   │
│   ├── Parsing/                       # Transformations de contenu
│   │   ├── FrontmatterParser.php      # Markdown → ParsedDocument (Frontmatter + corps)   ✅
│   │   ├── SkillsGridLoader.php       # content/skills.yaml → SkillsGrid validée          ✅
│   │   ├── MarkdownParser.php         # Corps Markdown → HTML standard (GFM, HTML échappé) ✅
│   │   ├── HighlightedCodeRenderer.php # Blocs de code colorés côté serveur (highlight.php) ✅
│   │   ├── GutenbergTransformer.php   # HTML → HTML commenté Gutenberg (DOMDocument)      ✅
│   │   └── ContentRenderer.php        # Phases deux + trois en un appel, porte VERSION    ✅
│   │
│   ├── Storage/                       # Adaptateurs vers les tables WordPress
│   │   ├── WordPressSkillTermGateway.php  # Termes de la taxonomie skill (port de Service) ✅
│   │   └── WordPressContentRepository.php # Posts, termes et metas des articles et projets ✅
│   │
│   ├── Admin/                         # Interface d'administration WordPress             ⏳
│   │   ├── EditorMetaBox.php          # Métabox Markdown sur les écrans
│   │   └── ImportPage.php             # Page de collage manuel
│   │
│   ├── Rest/                          # API REST exposée                                 ⏳
│   │   ├── RouteRegistrar.php         # Enregistre toutes les routes
│   │   ├── TokenAuthentication.php    # Vérification bearer token pour /sync
│   │   └── Controller/
│   │       ├── ArticleController.php
│   │       ├── ProjectController.php
│   │       └── SyncController.php
│   │
│   ├── Service/                       # Cas d'usage applicatifs
│   │   ├── SkillTermSynchronizer.php  # Grille → taxonomie skill, via le port SkillTermGateway ✅
│   │   ├── SkillTermGateway.php, SkillTermRecord.php, SyncReport.php                     ✅
│   │   ├── ContentImportService.php   # Orchestre le pipeline complet                    ✅
│   │   ├── ConflictResolver.php       # Applique la règle source_of_truth (ADR-005)      ✅
│   │   ├── ContentRepository.php, ContentRecord.php, StoredContent.php   # Port du dépôt  ✅
│   │   ├── ImportOptions.php, ImportOutcome.php, ImportResult.php, ImportException.php   ✅
│   │   ├── Clock.php, SystemClock.php # L'heure de synchro, remplaçable dans les tests    ✅
│   │   └── MediaImportService.php     # Gère l'import des images                         ⏳
│   │
│   └── Cli/                           # Commandes WP-CLI (un hôte, comme Admin/ et Rest/)
│       ├── SkillsCommand.php          # wp portfolio skills sync                         ✅
│       └── ImportCommand.php          # wp portfolio import                              ✅
│
├── assets/                            # Ressources statiques côté navigateur             ⏳
│   ├── js/admin-editor.js             # Éditeur Markdown (EasyMDE/CodeMirror)
│   └── css/admin-editor.css
│
└── tests/
    ├── bootstrap.php                  # Autoload + doublures WordPress                   ✅
    ├── Support/                       # WordPressSpy, wp-stubs, doublures en mémoire des ports ✅
    ├── Unit/                          # Sans WordPress, ultra-rapides                     ✅
    │   ├── Domain/, Meta/, PostTypes/, Taxonomies/
    │   └── Parsing/, Service/ (pipeline et synchronisation des compétences)              ✅
    └── Integration/
        └── wp-cli/                    # Tests de fumée joués via `wp eval-file` :
            ├── meta-smoke.php         #   modèle de contenu (metas, capacités, REST)       ✅
            ├── skills-smoke.php       #   synchronisation des compétences, passerelle WP  ✅
            └── import-smoke.php       #   import complet : posts, termes, metas, conflits ✅
```

Trois écarts volontaires par rapport au plan d'origine. Le fichier `Storage/MetaKeys.php` prévu pour centraliser les clés meta n'existe pas : chaque clé est une **constante publique du registrar** qui l'enregistre (`CommonMetaRegistrar::FEATURED`, `InternalMetaRegistrar::MD_SOURCE`, …), ce qui empêche la clé et son enregistrement de dériver l'un de l'autre. Les classes `ProjectPostType` / `TechStackTaxonomy` ont été remplacées par deux registrars (`PostTypeRegistrar`, `TaxonomyRegistrar`) qui portent chacun tous les types ou toutes les taxonomies : moins de fichiers, même responsabilité par fichier. Enfin, les deux repositories prévus (`ArticleRepository`, `ProjectRepository`) et les value objects `Article` / `Project` n'existent pas : un seul `Storage\WordPressContentRepository` écrit les deux post types, parce que leurs différences tiennent en trois lignes (statut, compétences, metas projet), et le pipeline transporte un `Frontmatter` typé plus un corps, ce qui rendait les deux objets redondants.

Le mapping vers la Clean Architecture se lit comme suit. Les dossiers `Domain/` et `Service/` forment le cœur métier — ils sont l'équivalent PHP de ce que serait `Portfolio.Application` en C#. Les dossiers `Parsing/`, `Storage/`, `PostTypes/`, `Taxonomies/`, `Meta/`, `Admin/`, `Rest/` sont les adaptateurs, équivalent de `Portfolio.Infrastructure` et `Portfolio.Api` combinés. La frontière entre cœur et adaptateurs n'est pas matérialisée par des projets séparés comme en .NET (PHP ne fonctionne pas ainsi) mais par une discipline de namespaces et de dépendances.

La règle que la discipline doit faire respecter : aucun fichier de `Domain/` ou `Service/` ne doit contenir `use WP_Post;` ni appeler de fonction WordPress globale. Si tu vois un `get_post()` dans `Service/ContentImportService.php`, c'est une alarme : cette logique devrait vivre dans `Storage/WordPressContentRepository.php` qui, lui, a le droit de parler WordPress. Les registrars de `Meta/` illustrent la nuance : la classe appelle `register_post_meta()` (adaptateur), mais ses sanitizers délèguent à `Meta\Sanitizer`, dont presque toutes les fonctions sont du PHP pur testable sans WordPress.

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

La classe `Plugin` joue le rôle de *composition root* : c'est l'unique endroit du code où sont instanciés les services et où sont accrochés les hooks WordPress. En pratique, son constructeur instancie en cascade tous les services du plugin (aujourd'hui les cinq registrars), puis `boot()` enregistre les hooks WordPress vers les méthodes appropriées des objets construits : `init` pour les enregistrements, les hooks d'activation et de désactivation pour l'insertion des termes fermés et le rafraîchissement des règles de réécriture d'URL. Cette centralisation évite l'anti-pattern qu'on voit dans beaucoup de plugins WordPress où chaque fichier enregistre ses propres hooks globalement, créant un graphe de dépendances invisible.

---

## 4. Le pipeline de conversion

C'est le cœur technique du plugin. Il transforme un document Markdown complet (frontmatter plus corps) en un article WordPress pleinement stocké. Le pipeline est séquentiel et chacune de ses phases est pure et testable indépendamment. ✅ Les cinq phases sont implémentées depuis le 26 octobre 2026 pour les articles et les projets ; les pages statiques (`type: page`) sont refusées par le service tant que les champs internes de la phase cinq ne sont pas enregistrés sur le type `page` (`04-structure-wordpress.md` §3.4).

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

✅ **Implémenté le 26 octobre 2026.** `Parsing\FrontmatterParser::parse()` renvoie un `ParsedDocument` (le `Domain\Frontmatter` typé et le corps). Deux familles d'erreurs, pour que l'auteur sache quoi corriger : `Parsing\MalformedDocumentException` quand le bloc manque ou que le YAML ne se lit pas, `Domain\InvalidFrontmatterException` quand une clé est inconnue, absente ou mal typée. La validation est volontairement stricte (booléens YAML obligatoires, dates calendaires, URL en `http(s)`, codes de compétences au format `4.0.2/E07`, clés autorisées par type de contenu) : la sanitization des metas (ADR-013) reste la dernière ligne de défense, pas la première. Détail à connaître : le YAML est lu avec `Yaml::PARSE_DATETIME`, sans quoi `symfony/yaml` transforme une date nue en entier.

### 4.2. Phase deux : Markdown vers HTML avec coloration syntaxique

La deuxième phase transforme le corps Markdown en HTML standard. Elle utilise `league/commonmark` configuré avec les extensions GFM (tableaux, listes de tâches, barré, liens auto). La coloration syntaxique des blocs de code est faite à cette étape, côté serveur, par une extension custom qui intercepte les nœuds `CodeBlock` de l'AST CommonMark et y applique `scrivo/highlight.php`. Le HTML produit contient déjà les balises `<span>` colorées et les classes appropriées pour que le CSS du thème (côté WordPress) et du front React les stylent correctement.

Ce choix de colorer côté serveur plutôt qu'avec une librairie JavaScript côté client a trois conséquences. Le HTML livré est immédiatement stylisé, donc il n'y a pas de *flash of unstyled content* au chargement. Les lecteurs d'écran et les robots d'indexation voient le code comme du texte propre, sans JavaScript à exécuter. Et les pages sont plus légères puisqu'aucune librairie JS de coloration n'est téléchargée chez le visiteur.

La classe `MarkdownParser` expose une méthode unique `render(string $markdown): string` qui prend le corps Markdown et retourne le HTML standard. Aucune connaissance de WordPress ni de Gutenberg à cette étape.

**Tel qu'implémenté.** `league/commonmark` 2.x avec `CommonMarkCoreExtension` et `GithubFlavoredMarkdownExtension`, `html_input: escape` (un `<b>` écrit dans le Markdown apparaît en clair plutôt que de disparaître, pour que l'écart au contrat reste visible), `allow_unsafe_links: false` (les schémas `javascript:`, `vbscript:`, `file:` et `data:` hors images perdent leur `href` ou `src`), `max_nesting_level: 100`. La coloration est un renderer de nœuds (`HighlightedCodeRenderer`) enregistré pour `FencedCode` et `IndentedCode` avec une priorité supérieure à celle des renderers du cœur, donc interrogé avant eux. Un bloc ```` ```php ```` donne `<pre><code class="hljs language-php">…</code></pre>` avec les `<span class="hljs-…">` de highlight.js ; une langue inconnue de highlight.php garde sa classe `language-…` et un texte échappé ; un bloc sans langue reste nu. Le mot de langue est mis en minuscules et doit ressembler à un nom de langue (lettres, chiffres, `+ # . - _`), sinon il est ignoré : l'info string vient du contenu et finit dans un attribut. Le colorateur charge ses 185 grammaires à la première utilisation (une quinzaine de millisecondes), pas au démarrage du plugin. `ContentRenderer` enchaîne les phases deux et trois et porte `ContentRenderer::VERSION`, la version de rendu écrite en base (§4.5) : à incrémenter dès que la sortie du parseur ou du transformateur change, y compris après une mise à jour de `league/commonmark` ou de `highlight.php` qui modifierait le HTML produit.

### 4.3. Phase trois : HTML vers blocs Gutenberg

La troisième phase est la plus spécifique à WordPress. Le HTML produit par la phase précédente est du HTML valide W3C, mais Gutenberg ne sait pas l'éditer tel quel : il attend des commentaires HTML qui délimitent des blocs, comme `<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->`.

La classe `GutenbergTransformer` parcourt le HTML d'entrée élément par élément via `DOMDocument` (extension PHP native ; le fragment est chargé dans un `<div>` enveloppe précédé d'une déclaration XML `UTF-8`, seule façon de faire comprendre l'encodage au parseur HTML de libxml) et sérialise chaque élément de bloc exactement comme Gutenberg le fait lui-même, classes d'habillage comprises : c'est à cette condition que l'éditeur valide les blocs au lieu de les signaler comme « contenu inattendu ».

| Élément HTML source | Bloc Gutenberg produit |
|---|---|
| `<h1>` à `<h6>` | `wp:heading`, `<hN class="wp-block-heading">`, attribut `level` sauf pour `h2` (niveau par défaut de l'éditeur) |
| `<p>` | `wp:paragraph` ; un paragraphe qui ne contient qu'une image, éventuellement dans un lien, devient `wp:image` (`<figure class="wp-block-image">`, `linkDestination: custom` s'il y a un lien) |
| `<ul>` / `<ol>` | `wp:list` (`ordered: true`, `start` s'il diffère de 1) avec un bloc interne `wp:list-item` par `<li>` ; les listes imbriquées sont des `wp:list` dans le `<li>` ; le paragraphe unique d'une liste « aérée » est déballé |
| `<pre><code>` | `wp:code`, `<pre class="wp-block-code …"><code>` : les classes de coloration (`hljs language-php`) passent sur le `<pre>` via `className`, parce que le bloc valide un `<code>` sans attribut ; les `[` sont écrits `&#91;` comme Gutenberg le fait, pour qu'aucun shortcode ne soit jamais interprété dans du code |
| `<blockquote>` | `wp:quote`, `<blockquote class="wp-block-quote">` dont le contenu est transformé récursivement en blocs internes |
| `<table>` | `wp:table` (`hasFixedLayout: false`, explicite pour ne pas dépendre de la valeur par défaut de l'éditeur), `<figure class="wp-block-table">` ; l'alignement GFM (`align="center"`) devient la classe `has-text-align-center` ; les retours à la ligne entre lignes et sections sont retirés |
| `<hr>` | `wp:separator`, `<hr class="wp-block-separator has-alpha-channel-opacity"/>` |
| autre | `wp:html` (bloc HTML brut, reste éditable en mode HTML) |

Une liste que l'éditeur ne saurait pas représenter tombe entière dans `wp:html` plutôt que d'être aplatie : liste de tâches GFM (les cases sont des `<input>`, qu'un `wp:list-item` ne peut pas porter), item contenant plusieurs paragraphes, un bloc de code ou une citation. Les éléments non reconnus tombent de même dans `wp:html`, bloc Gutenberg valide mais sans sémantique. C'est le filet de sécurité qui garantit qu'un document non standard ne casse jamais le pipeline ; un test unitaire rend les quatorze fiches et les deux pages de `content/` et vérifie qu'aucune n'en a besoin. Les attributs JSON des commentaires de bloc sont échappés comme le fait le sérialiseur de Gutenberg (`--`, `<`, `>`, `&` et `"` en séquences `\u` suivies du code), pour qu'aucune valeur ne puisse fermer le commentaire.

### 4.4. Phase quatre : distribution des métadonnées vers WordPress

La quatrième phase prend le `Frontmatter` parsé à la phase un et distribue ses champs vers les bons endroits de WordPress. Cette logique vit dans `Storage\WordPressContentRepository`, derrière le port `Service\ContentRepository` (deux méthodes : `findBySlug()` et `save()`), qui encapsule les appels à `wp_insert_post()`, `wp_update_post()`, `wp_set_object_terms()` et `Meta\MetaWriter::update()`.

Le principe de distribution a été posé dans l'overview : chaque fois qu'il existe un champ natif WordPress qui correspond à un concept du frontmatter, on l'utilise plutôt que d'inventer un champ meta. Cela garantit la compatibilité avec l'écosystème WordPress (plugins, thèmes, outils de migration). Le tableau complet du mapping, aligné sur les champs effectivement enregistrés (`04-structure-wordpress.md` §4 et §5) :

| Champ frontmatter | Destination WordPress | Notes |
|---|---|---|
| `title` | `wp_posts.post_title` | Obligatoire |
| `slug` | `wp_posts.post_name` | Obligatoire, unique |
| `date` | `wp_posts.post_date` | Jour calendaire du frontmatter, à minuit dans le fuseau du site (`post_date` et `post_date_gmt` écrits ensemble) ; si absent, date de création, conservée ensuite |
| `updated` | `wp_posts.post_modified` | Géré automatiquement par WordPress |
| `type` | post type `article` ou `project` | Guide le choix du CPT |
| `tags` | taxonomie `tech_tag` | Liste de slugs, partagée article + projet ; un tag inconnu est créé avec son slug pour nom, à renommer dans l'admin |
| `skills` (projets) | taxonomie `skill` | Liste de codes (`4.0.1/FR-13`) ; chaque code retrouve son terme via le term meta `_portfolio_skill_code` ; un code absent de la taxonomie fait **échouer** l'import, la grille `content/skills.yaml` étant la source des intitulés (ADR-014, note d'implémentation) |
| `status` (projets) | taxonomie `project_status` **et** meta `_portfolio_project_status` | Résolu en terme par son slug ; la taxonomie est la source de vérité côté UI, la meta un miroir plat |
| `subtitle` | meta `_portfolio_subtitle` | Texte court |
| `seo_description` | meta `_portfolio_seo_description` | Tronqué à 160 caractères ; recopie vers `_yoast_wpseo_metadesc` à prévoir ici si Yoast est actif |
| `featured` | meta `_portfolio_featured` | Booléen |
| `reading_time` | meta `_portfolio_reading_time` | Entier, 0 à 240 minutes |
| `is_fil_rouge` (projets) | meta `_portfolio_project_is_fil_rouge` | Booléen (ADR-011) |
| `role` (projets) | meta `_portfolio_project_role` | String libre |
| `period_start` (projets) | meta `_portfolio_project_period_start` | Date ISO, jour calendaire réel |
| `period_end` (projets) | meta `_portfolio_project_period_end` | Date ISO ou vide |
| `repo_url` (projets) | meta `_portfolio_project_repo_url` | URL `http`/`https` |
| `demo_url` (projets) | meta `_portfolio_project_demo_url` | URL `http`/`https` ou vide |
| `client` (projets) | meta `_portfolio_project_client` | String libre |

Trois champs du plan d'origine ont disparu de ce tableau. `gallery` a été écarté le 25 avril 2026 au profit des blocs Gutenberg natifs (`04-structure-wordpress.md` §5.4). `needs_mermaid` et `needs_katex` sont reportés jusqu'à ce qu'ils aient un producteur et un consommateur (ADR-013). `category` enfin, présent dans l'ancien exemple de frontmatter de l'overview, est abandonné : la taxonomie native n'est pas utilisée (`04-structure-wordpress.md` §4.4) et les tags techniques suffisent (ADR-015).

Les valeurs sont validées deux fois : par le pipeline, qui remonte une erreur lisible à l'auteur, et par le `sanitize_callback` enregistré avec chaque meta, dernière ligne de défense (`Meta\Sanitizer`). Une valeur invalide qui atteint la seconde est stockée comme valeur neutre (`''`, `false`, `0`), jamais « réparée ».

Trois règles WordPress que le dépôt applique, vérifiées par le test de fumée `import-smoke.php`. `wp_insert_post()` et `wp_update_post()` déslashent leurs entrées comme `update_post_meta()` : le tableau du post passe par `wp_slash()`, sinon les antislashs d'un bloc de code disparaîtraient. WordPress peut suffixer un slug déjà pris par un autre post du même type (`wp_unique_post_slug()`) : le dépôt le refuse bruyamment, et supprime le post qu'il vient de créer avec le mauvais slug, pour que le fichier Markdown et WordPress ne divergent jamais. Un post existant garde son statut (`publish`, `draft`) et son auteur : l'import ne fixe les deux qu'à la création. Le HTML dérivé passe par `wp_kses()` avec les balises du contexte `post` plus la case à cocher des listes de tâches (§11).

### 4.5. Phase cinq : métadonnées internes de gestion

La cinquième phase stocke les champs dont le plugin a besoin pour son propre fonctionnement et qui ne sont pas visibles de l'extérieur. Ces champs sont toujours préfixés par `_portfolio_` pour signaler leur caractère interne et suivre la convention WordPress qui cache les metas à préfixe underscore de l'interface « Custom Fields » de l'admin.

| Clé meta | Contenu | Utilité |
|---|---|---|
| `_portfolio_md_source` | Markdown brut complet (avec frontmatter), stocké verbatim | Source canonique du contenu |
| `_portfolio_md_hash` | SHA-256 hexadécimal du MD source | Détection de changement, idempotence |
| `_portfolio_source_type` | `admin` / `git` / `paste` (enum `Domain\ContentSource`) | Provenance de la dernière édition |
| `_portfolio_last_sync_at` | Date-heure ISO 8601 normalisée en UTC | Dernière synchro réussie |
| `_portfolio_render_version` | Version du renderer (`MAJEUR.MINEUR.CORRECTIF`) | Invalider le HTML dérivé en cas d'upgrade |

Ces cinq champs sont enregistrés par `Meta\InternalMetaRegistrar` avec `show_in_rest => false` et `auth_callback => '__return_false'` : invisibles dans l'API REST, et aucun utilisateur ne peut les écrire quel que soit son rôle. Seul le code du plugin les écrit, et toujours via `Meta\MetaWriter::update()` plutôt que `update_post_meta()` directement : WordPress déslashe la valeur qu'on lui donne avant de la stocker, ce qui ferait disparaître les antislashs du Markdown (`\*`, `C:\chemin`) et invaliderait le hash. Le helper applique `wp_slash()` au préalable, comme l'API REST de WordPress le fait pour ses propres écritures.

La version de rendu est `Parsing\ContentRenderer::VERSION`. L'heure de synchronisation vient du port `Service\Clock` (`SystemClock` en production, horloge figée dans les tests) et est écrite en UTC au format `AAAA-MM-JJThh:mm:ssZ`. Le hash couvre le document tel qu'il a été reçu, octet pour octet, et c'est ce même texte qui est stocké dans `_portfolio_md_source`.

---

## 5. La règle du `source_of_truth`

Puisque le contenu peut être modifié depuis trois sources distinctes (admin WordPress, webhook Git, import manuel), il faut prévoir les cas de collision. La règle implémentée est volontairement conservative.

À chaque écriture, le champ `_portfolio_source_type` est mis à jour avec la provenance. Quand un webhook Git arrive pour un article existant, la classe `ConflictResolver` compare le hash actuel stocké en base avec le hash du contenu que le webhook voudrait écrire. Si les hashes diffèrent et que `_portfolio_source_type` vaut autre chose que `git`, le plugin considère qu'il y a eu une édition « hors Git » depuis la dernière synchro, et refuse l'écrasement silencieux. Le webhook retourne alors un statut HTTP 409 pour cet article, avec un message descriptif, et Morgan résout manuellement le conflit via l'admin en choisissant quelle version préserver.

Cette logique s'inspire directement du comportement Git lui-même (un push non fast-forward est rejeté, il faut forcer explicitement ou résoudre). Elle protège contre la perte silencieuse de travail, au prix d'une friction occasionnelle quand les sources divergent.

Un mécanisme de *force update* est prévu pour les cas où Morgan veut délibérément écraser une édition admin par un contenu Git (ou vice-versa). Ce mécanisme passe par un paramètre explicite dans la requête et n'est jamais activé par défaut.

**Tel qu'implémenté (26 octobre 2026).** La règle est une décision pure dans `Service\ConflictResolver::decide()`, appliquée par `ContentImportService` à chaque import, quelle que soit la porte : pas de post sous ce slug → création ; même hash et même version de rendu → inchangé (l'import est idempotent) ; hash différent, écriture venant de Git, dernière écriture venue d'ailleurs (`admin`, `paste`, ou aucune provenance connue : post créé à la main) et pas de forçage → conflit, rien n'est écrit ; sinon mise à jour. Une écriture venue de l'admin ou d'un collage gagne toujours : c'est un acte humain. Un même hash avec une version de rendu plus ancienne est réécrit, pour que le HTML dérivé rattrape le renderer. Le forçage est le drapeau `--force` de la commande WP-CLI ; l'endpoint `/sync` reprendra le même service avec son paramètre explicite.

---

## 6. Les trois portes d'entrée

Les trois chemins par lesquels du contenu peut entrer dans le plugin convergent tous vers le même service `ContentImportService`. Cette convergence est la raison pour laquelle le code reste compact malgré la flexibilité offerte. Une quatrième porte existe depuis le 26 octobre 2026, la commande WP-CLI `wp portfolio import` (§6.4) : elle tient lieu d'import manuel et préfigure le webhook. Les trois autres restent à construire ⏳.

### 6.1. Éditeur Markdown dans l'admin

La classe `Admin\EditorMetaBox` ajoute une métabox custom sur les écrans d'édition des articles et des projets. Cette métabox remplace fonctionnellement l'éditeur Gutenberg natif pour ces types de posts — Gutenberg est désactivé via le filtre `use_block_editor_for_post_type`. L'interface d'édition montre à Morgan un éditeur Markdown riche (basé sur EasyMDE ou CodeMirror 6) avec coloration syntaxique du Markdown lui-même, prévisualisation en parallèle, et insertion facilitée d'images depuis la Media Library WordPress standard.

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

### 6.4. Import par WP-CLI

La classe `Cli\ImportCommand` enregistre `wp portfolio import <chemin>... [--source=<git|paste|admin>] [--force] [--dry-run] [--draft]`. Chaque chemin est un fichier Markdown ou un dossier dont les `*.md` sont importés dans l'ordre des noms ; le dossier `content/` du dépôt est monté en lecture seule dans le conteneur `wpcli` sous `/srv/portfolio-content`. La commande exige `--user=<login>` pour que les posts aient un auteur. Chaque document est traité indépendamment et donne une ligne `CREATED`, `UPDATED`, `UNCHANGED`, `CONFLICT` ou `FAILED` ; la commande continue après un échec et sort en erreur à la fin s'il y a eu au moins un conflit ou un échec. La provenance enregistrée vaut `git` par défaut : les fichiers viennent du dépôt, et c'est ce qui permettra au webhook (§6.2) de prendre le relais sans conflit. `--dry-run` parse et rend chaque document sans rien écrire ; `--draft` crée les nouveaux posts en brouillon, les posts existants gardant leur statut.

---

## 7. L'API REST exposée

Le plugin expose plusieurs endpoints sous le namespace `/wp-json/portfolio/v1/`, enregistrés par la classe `Rest\RouteRegistrar` au moment du hook `rest_api_init`. ⏳ À construire. À noter que l'API REST **native** de WordPress expose déjà, dès aujourd'hui, les deux post types (`/wp-json/wp/v2/article`, `/wp-json/wp/v2/project`), leurs taxonomies et leurs metas publiques : c'est ce qui permet à Gutenberg de fonctionner, et ce que vérifie le test de fumée.

### 7.1. Endpoints publics en lecture

Les endpoints publics ne requièrent pas d'authentification. Cette décision simplifie la consommation par l'API C# et reste sans risque parce que le contenu est, par nature, destiné à être lu.

`GET /articles` retourne la liste paginée des articles, avec filtres optionnels par tag via query params. Chaque article du tableau est une représentation complète (Markdown source inclus).

`GET /articles/{slug}` retourne un article spécifique identifié par son slug, 404 si absent.

`GET /projects` et `GET /projects/{slug}` font la même chose pour les projets, avec en plus les champs de projet (`status`, `skills`, `is_fil_rouge`, période, liens).

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
  "tags": [
    { "slug": "rust", "name": "Rust" },
    { "slug": "systems", "name": "Systems" }
  ],
  "subtitle": "Une intuition visuelle du borrow checker",
  "seo_description": "Une intuition visuelle du borrow checker.",
  "reading_time": 8,
  "featured": false,
  "markdown": "---\ntitle: ...\n---\n\n# Le vrai problème..."
}
```

Pour un projet, la réponse porte en plus `status`, `skills` (liste d'objets `{ slug, code, label }`, le code venant du term meta), `is_fil_rouge`, `role`, `period_start`, `period_end`, `repo_url`, `demo_url` et `client`.

Point important : le HTML Gutenberg n'est **pas** retourné par cet endpoint. Les consommateurs externes reçoivent le Markdown brut (frontmatter inclus) et font leur propre rendu selon leurs conventions. Cette décision garantit que le format canonique traverse tout le système sans transformation intermédiaire, et que l'API C# peut appliquer ses propres règles (ancres automatiques sur les titres, enrichissements, etc.).

---

## 8. Import des images et Media Library

Les images référencées dans le Markdown par la syntaxe standard `![alt](chemin/image.png)` posent un problème particulier selon l'origine du contenu. Si le chemin est relatif (cas typique du workflow Git où les images vivent à côté du `.md` dans le repo), le plugin doit uploader les images dans la Media Library WordPress et réécrire les URLs dans le Markdown stocké et dans le HTML dérivé. Si le chemin est absolu et pointe déjà vers `/wp-content/uploads/`, le plugin ne fait rien. Si c'est une URL externe, le plugin la laisse telle quelle (choix initial — on pourra plus tard ajouter un rapatriement automatique si besoin).

La classe `Service\MediaImportService` encapsule cette logique. Elle utilise les fonctions WordPress standard `wp_insert_attachment()` et `wp_generate_attachment_metadata()` pour uploader un fichier et créer les miniatures associées. Elle maintient une table de correspondance entre chemin relatif d'origine et URL finale WordPress, pour éviter de ré-uploader la même image à chaque synchro.

Les galeries d'images de projet ne sont pas un champ dédié : elles vivent dans le corps du contenu via les blocs Gutenberg natifs `core/gallery` et `core/image` (décision du 25 avril 2026, `04-structure-wordpress.md` §5.4), et passent donc par le même import que les autres images.

---

## 9. Custom Post Types, taxonomies et champs personnalisés

Le modèle de contenu (types, taxonomies, champs, règles de sanitization) est spécifié dans [`04-structure-wordpress.md`](04-structure-wordpress.md). Cette section résume seulement ce que le plugin enregistre et où.

### 9.1. Les CPT `article` et `project`

La classe `PostTypes\PostTypeRegistrar` enregistre les deux types au hook `init` : URLs `/articles/<slug>/` et `/projets/<slug>/`, `show_in_rest` activé (indispensable à Gutenberg), support des champs personnalisés sur les deux, image de couverture (`thumbnail`) sur les projets seulement. Les slugs sont exposés en constantes `PostTypeRegistrar::ARTICLE` et `PostTypeRegistrar::PROJECT` pour que personne n'écrive `'article'` en dur ailleurs. Le choix d'un CPT `article` plutôt que du type natif `post` est justifié dans `04-structure-wordpress.md` §3.1.

### 9.2. Les taxonomies `tech_tag`, `project_status` et `skill`

La classe `Taxonomies\TaxonomyRegistrar` enregistre trois taxonomies. `tech_tag` est plate, partagée par les articles et les projets, en `/tag/<slug>/`. `project_status` est plate, réservée aux projets, à valeurs fermées (`TaxonomyRegistrar::PROJECT_STATUS_TERMS`, insérées à l'activation), en `/statut/<slug>/`. `skill` est **hiérarchique**, réservée aux projets : les parents sont les référentiels UHA 4.0 (`TaxonomyRegistrar::SKILL_REFERENTIALS`, insérés à l'activation), les enfants les compétences, dont le code officiel vit dans le term meta `_portfolio_skill_code` ; URL `/competences/<referentiel>/<competence>/`. Les compétences sont synchronisées depuis `content/skills.yaml` par la commande `wp portfolio skills sync` : `Cli\SkillsCommand` traduit les arguments, `Service\SkillTermSynchronizer` décide (créer, mettre à jour en place, signaler les orphelins sans jamais supprimer) à travers le port `SkillTermGateway`, et `Storage\WordPressSkillTermGateway` parle aux fonctions de termes WordPress. La commande est idempotente : rejouée sur une grille inchangée, elle n'écrit rien. Pourquoi une taxonomie custom plutôt que `post_tag`, et pourquoi hiérarchique : `04-structure-wordpress.md` §4, ADR-011 et ADR-014.

### 9.3. Les champs personnalisés

Trois registrars dans `Meta/` : `CommonMetaRegistrar` (quatre champs publics sur les deux types), `ProjectMetaRegistrar` (huit champs publics sur les projets, dont `_portfolio_project_is_fil_rouge`), `InternalMetaRegistrar` (cinq champs internes, verrouillés, hors REST). Toutes les clés sont préfixées `_portfolio_`, donc protégées par WordPress. Conséquence à connaître : une meta protégée est par défaut **interdite en écriture via REST et Gutenberg**, même pour un administrateur. Les metas publiques déclarent donc `MetaRegistration::authorizeEditPost` comme `auth_callback` (qui peut éditer le post peut éditer ses metas) ; toute nouvelle meta publique doit faire de même. Détail et raisons : ADR-013.

---

## 10. Dépendances Composer

Le fichier `composer.json` déclare un petit nombre de dépendances soigneusement choisies.

`league/commonmark` en version 2.x est le parser Markdown. Il est maintenu, performant, et modulaire par extensions. Les extensions activées incluront celle pour GFM (tableaux, tâches, barré, liens auto) et une extension custom locale qui plugge `scrivo/highlight.php` sur les blocs de code.

`symfony/yaml` est utilisé uniquement pour parser le frontmatter. C'est une librairie stable, maintenue par Symfony, parfaitement adaptée à ce cas d'usage précis.

`scrivo/highlight.php` fournit la coloration syntaxique côté serveur. C'est un portage PHP de highlight.js qui supporte une centaine de langages, dont tous ceux que Morgan utilisera (Rust, C#, TypeScript, PHP, SQL, JSON, etc.).

En dépendances de développement, `phpunit/phpunit` 11 pour les tests. `yoast/phpunit-polyfills` (compatibilité avec `wp-phpunit`) et `phpstan/phpstan` (analyse statique) sont prévus mais pas encore ajoutés : ils viendront avec les premières classes qui touchent la base WordPress.

L'autoload PSR-4 mappe le namespace `Voltz\PortfolioMd\` vers `src/` et, en développement, `Voltz\PortfolioMd\Tests\` vers `tests/` :

```json
"autoload": {
    "psr-4": {
        "Voltz\\PortfolioMd\\": "src/"
    }
},
"autoload-dev": {
    "psr-4": {
        "Voltz\\PortfolioMd\\Tests\\": "tests/"
    }
}
```

Avec cette configuration, une classe `Voltz\PortfolioMd\Parsing\MarkdownParser` sera automatiquement chargée depuis `src/Parsing/MarkdownParser.php` à la première utilisation. Aucun `require` manuel n'est nécessaire nulle part dans le code. Les scripts `composer test` et `composer test:unit` lancent PHPUnit.

---

## 11. Sécurité

Trois surfaces d'attaque principales sont à considérer.

La première est l'**endpoint `/sync`** authentifié par bearer token. La sécurité repose sur la confidentialité du token. Il doit être suffisamment long et aléatoire (au moins trente-deux caractères), stocké dans `wp-config.php` hors du web root, transmis uniquement via HTTPS, et renouvelable facilement par redéfinition de la constante. La comparaison du token côté serveur utilise `hash_equals()` pour se prémunir contre les timing attacks.

La deuxième est l'**éditeur admin** qui accepte du Markdown brut. Un contributeur malveillant avec les droits d'édition pourrait tenter d'injecter du HTML dangereux via Markdown (certaines implémentations Markdown autorisent le HTML inline). La configuration de `league/commonmark` désactive l'interprétation du HTML inline (`html_input: 'escape'` : le HTML écrit dans le Markdown apparaît en clair, l'erreur reste visible) et retire les liens aux schémas dangereux (`allow_unsafe_links: false`). Le HTML produit par le pipeline passe ensuite par `wp_kses()` avant stockage, avec les balises et attributs du contexte `post` de WordPress (ceux de `wp_kses_post()`, qui excluent scripts, iframes et gestionnaires d'événements) plus la case à cocher `<input type="checkbox">` des listes de tâches GFM, que ce contexte ne connaît pas. Sur le contenu réel ce passage est sans effet, ce que le test de fumée vérifie : il est là pour le jour où une autre porte d'entrée recevrait un Markdown moins sage.

La troisième est l'**import d'images**. Les fichiers uploadés passent par le flux standard WordPress qui applique ses propres vérifications (extension, type MIME, taille max). On s'appuie ici sur la robustesse de WordPress plutôt que de réimplémenter des vérifications.

Les capabilities WordPress sont respectées partout. Seuls les utilisateurs disposant de `edit_posts` peuvent déclencher l'éditeur admin et la page d'import manuel ; les metas publiques ne sont modifiables que par qui peut éditer le post concerné, et les metas internes par personne (section 9.3). L'endpoint `/sync`, étant authentifié par token uniquement, opère en mode « super-admin » — le token étant un secret partagé entre le repo Git et le serveur, sa possession équivaut à une autorisation complète sur le plugin.

---

## 12. Tests

La stratégie de tests exploite la séparation claire entre code métier et code couplé à WordPress.

Les **tests unitaires** (`tests/Unit/`) tournent sans WordPress et en quelques dizaines de millisecondes. Les registrars appellent pourtant des fonctions WordPress (`register_post_meta`, `sanitize_text_field`, …) : `tests/bootstrap.php` charge des doublures (`tests/Support/wp-stubs.php`) qui enregistrent les appels dans `WordPressSpy` ou imitent le comportement de WordPress d'assez près pour les cas testés. La suite vérifie que chaque meta est enregistrée sur les bons post types avec le bon type, la bonne visibilité REST et les bons callbacks, et que chaque sanitizer accepte et rejette exactement ce qu'il doit. `Domain/`, `Parsing/` et `Service/` y sont testés sans aucune doublure WordPress, puisqu'ils ne la connaissent pas : le parseur Markdown et le transformateur Gutenberg sur des fragments dont la sortie attendue est celle de l'éditeur, octet pour octet ; le service d'import avec un dépôt et une taxonomie en mémoire (`tests/Support/`) et une horloge figée ; la table de décision d'ADR-005 ligne par ligne ; et les quatorze fiches et deux pages de `content/`, rendues intégralement pour vérifier qu'aucune ne retombe sur le bloc HTML brut.

Les **tests d'intégration** avec un vrai WordPress passent aujourd'hui par trois scripts WP-CLI joués avec `wp eval-file` dans le conteneur `wpcli` : `tests/Integration/wp-cli/meta-smoke.php` (enregistrements effectifs, sanitization à travers `update_post_meta()`, règles de capabilities, représentation REST typée), `skills-smoke.php` (passerelle de termes de la taxonomie `skill`) et `import-smoke.php` (import complet d'un article et d'un projet synthétiques : post, dates, termes, metas, idempotence, règle de conflit, refus ; tout ce qui est créé est supprimé à la fin). Le framework officiel `wp-phpunit` viendra avec `Rest/` et `Admin/`, pour couvrir les cas où la logique s'entrelace avec les mécaniques WordPress.

La commande `composer test` lance la suite unitaire (`composer test:unit` idem). Sans PHP sur la machine hôte, les deux suites tournent dans des conteneurs ; les commandes exactes sont dans `plugin/plugin-README.md`.

---

## 13. Relation avec le thème WordPress

Le plugin est volontairement découplé du thème, mais certains points de coordination sont nécessaires.

Le plugin produit du HTML Gutenberg standard. Tout thème compatible avec les blocs Gutenberg natifs affichera correctement les articles produits par le plugin, y compris les thèmes de base comme GeneratePress, Kadence ou les thèmes officiels WordPress (Twenty Twenty-Four, etc.).

Le thème peut avoir besoin de templates dédiés pour les CPT. La convention WordPress de hiérarchie des templates permet de créer `single-project.php`, `archive-project.php`, `single-article.php`, `archive-article.php` et `taxonomy-skill.php` dans le child theme pour customiser l'affichage sans toucher au plugin.

Pour les enrichissements à la demande (Mermaid, KaTeX), le thème devra lire les flags `_portfolio_needs_mermaid` et `_portfolio_needs_katex` en début de rendu et charger conditionnellement les assets correspondants. Ces flags ne sont pas encore enregistrés (reportés, ADR-013) : ils apparaîtront avec le pipeline qui les détecte et le thème qui les lit. Cette logique vit dans le thème, pas dans le plugin, parce qu'elle concerne le rendu et non le stockage.

La documentation du child theme sera produite séparément quand la phase dix de la feuille de route sera atteinte.

---

## 14. Navigation

Pour plus de contexte ou pour approfondir un aspect spécifique :

| Document | Contenu |
|---|---|
| [`00-overview.md`](00-overview.md) | Vue d'ensemble et principes directeurs du projet |
| [`04-structure-wordpress.md`](04-structure-wordpress.md) | Modèle de contenu : types, taxonomies, champs et règles de sanitization |
| [`02-api-csharp.md`](02-api-csharp.md) | L'API C# qui consomme cette API REST exposée par le plugin |
| [`03-frontend-react.md`](03-frontend-react.md) | Le front qui consomme indirectement ce plugin via l'API C# |
| [`99-decisions.md`](99-decisions.md) | Journal des décisions architecturales |
| [`../../CLAUDE.md`](../../CLAUDE.md) | Règles de collaboration avec Claude Code |

---

*Ce document évolue avec le plugin lui-même. Chaque changement structurant du code doit être reflété ici et consigné dans le journal des décisions.*

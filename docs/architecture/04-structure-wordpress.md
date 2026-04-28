# 04 — Structure WordPress

**Fichier** : `docs/architecture/04-structure-wordpress.md`
**Rôle** : référence durable de la structure d'information et de contenu côté WordPress. Définit les types de contenu, taxonomies, champs personnalisés, et l'arborescence des pages.
**Lectorat** : Morgan à froid, contributeurs futurs, et toute personne qui veut comprendre comment WordPress est organisé pour ce projet.
**Articulation** : ce document complète `01-plugin-php.md` (qui détaille le plugin de conversion Markdown ↔ WordPress) en se concentrant sur la modélisation côté CMS.
**Temps de lecture** : environ 12 minutes
**Dernière mise à jour** : 25 avril 2026 (cadrage des champs personnalisés en fin de session)

---

## À quoi sert ce document

Ce document répond à une question précise : **comment WordPress doit-il être structuré pour servir le portfolio ?** Il définit les types de contenu, les taxonomies, les champs personnalisés, et l'arborescence des pages publiques.

Il ne traite **pas** :
- du *comment* mettre en place cette structure dans l'admin ou en code — c'est le rôle du guide `docs/guides/01-prise-en-main-wordpress.md` ;
- du *comment* le plugin convertit Markdown ↔ WordPress — c'est le rôle de `docs/architecture/01-plugin-php.md` ;
- des choix d'identité graphique — c'est le rôle de `docs/branding/03-direction-graphique.md`.

C'est un document de référence, à consulter quand on se demande « est-ce qu'un projet a un champ "client" ? » ou « comment sont reliés articles et tags techniques ? ».

---

## 1. Le principe directeur : minimaliste et cohérent

WordPress permet d'aller dans tous les sens : custom post types à foison, taxonomies imbriquées, champs personnalisés sans limite, plugins qui rajoutent des couches d'abstraction. Ce projet adopte le **principe inverse** : la structure la plus minimaliste qui soutient les besoins, et rien de plus.

Trois raisons à ce choix.

D'abord, la **cohérence avec le format pivot Markdown** (voir `docs/02-le-markdown-comme-format-pivot.md`). Tout ce qui est dans WordPress doit pouvoir s'exprimer en frontmatter YAML et en corps Markdown, sinon le pivot est cassé.

Ensuite, la **cohérence avec le principe de rigueur calibrée** (voir `docs/branding/02-positionnement-et-fil-rouge.md`). Un portfolio personnel n'a pas besoin de la richesse structurelle d'un journal en ligne ou d'un site e-commerce. Surdimensionner la structure CMS serait l'erreur exacte qu'on critique ailleurs.

Enfin, la **maintenabilité à froid**. Un projet personnel passe régulièrement par des périodes d'inactivité de plusieurs mois. Au retour, il faut pouvoir comprendre la structure en cinq minutes.

---

## 2. L'arborescence du site public

Le site public se compose de **cinq pages principales**, exposées dans la navigation, plus les pages de détail qui découlent des deux types de contenu.

```
/                      Home (porte d'entrée)
/articles              Liste des articles
/articles/:slug        Détail d'un article
/projets               Liste des projets
/projets/:slug         Détail d'un projet
/a-propos              Page À propos
/contact               Page Contact
```

**Home.** La porte d'entrée du site. Contient la phrase d'accroche, la ligne de statut courant, et les panneaux du pupitre (articles récents, projets en cours, stack, en cours d'apprentissage). Aucun contenu long ; uniquement des vitrines vers le reste. Voir `03-direction-graphique.md` section 6 et `05-textes-du-site.md` pour les textes à intégrer.

**Liste articles.** Affiche tous les articles, paginés, filtrables par tag technique. Tri par date de publication descendante par défaut.

**Détail article.** Affiche un article complet avec son contenu rendu en HTML, sa date, ses tags techniques, son temps de lecture estimé. C'est aussi la cible canonique pour le SEO : c'est cette URL qui doit être indexée par les moteurs.

**Liste projets.** Affiche tous les projets en vitrine, organisés par statut (En cours, Stable, Archivé), filtrables par stack technique.

**Détail projet.** Affiche un projet complet selon le patron en trois temps (problème → décisions techniques → ce qui a été appris) défini dans `05-textes-du-site.md` section 4.

**À propos.** Page statique. Version étendue de la phrase d'accroche, raconte le parcours électronique → automatisme → développement. Voir `05-textes-du-site.md` section 3.

**Contact.** Page statique courte et factuelle. Email, GitHub, LinkedIn, éventuellement disponibilité.

---

## 3. Les types de contenu

WordPress fournit deux types de contenu par défaut : `post` (article de blog) et `page` (page statique). Pour ce projet, on définit en plus deux **custom post types** dédiés.

> **État de l'implémentation** : les deux post types décrits ci-dessous sont enregistrés par la classe `Voltz\PortfolioMd\PostTypes\PostTypeRegistrar` (fichier `plugin/src/PostTypes/PostTypeRegistrar.php`). L'enregistrement se fait au hook WordPress `init`, accroché par la composition root `Voltz\PortfolioMd\Plugin`.

### 3.1. Pourquoi des custom post types

On pourrait techniquement utiliser les `post` natifs de WordPress pour les articles du portfolio. Le choix d'un custom post type dédié est volontaire, pour deux raisons :

D'abord, **pour séparer proprement** « article du portfolio » de tout autre usage futur des `post` standards.

Ensuite, **pour avoir un slug d'URL propre** (`/articles/...` plutôt que `/blog/...` ou `/?p=42`).

Les **projets**, en revanche, n'ont pas d'équivalent natif et nécessitent obligatoirement un custom post type, parce qu'ils ont des champs spécifiques que les articles n'ont pas.

### 3.2. Custom post type `article`

| Propriété | Valeur |
|---|---|
| Identifiant technique | `article` |
| Slug d'URL | `articles` |
| Public | Oui (visible côté front) |
| Indexable | Oui (sitemap, robots.txt) |
| Hiérarchique | Non |
| Supports natifs | `title`, `editor` (corps Markdown rendu), `excerpt`, `revisions`, `author`, `custom-fields` |
| Taxonomies attachées | `tech_tag` |
| Icône admin | `dashicons-edit-large` |
| Implémentation | `PostTypeRegistrar::registerArticle()` |

### 3.3. Custom post type `project`

| Propriété | Valeur |
|---|---|
| Identifiant technique | `project` |
| Slug d'URL | `projets` |
| Public | Oui |
| Indexable | Oui |
| Hiérarchique | Non |
| Supports natifs | `title`, `editor`, `excerpt`, `revisions`, `author`, `custom-fields`, `thumbnail` (pour image de couverture) |
| Taxonomies attachées | `tech_tag`, `project_status` |
| Icône admin | `dashicons-portfolio` |
| Implémentation | `PostTypeRegistrar::registerProject()` |

### 3.4. Pages standards

Les pages `À propos` et `Contact` utilisent le type natif `page` de WordPress. Pas de spécificité, pas de custom field.

---

## 4. Les taxonomies

Une taxonomie dans WordPress est un système de classification. Le projet définit **deux taxonomies custom**, en plus des catégories et tags natifs (qu'on n'utilise pas pour les types `article` et `project`).

> **État de l'implémentation** : les deux taxonomies décrites ci-dessous sont enregistrées par la classe `Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar` (fichier `plugin/src/Taxonomies/TaxonomyRegistrar.php`). L'enregistrement se fait au hook `init`. Les statuts fermés de `project_status` sont auto-insérés à l'activation du plugin via `TaxonomyRegistrar::ensureProjectStatusTerms()`.

### 4.1. `tech_tag` — Tag technique

| Propriété | Valeur |
|---|---|
| Identifiant technique | `tech_tag` |
| Slug d'URL | `tag` |
| Hiérarchique | Non (taxonomie plate, comme les `post_tag` natifs) |
| Attachée à | `article`, `project` |
| Public | Oui (pages d'archive `/tag/rust`, etc.) |
| Implémentation | `TaxonomyRegistrar::registerTechTag()` |
| Constante de slug | `TaxonomyRegistrar::TECH_TAG` |

Cette taxonomie regroupe les **technologies, outils et concepts techniques** qui caractérisent un contenu. Elle s'applique aux articles ET aux projets, ce qui permet à un visiteur de filtrer tous les contenus liés à Rust (articles + projets) en une seule vue.

**Liste de départ** (à enrichir au fil de l'eau) :

*Langages :* `Rust`, `C#`, `PHP`, `Python`, `C++`, `JavaScript`, `TypeScript`, `SQL`

*Frameworks et plateformes :* `.NET`, `ASP.NET Core`, `React`, `WordPress`, `Tailwind`, `Bootstrap`, `Iced`, `Dioxus`

*Concepts et pratiques :* `Architecture`, `Clean Architecture`, `Performance`, `Tests`, `DevOps`, `Docker`, `Sécurité`, `Refactoring`

*Domaines :* `Backend`, `Frontend`, `Système`, `Embarqué`, `Automatisme`, `Industrie`

Cette liste est délibérément courte. Mieux vaut quinze tags utilisés régulièrement que cinquante tags mal nommés.

### 4.2. `project_status` — Statut du projet

| Propriété | Valeur |
|---|---|
| Identifiant technique | `project_status` |
| Slug d'URL | `statut` |
| Hiérarchique | Non |
| Attachée à | `project` uniquement |
| Public | Oui |
| Implémentation | `TaxonomyRegistrar::registerProjectStatus()` |
| Constante de slug | `TaxonomyRegistrar::PROJECT_STATUS` |
| Constante des termes | `TaxonomyRegistrar::PROJECT_STATUS_TERMS` |

Cette taxonomie encode l'**état d'avancement** d'un projet. Quatre valeurs uniques, fixées dès le départ :

| Slug | Libellé | Sémantique |
|---|---|---|
| `en-cours` | En cours | Projet activement développé en ce moment |
| `stable` | Stable | Projet livré, fonctionnel, maintenu |
| `archive` | Archivé | Projet terminé, plus maintenu, conservé à titre de trace |
| `prototype` | Prototype | Projet exploratoire, non destiné à la production |

Ces valeurs sont **fermées** côté convention. Le plugin auto-insère ces quatre termes à l'activation, et il ne devrait pas y en avoir d'autres. WordPress ne fournit pas de mécanisme natif pour interdire la création via l'admin — cette discipline est documentée et sera durcie à l'étape 3 du plan d'implémentation du plugin (capabilities custom ou masquage du formulaire d'ajout).

### 4.3. Pourquoi pas les `category` et `post_tag` natifs ?

Trois raisons.

D'abord, **séparation des concerns**. Les `post_tag` natifs sont conçus pour les `post` natifs.

Ensuite, **slug d'URL propre**. La taxonomie native produit `/tag/rust` qui mélange tous les usages ; notre `tech_tag` peut être configurée avec son propre slug propre.

Enfin, **expressivité du nom**. `tech_tag` dit clairement de quoi on parle, ce que `post_tag` ne dit pas.

---

## 5. Les champs personnalisés (custom fields)

WordPress permet d'attacher à n'importe quel post des paires clé-valeur arbitraires (les *post meta*). On les utilise pour stocker tout ce qui ne rentre dans aucun champ natif.

> **État de l'implémentation** : les champs personnalisés ne sont **pas encore enregistrés** par le plugin (à la date du 25 avril 2026). Ils seront ajoutés à l'étape 3 du plan d'implémentation, en même temps que le pipeline de conversion Markdown qui sera leur producteur principal. Le plugin de conversion Markdown (voir `01-plugin-php.md` section 4) sera responsable de remplir ces champs depuis le frontmatter YAML.

### 5.1. Champs publics communs

Ces champs n'ont pas de préfixe underscore — ils sont visibles dans l'admin WordPress « Custom Fields » pour facilité de debug.

| Clé | Type | Cible | Utilité |
|---|---|---|---|
| `seo_description` | string | tous | Description meta pour SEO (compatible Yoast via `_yoast_wpseo_metadesc`) |
| `featured` | bool | tous | Mis en avant sur la home |
| `reading_time` | int | `article` | Temps de lecture estimé en minutes |
| `subtitle` | string | `article` | Sous-titre / résumé court |

### 5.2. Champs spécifiques aux projets

| Clé | Type | Utilité |
|---|---|---|
| `_portfolio_project_status` | string | Doublon textuel du statut (cohérence avec la taxo) |
| `_portfolio_project_role` | string | Rôle de Morgan dans le projet (lead, contributeur, etc.) |
| `_portfolio_project_period_start` | date ISO | Date de début |
| `_portfolio_project_period_end` | date ISO ou null | Date de fin (null si en cours) |
| `_portfolio_project_repo_url` | URL ou null | Lien vers le dépôt Git |
| `_portfolio_project_demo_url` | URL ou null | Lien vers une démo en ligne |
| `_portfolio_project_client` | string ou null | Nom du client si applicable |

Le préfixe `_portfolio_` (avec underscore initial) suit la convention WordPress qui cache automatiquement ces champs de l'interface « Custom Fields » de l'admin. Ils sont gérés exclusivement par le plugin.

### 5.3. Champs internes de gestion

Ces champs sont utilisés par le plugin pour son propre fonctionnement et ne sont jamais affichés.

| Clé | Type | Utilité |
|---|---|---|
| `_portfolio_md_source` | string | Markdown brut complet (frontmatter + corps), source canonique |
| `_portfolio_md_hash` | string | SHA-256 du Markdown source, pour détection de changement |
| `_portfolio_source_type` | enum | `admin` / `git` / `paste`, provenance de la dernière édition |
| `_portfolio_last_sync_at` | timestamp | Dernière synchro réussie |
| `_portfolio_render_version` | string | Version du renderer, pour invalider le HTML cache |

### 5.4. Champs explicitement écartés et pourquoi

Cette section consigne les champs qui ont été **considérés et rejetés** lors de la session de cadrage du 25 avril 2026. Garder cette trace évite qu'on les rajoute par réflexe plus tard sans avoir relu pourquoi ils avaient été écartés.

**`gallery` (galerie d'images de projet)** — rejeté. WordPress fournit nativement les blocs Gutenberg `core/gallery` et `core/image`, qui s'intègrent naturellement au corps de contenu et qui s'exportent proprement dans le pipeline Markdown via les blocs serializés. Stocker en plus une galerie en custom field créerait une duplication de responsabilité (deux sources pour la même information) et un risque d'incohérence. Si le besoin d'une galerie dédiée hors corps de contenu se précise un jour, on rouvrira la décision.

**`tags_inline` (duplication des tags en chaîne plate)** — rejeté. La taxonomie `tech_tag` couvre déjà ce besoin avec une structure propre. Dupliquer dans un custom field n'apporte rien, complique la maintenance, et casse le principe de source unique.

**`difficulty`, `category` ou autres axes de classement** — rejetés. La taxonomie `tech_tag` est suffisamment expressive pour couvrir les axes de classement nécessaires sans multiplier les structures.

**`status` sur les articles** — rejeté. Le statut natif de WordPress (publié, brouillon, programmé, privé) suffit pour les articles. La taxonomie `project_status` est volontairement réservée aux projets parce que sa sémantique (En cours / Stable / Archivé / Prototype) est spécifique au cycle de vie d'un projet et n'a pas de sens pour un article.

### 5.5. Critère de décision pour les évolutions futures

Pour décider si un nouveau champ mérite d'être ajouté, le critère retenu est : **un champ doit satisfaire au moins un des trois usages suivants** :

1. **Affichage côté front** — le visiteur va voir cette information ou elle va influencer le rendu (mise en avant, tri, filtrage visuel).
2. **Filtrage ou recherche programmatique** — le code va interroger WordPress pour récupérer tous les contenus qui ont telle valeur (par exemple `featured = true` pour la home).
3. **Métadonnée d'export ou de SEO** — le champ est consommé par un système qui lit WordPress de l'extérieur (balises meta, pipeline Markdown, etc.).

Avant d'ajouter un champ, vérifier d'abord qu'aucun champ natif WordPress ne couvre déjà le besoin (titre, slug, contenu, extrait, image à la une, date, statut, auteur, parent, ordre). Si un champ natif convient, on l'utilise — pas de duplication.

---

## 6. Les rôles et capacités

WordPress dispose d'un système de rôles et de capacités (`capabilities`) granulaire. Pour ce projet, on garde le système natif sans le complexifier, avec deux niveaux d'usage.

**Administrateur** (rôle natif `administrator`). Morgan, propriétaire du site. Tous les droits.

**Visiteur anonyme.** Pas de compte, accès au site public uniquement.

Pas besoin de rôle « auteur », « contributeur », « éditeur » à ce stade.

Le seul cas particulier sera l'**endpoint de synchronisation Git** (`/sync` dans le plugin). Il ne dépendra pas du système de rôles WordPress mais d'un bearer token configuré hors du repo Git. Voir `01-plugin-php.md` section 11.

---

## 7. Ce qu'on n'utilise pas et pourquoi

Pour rester aligné sur le principe minimaliste, voici ce qu'on **n'utilise pas** dans ce projet, et pourquoi.

**Pas de page builder** (Elementor, Divi, WPBakery, Beaver Builder). HTML imbuvable, dépendances permanentes, incompatibles avec le format pivot Markdown.

**Pas de plugins de SEO complexes** (Yoast en mode complet, RankMath). Setup léger via les champs custom existants.

**Pas de constructeur de formulaires** (Contact Form 7, WPForms, Gravity Forms). Un email visible et un lien `mailto:` suffisent.

**Pas de plugin de cache lourd** (W3 Total Cache, WP Rocket). Le plugin du projet gère ses propres caches au niveau des contenus.

**Pas de constructeur visuel de custom post type ou de champs** (CPT UI, ACF Pro). Les types et champs sont déclarés en code dans le plugin du projet — décision actée et appliquée le 25 avril 2026 (chemin B du guide WordPress).

---

## 8. Récapitulatif visuel

```
WordPress
├── Types de contenu
│   ├── article (custom post type) ......... PostTypeRegistrar::registerArticle
│   ├── project (custom post type) ......... PostTypeRegistrar::registerProject
│   └── page (natif, pour À propos et Contact)
│
├── Taxonomies
│   ├── tech_tag (sur article + project) ... TaxonomyRegistrar::registerTechTag
│   └── project_status (sur project) ....... TaxonomyRegistrar::registerProjectStatus
│       └── 4 termes auto-insérés .......... TaxonomyRegistrar::ensureProjectStatusTerms
│
├── Champs personnalisés (à venir étape 3)
│   ├── communs : seo_description, featured, reading_time, subtitle
│   ├── projet : status, role, period_*, repo_url, demo_url, client
│   └── internes (préfixe _portfolio_) : md_source, md_hash, source_type, …
│
├── Rôles
│   └── administrator (Morgan) + visiteur anonyme
│
└── Plugins
    ├── plugin du projet (portfolio-md, défini dans 01-plugin-php.md)
    │   ├── src/Plugin.php ................. composition root
    │   ├── src/PostTypes/PostTypeRegistrar.php
    │   └── src/Taxonomies/TaxonomyRegistrar.php
    └── plugins ciblés minimaux (à évaluer au besoin)
```

---

## 9. Journal de décisions

**2026-04-25 (premier passage)**

- Arborescence du site fixée : home, articles, projets, à propos, contact (5 pages principales).
- Custom post types fixés : `article` et `project`.
- Taxonomies fixées : `tech_tag` (commune) et `project_status` (réservée aux projets, valeurs fermées).
- Liste de tags techniques de départ posée (à enrichir au fil de l'eau).
- Principe minimaliste explicité : pas de page builder, pas de plugins SEO lourds, pas de constructeur visuel de CPT.
- Articulation avec les autres docs précisée.

**2026-04-25 (deuxième passage, après implémentation)**

- Sections 3 et 4 implémentées en code dans le plugin `portfolio-md`. Le doc d'archi pointe maintenant explicitement vers les classes `PostTypeRegistrar` et `TaxonomyRegistrar` qui réalisent la spec.
- Section 5 (champs personnalisés) **non encore implémentée** — reportée à une prochaine session, en lien avec le pipeline Markdown qui sera leur producteur principal.
- Confirmation : zéro plugin tiers utilisé pour la structure (pas de CPT UI, pas d'ACF). Tout est en code propre, versionné, conforme au principe minimaliste.

**2026-04-25 (troisième passage, cadrage des champs personnalisés)**

- Liste des champs personnalisés finalisée. Quatre champs publics communs, sept champs spécifiques aux projets, cinq champs internes de gestion. Total : seize champs.
- **Champ `gallery` explicitement écarté** au profit des blocs Gutenberg natifs (`core/gallery`, `core/image`). Raison : éviter la duplication de responsabilité avec le corps de contenu.
- Posage du **critère de décision** pour les évolutions futures (section 5.5) : un champ ne mérite d'être ajouté que s'il satisfait au moins un des trois usages identifiés (affichage front, filtrage programmatique, métadonnée d'export/SEO).
- Posage de la trace des **champs considérés et rejetés** (section 5.4) pour qu'on retrouve la raison à froid si la question revient.
- Implémentation reportée à la session suivante, dédiée, juste avant le pipeline Markdown.

---

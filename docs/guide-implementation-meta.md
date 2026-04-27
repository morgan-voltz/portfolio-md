# Guide d'implémentation — Champs personnalisés du plugin

**Statut** : note de session, pas une doc d'architecture pérenne.
**Objectif** : te donner sous la main tout ce qu'il faut pour coder les trois classes de meta sans devoir aller-retour entre cinq documents.
**À lire en priorité** : sections 1, 2, 4 (tableau de tes champs) et 5 (sanitize). Le reste est utile mais peut être consulté à la demande.

---

## 0. Contexte de la session

Le plugin a déjà ses deux briques structurantes (`PostTypeRegistrar` et `TaxonomyRegistrar`). Cette session ajoute la troisième : l'enregistrement des **post metas** (champs personnalisés) côté WordPress.

À l'issue de la session, le plugin doit :

- enregistrer auprès de WordPress chacun des 15 champs prévus, avec son type, sa sanitization, et sa visibilité REST ;
- exposer des constantes pour toutes les clés de meta, à la même façon que `TaxonomyRegistrar::TECH_TAG` ;
- s'accrocher au hook `init` depuis la composition root `Plugin.php`, comme les deux registrars existants.

Il ne fait **rien de plus**. Le remplissage de ces champs sera le rôle du pipeline de conversion Markdown qu'on construira après. À ce stade, on prépare les « cases vides » correctement typées et sécurisées.

---

## 1. Décisions arrêtées en cadrage

| Décision | Choix retenu | Raison courte |
|---|---|---|
| Convention de nommage | Préfixe `_portfolio_` sur **tous** les champs | Cohérence interne forte, signalisation claire que c'est géré par le plugin |
| Architecture | Trois classes séparées | Pas de god class, séparation par lot logique (commun / projet / interne) |
| Hook d'enregistrement | `init`, identique aux autres registrars | Cohérence avec le pattern existant |
| Composition root | Instanciation et accrochage dans `Plugin.php` | Discipline déjà appliquée pour les autres registrars |

Cette décision sur le préfixe entraîne une **mise à jour de la doc** `04-structure-wordpress.md` section 5.1 — le tableau actuel liste les communs sans underscore. On le fera après l'implémentation, pas pendant.

---

## 2. Architecture cible : trois classes

Trois classes dans un nouveau dossier `plugin/src/Meta/`. Chacune ne touche qu'à son lot de champs et n'a aucune dépendance sur les autres.

```
plugin/src/Meta/
├── CommonMetaRegistrar.php       # 4 champs communs aux deux post types
├── ProjectMetaRegistrar.php      # 6 champs spécifiques au CPT project
└── InternalMetaRegistrar.php     # 5 champs internes au plugin
```

Namespaces (PSR-4 selon `composer.json`) :

```
Voltz\PortfolioMd\Meta\CommonMetaRegistrar
Voltz\PortfolioMd\Meta\ProjectMetaRegistrar
Voltz\PortfolioMd\Meta\InternalMetaRegistrar
```

**Forme attendue de chaque classe** (généralisable depuis `TaxonomyRegistrar`) :

- des **constantes publiques** pour les clés meta, pour qu'aucun code ailleurs n'écrive le nom de la clé en dur ;
- une méthode publique `register()` accrochée au hook `init` ;
- une méthode `register()` qui fait simplement la séquence d'appels à `register_post_meta()`, un par champ ;
- les sanitize callbacks sont des **méthodes statiques privées** de la classe (pour la testabilité — elles sont pures et testables sans WordPress).

**Composition root** : dans `Plugin.php`, tu ajoutes trois instanciations et trois `add_action('init', ...)`. Une ligne par classe. Le pattern est exactement celui de `TaxonomyRegistrar` que tu as déjà.

---

## 3. L'API WordPress : `register_post_meta()`

Avant d'attaquer le code, prends cinq minutes pour comprendre la fonction WordPress que tu vas appeler 15 fois. C'est la pièce centrale.

### 3.1. Signature

```php
register_post_meta( string $post_type, string $meta_key, array $args ): bool
```

- `$post_type` : le slug du CPT auquel ce meta s'applique (`'article'`, `'project'`).
- `$meta_key` : la clé du champ (avec ton préfixe `_portfolio_`).
- `$args` : un tableau associatif de configuration. C'est là que tout se joue.

Si `$post_type` est une chaîne vide `''`, le meta s'applique à **tous les post types** (ce qui sera utile pour `CommonMetaRegistrar` qui doit enregistrer sur `article` ET `project` — deux appels, ou un appel global, on en discute en section 4.1).

### 3.2. Les clés du tableau `$args` qui nous intéressent

| Clé | Rôle | Valeur typique pour ce projet |
|---|---|---|
| `type` | Type WordPress du champ | `'string'`, `'boolean'`, `'integer'` |
| `single` | Une seule valeur ou plusieurs | `true` partout chez nous |
| `default` | Valeur par défaut si absente | `''`, `false`, `0` selon le type |
| `description` | Description pour la doc et REST | une phrase courte en anglais |
| `sanitize_callback` | Fonction qui nettoie la valeur avant stockage | callback de sanitize (voir section 5) |
| `auth_callback` | Qui peut lire/écrire ce meta via REST | dépend du lot, voir ci-dessous |
| `show_in_rest` | Exposition dans l'API REST WordPress native | `true` pour publics, `false` pour internes |

### 3.3. Snippet d'illustration (pédagogique, à ne pas copier tel quel)

Voici comment tu enregistrerais **un seul** champ — celui-là, juste pour voir la forme. Les 14 autres suivent le même moule mais avec leurs propres callbacks. Tu écriras les autres toi-même en t'inspirant de ce gabarit.

```php
register_post_meta('article', self::FEATURED, [
    'type'              => 'boolean',
    'single'            => true,
    'default'           => false,
    'description'       => 'Whether this article is featured on the home page.',
    'sanitize_callback' => [self::class, 'sanitizeBoolean'],
    'auth_callback'     => null, // null = défaut WordPress (capability edit_post)
    'show_in_rest'      => true,
]);
```

Note bien : `auth_callback => null` (ou clé absente) garde le comportement par défaut de WordPress, qui est de vérifier que l'utilisateur a `edit_post` sur ce post — c'est ce qu'on veut pour les champs publics. Pour les internes, on mettra explicitement `'__return_false'` (ou une closure qui retourne `false`) pour bloquer toute écriture via REST.

---

## 4. Tableau des champs à enregistrer

Trois lots, donc trois sous-sections. Les types et clés ci-dessous sont **définitifs après la décision de préfixe**.

### 4.1. `CommonMetaRegistrar` — champs communs aux deux CPT

Ces quatre champs s'appliquent aussi bien à `article` qu'à `project`. Tu as deux choix d'implémentation :

- **Option A** : appeler `register_post_meta` deux fois par champ (une fois pour `'article'`, une fois pour `'project'`). Plus verbeux mais explicite et grep-friendly.
- **Option B** : appeler `register_post_meta('', $key, $args)` une seule fois — la chaîne vide signifie "tous les post types". Plus court mais expose aussi le meta sur `page` et `post` natifs, ce qu'on ne veut probablement pas.

Recommandation : **Option A**. Verbeux mais correct. Tu peux extraire une petite méthode privée `registerForBothTypes(string $key, array $args)` pour ne pas dupliquer.

| Constante suggérée | Clé meta | Type | Default | `show_in_rest` |
|---|---|---|---|---|
| `SEO_DESCRIPTION` | `_portfolio_seo_description` | string | `''` | `true` |
| `FEATURED` | `_portfolio_featured` | boolean | `false` | `true` |
| `READING_TIME` | `_portfolio_reading_time` | integer | `0` | `true` |
| `SUBTITLE` | `_portfolio_subtitle` | string | `''` | `true` |

**Notes par champ** :

- `_portfolio_subtitle` : selon la spec, ce champ ne sert qu'aux articles. Si tu l'enregistres sur `project` aussi, ce ne sera pas une erreur fonctionnelle (juste un champ jamais utilisé). Tu peux le ranger soit ici (et accepter qu'il soit techniquement disponible côté projet sans qu'on s'en serve), soit dans un `ArticleMetaRegistrar` à part. Vu qu'on n'a qu'un seul champ d'article-spécifique, je recommande de le laisser ici pour ne pas créer une quatrième classe pour un seul champ. À toi de voir.
- `_portfolio_reading_time` : même remarque, semantique réservée aux articles, mais l'enregistrer côté `project` ne casse rien.

### 4.2. `ProjectMetaRegistrar` — champs spécifiques au CPT `project`

Six champs (et non sept comme dans la liste initiale — `gallery` a été écarté en faveur des blocs Gutenberg natifs, voir `99-decisions.md` du 25 avril).

| Constante suggérée | Clé meta | Type | Default | Note |
|---|---|---|---|---|
| `PROJECT_STATUS` | `_portfolio_project_status` | string | `''` | Enum fermée, mêmes valeurs que les termes de la taxo |
| `PROJECT_ROLE` | `_portfolio_project_role` | string | `''` | Texte libre |
| `PROJECT_PERIOD_START` | `_portfolio_project_period_start` | string | `''` | Date ISO 8601 (`YYYY-MM-DD`) |
| `PROJECT_PERIOD_END` | `_portfolio_project_period_end` | string | `''` | Date ISO 8601 ou chaîne vide |
| `PROJECT_REPO_URL` | `_portfolio_project_repo_url` | string | `''` | URL ou chaîne vide |
| `PROJECT_DEMO_URL` | `_portfolio_project_demo_url` | string | `''` | URL ou chaîne vide |
| `PROJECT_CLIENT` | `_portfolio_project_client` | string | `''` | Texte libre |

Tous en `show_in_rest: true` (utiles pour Gutenberg et pour le debug via l'API native).

**Remarque sur `_portfolio_project_status`** : il y a un doublon volontaire avec la taxonomie `project_status`. La taxo est la source de vérité côté UI ; le meta est un miroir textuel pour faciliter les requêtes en SQL plat sans jointure. Ton sanitize callback doit valider que la valeur est dans la liste fermée des slugs de statuts (`en-cours`, `stable`, `archive`, `prototype`).

### 4.3. `InternalMetaRegistrar` — champs internes du plugin

Cinq champs. Ils sont écrits **uniquement par le plugin lui-même** — jamais via REST, jamais via formulaire admin. Donc deux différences avec les autres :

- `auth_callback` → une closure ou callable qui retourne `false`. Empêche toute écriture externe.
- `show_in_rest` → `false`. Pas exposés dans la REST API native.

| Constante suggérée | Clé meta | Type | Note |
|---|---|---|---|
| `MD_SOURCE` | `_portfolio_md_source` | string | Markdown brut, peut faire plusieurs Ko |
| `MD_HASH` | `_portfolio_md_hash` | string | SHA-256 hexadécimal, 64 caractères |
| `SOURCE_TYPE` | `_portfolio_source_type` | string | Enum fermée : `admin`, `git`, `paste` |
| `LAST_SYNC_AT` | `_portfolio_last_sync_at` | string | Timestamp ISO 8601 UTC |
| `RENDER_VERSION` | `_portfolio_render_version` | string | Version du renderer (semver-like) |

Ces champs s'appliquent aux deux post types (`article` et `project`). Même question Option A vs B qu'en 4.1, même réponse : Option A.

---

## 5. Stratégie de sanitize et de validation

C'est la partie la plus formatrice de cette session. WordPress te confie la responsabilité de nettoyer toi-même la donnée. Une sanitize bâclée laisse passer du XSS, des injections, ou simplement de la donnée incohérente qui plante le pipeline plus tard.

**Principe directeur** : `sanitize_callback` reçoit la valeur brute, doit retourner une **valeur stockable propre**. Si la valeur est invalide, deux écoles :

1. Retourner une valeur "corrigée" (vide, default, troncature). Permissive, ne casse rien, mais peut masquer des bugs.
2. Retourner une chaîne vide / `null` et logger l'incident. Stricte, plus pédagogique pour comprendre les problèmes, mais demande un peu plus de discipline.

Je te recommande la **2** pour ce projet : tu apprends WordPress, tu veux voir les erreurs, et le pipeline Markdown qui appelle ces meta connaîtra de toute façon les contraintes (il pourra se charger de valider en amont).

### 5.1. Référence des sanitizers natifs WordPress que tu vas utiliser

| Fonction WP | Pour quoi | Comportement |
|---|---|---|
| `sanitize_text_field` | Texte court, sans HTML | Strip tags, normalise les espaces, supprime les retours à la ligne |
| `sanitize_textarea_field` | Texte long, sans HTML | Comme ci-dessus mais préserve les retours à la ligne |
| `esc_url_raw` | URL pour stockage en base | Valide et nettoie une URL ; retourne `''` si invalide |
| `absint` | Entier non négatif | Cast en int et applique `abs()` |
| `(bool)` cast PHP | Booléen | Pas besoin de fonction WordPress dédiée |

### 5.2. Mapping sanitize par champ

Voici la stratégie de sanitize champ par champ. Tu écris une méthode statique privée par champ (ou par type quand c'est répété), nommée `sanitizeXxx` pour rester cohérent.

**Communs** :

- `_portfolio_seo_description` : `sanitize_text_field` puis tronquer à 160 caractères (recommandation Google pour la meta description).
- `_portfolio_featured` : `(bool)` cast strict.
- `_portfolio_reading_time` : `absint`. Plafonner à une valeur raisonnable (ex. 240 minutes) si tu veux éviter les valeurs aberrantes.
- `_portfolio_subtitle` : `sanitize_text_field`, longueur libre mais probablement < 200 chars en pratique.

**Projet** :

- `_portfolio_project_status` : tester `in_array($value, ['en-cours', 'stable', 'archive', 'prototype'], true)`. Si pas valide, retourne `''`. Idéalement, importe la liste depuis `TaxonomyRegistrar::PROJECT_STATUS_TERMS` pour qu'il n'y ait qu'une seule source de vérité.
- `_portfolio_project_role` et `_portfolio_project_client` : `sanitize_text_field`.
- `_portfolio_project_period_start` et `_portfolio_project_period_end` : regex `/^\d{4}-\d{2}-\d{2}$/`. Si match, retour de la valeur ; sinon `''`. Tu peux aller plus loin avec `DateTime::createFromFormat('Y-m-d', $value)` qui valide aussi que le mois est ≤ 12 et le jour cohérent.
- `_portfolio_project_repo_url` et `_portfolio_project_demo_url` : `esc_url_raw`. Cette fonction retourne déjà `''` si invalide.

**Internes** :

- `_portfolio_md_source` : c'est du Markdown brut, donc pas de strip de tags. Cast string et c'est tout. Attention : `sanitize_text_field` *casserait* ton Markdown en supprimant les retours à la ligne. À ne **pas** utiliser ici.
- `_portfolio_md_hash` : regex `/^[a-f0-9]{64}$/i`. Si match retour, sinon `''`.
- `_portfolio_source_type` : `in_array` sur `['admin', 'git', 'paste']`.
- `_portfolio_last_sync_at` : regex ISO 8601 ou validation `DateTime::createFromFormat(DATE_ATOM, $value)`.
- `_portfolio_render_version` : regex semver `/^\d+\.\d+\.\d+$/` ou plus permissif si tu veux.

### 5.3. Note sur l'`auth_callback` des internes

Le moyen le plus court d'interdire l'écriture externe :

```php
'auth_callback' => '__return_false',
```

`__return_false` est une fonction native WordPress qui retourne toujours `false`. C'est l'équivalent canonique d'un "interdit" déclaratif.

---

## 6. Une question encore ouverte (à régler en cours de route)

Le mapping frontmatter du document `01-plugin-php.md` (section 4.4) liste deux champs que la spec section 5 de `04-structure-wordpress.md` ne mentionne **pas** :

- `_portfolio_needs_mermaid` (booléen)
- `_portfolio_needs_katex` (booléen)

Ce sont des flags qui indiqueraient au thème de charger les assets correspondants. Question : est-ce qu'on les implémente maintenant ou on attend ?

Mon avis : **on attend**. Tant que le pipeline Markdown ne les détecte pas et que le thème n'en consomme pas, c'est du code mort. On les ajoutera au moment où ils auront un producteur ET un consommateur. Mais c'est une vraie question à laquelle tu peux répondre autrement — dis-moi.

---

## 7. Ordre d'implémentation suggéré

L'ordre te permet de prendre confiance progressivement et de tester chaque brique avant la suivante.

1. **`CommonMetaRegistrar`** en premier. Quatre champs, types variés (string, bool, int), c'est le bon lot pour roder l'API et voir comment les types fonctionnent. Une fois enregistré, vérifie dans WP-CLI ou dans un script de test que `get_registered_meta_keys('post', 'article')` liste bien tes quatre clés.
2. **`InternalMetaRegistrar`** ensuite. Petit lot (5 champs, tous strings) qui te fait pratiquer l'`auth_callback => '__return_false'` et `show_in_rest => false`.
3. **`ProjectMetaRegistrar`** en dernier. Six champs avec des sanitize plus exigeantes (enum, dates, URLs). À ce stade tu auras les réflexes en place.

Pour chaque classe : **commit séparé**. Trois commits propres :

```
feat(plugin): register common post metas
feat(plugin): register internal post metas
feat(plugin): register project-specific post metas
```

Conformément à `CLAUDE.md` section 5.1 (Conventional Commits avec scope obligatoire).

---

## 8. Tests à prévoir

Pour chaque classe, deux types de tests :

**Tests unitaires** (rapides, sans WordPress) sur les sanitize callbacks. Comme ce sont des méthodes statiques pures, tu peux les tester sans monter une instance WP. Exemple de cas à couvrir pour `_portfolio_project_status` :

- valeur valide (`'stable'`) → retourne `'stable'`
- valeur invalide (`'finished'`) → retourne `''`
- valeur vide → retourne `''`
- valeur avec espaces (`' stable '`) → comportement à décider (trim + valid, ou rejet)

**Tests d'intégration** (lents, avec `wp-phpunit`) sur l'enregistrement effectif :

- après `register()`, `get_registered_meta_keys()` retourne bien les clés attendues
- sur un post réel, `update_post_meta()` avec une valeur invalide stocke bien `''` (sanitize a fait son boulot)

Tu n'es pas obligé de tester les 15 champs exhaustivement à ce stade. Une bonne couverture unitaire des sanitize les plus tordus (statut enum, hash, URL) et un seul test d'intégration de fumée ("ça enregistre quelque chose") suffisent pour cette session.

---

## 9. Documentation à mettre à jour après l'implémentation

Liste à te rappeler avant de fermer la PR :

1. `docs/architecture/04-structure-wordpress.md` section 5 : remplacer le bloc « État de l'implémentation » qui dit "non encore implémenté" par les pointeurs vers les trois classes. Mettre à jour le tableau 5.1 pour refléter le préfixe `_portfolio_` sur les communs (changement par rapport à l'état actuel).
2. `docs/architecture/04-structure-wordpress.md` récap section 8 : remplacer le bloc « Champs personnalisés (à venir étape 3) » par la version avec les classes implémentées.
3. `docs/architecture/01-plugin-php.md` : si la section 4.4 (mapping) reste exacte (ce que je crois), pas de changement. Si on tranche les flags `mermaid`/`katex`, à mettre à jour.
4. `docs/architecture/99-decisions.md` : nouvelle entrée datée d'aujourd'hui, qui dit :
   - "Convention de préfixe `_portfolio_` étendue aux 4 champs publics communs (changement par rapport à la spec initiale du 25 avril)."
   - "Trois classes registrars distinctes plutôt qu'une seule (principe de séparation par lot)."
   - "Champs `needs_mermaid` / `needs_katex` reportés [si tu confirmes ce report]."

Annonce-moi quand tu attaques, je relis chaque classe au fur et à mesure.

---

*Fin du guide. Bon code.*

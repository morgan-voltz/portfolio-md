# content/ — la source Markdown du portfolio

**Rôle** : ce dossier est la source canonique du contenu du site (ADR-001, ADR-002). Tout ce que WordPress affiche en est une projection : le plugin `portfolio-md` stockera chaque fichier tel quel dans `_portfolio_md_source` et en dérivera le HTML Gutenberg. Les fichiers entrent dans WordPress par la commande `wp portfolio import` (voir « Import dans WordPress ») et, depuis le 29 octobre 2026, par la porte Git : chaque push sur `main` qui touche ce dossier est envoyé au site par le workflow `.github/workflows/sync-content.yml`, une fois les trois secrets du dépôt posés (`PORTFOLIO_SYNC_URL`, `PORTFOLIO_SYNC_USER`, `PORTFOLIO_SYNC_PASSWORD` ; ADR-019). La forme des fichiers est définitive.
**Langues** : le site est trilingue, français, allemand, anglais (ADR-020), et le contenu est une arborescence par langue. Les identifiants (slugs, codes, clés de frontmatter, noms de dossiers) restent en anglais.

> **Transition (novembre 2026).** Tant que la migration du contenu n'a pas eu lieu, les fichiers sont encore à `content/<dossier>/<slug>.md` et sont lus comme du français ; pendant cette période, on omet `lang` et `translation_key`. Le validateur annonce cette disposition par la ligne « disposition : avant migration » ; il tolère `lang: "fr"` (qui change le hash du fichier, donc le réimporte) et refuse `lang` `de` ou `en`. Les portes du plugin, elles, lisent la langue dans le chemin depuis le lot 1b d'ADR-020 : la porte Git **ignore** (`skipped`) un fichier resté à cette profondeur, suppression comprise, et `wp portfolio import` le **refuse** en nommant le déplacement à faire ; un document `de` ou `en` n'est accepté que dans son dossier de langue. Dès que `content/fr/`, `content/de/` ou `content/en/` existe, la disposition par langue est la seule admise : les dossiers de type restés à la racine sont signalés « arbre mixte ». La migration est un `git mv` strict vers `content/fr/` (même contenu, même slug), poussé après le déploiement du plugin : les seize posts passent en `updated`, aucun ne va à la corbeille (ADR-020, notes d'implémentation).
**Décision associée** : contenu versionné dans le monorepo, ADR-015 du 26 octobre 2026.

## Arborescence

```
content/
├── README.md            # ce document
├── skills.yaml          # grille de compétences UHA 4.0 → taxonomie skill (ADR-014), hors langue
├── fr/                  # une arborescence par langue : fr, de, en
│   ├── projects/        # une fiche par projet → post type project
│   │   └── <slug>.md
│   └── pages/           # pages statiques → type page natif
│       ├── a-propos.md
│       └── cv.md
├── de/
│   ├── projects/
│   │   └── <slug>-de.md
│   └── pages/
│       └── lebenslauf.md
└── en/
    └── …
```

Le dossier de langue dit la langue, le dossier de type dit le type, le nom du fichier est le slug (`Domain\ContentPath`). Les articles de blog (`type: "article"`) auront leur dossier `articles/` dans chaque langue quand le premier sera écrit.

## Frontmatter d'une fiche projet

Le mapping complet frontmatter → WordPress est dans [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) §4.4 ; les règles de sanitization dans [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md) §5.6. Gabarit à copier :

```markdown
---
title: "Nom du projet"
slug: "nom-du-projet"               # en allemand « nom-du-projet-de », en anglais « nom-du-projet-en »
type: "project"
lang: "fr"                          # fr | de | en, égal au dossier de langue
translation_key: "nom-du-projet"    # slug du document français ; facultatif en français, obligatoire en de et en
date: 2026-09-06                    # date de publication ; si absente, date d'import
subtitle: "Une ligne qui dit ce que c'est"
seo_description: "160 caractères maximum, phrase complète pour les moteurs de recherche."
featured: false                     # mis en avant sur la page d'accueil
status: "en-cours"                  # en-cours | stable | archive | prototype
role: "Développeur principal, équipe de 8"
period_start: 2026-01-12            # YYYY-MM-DD
period_end: ""                      # vide tant que le projet est en cours
repo_url: "https://github.com/morgan-voltz/..."   # http(s) seulement, vide si privé
demo_url: ""
client: ""                          # commanditaire réel, vide sinon
is_fil_rouge: false                 # true pour le seul projet fil rouge 4.0.2
tags: [csharp, dotnet, wpf]         # taxonomie tech_tag, slugs libres
skills: ["4.0.1/FR-13", "4.0.2/E07"] # codes de content/skills.yaml
---
```

Les codes de compétences viennent de `skills.yaml` : pour 4.0.1, les intitulés officiels de la feuille de suivi (`FR-nn` techniques, `PM-nn` métier, `TR-nn` transverses) ; pour 4.0.2, les étapes de la consigne du fil rouge (`Enn`, `Onn`) en attendant la feuille de suivi de l'année. Une fiche ne cite que les compétences que le projet démontre concrètement.

Le plugin n'exige que `title`, `slug` et `type`, plus `status` pour un projet et `translation_key` hors du français, et il refuse `translation_key` sans `lang` ; les autres clés peuvent être omises et valent alors vide ou `false`. Le validateur, lui, exige une fiche complète, comme ci-dessus (`reading_time` excepté). `lang` est facultatif jusqu'à la migration du contenu, et vaut alors `fr`. Les booléens s'écrivent `true` ou `false` (pas `yes`), les dates `AAAA-MM-JJ`, et le parseur refuse toute clé qu'il ne connaît pas.

Pas de champ `category` : la taxonomie native n'est pas utilisée (`04-structure-wordpress.md` §4.4), les tags techniques suffisent. Les flags `needs_mermaid` / `needs_katex` viendront avec le pipeline (ADR-013).

## Corps d'une fiche projet : le patron en trois temps

Chaque fiche raconte le projet dans l'ordre où un recruteur ou un jury veut le lire :

1. **Le problème** : pour qui, quelle douleur, quelle contrainte. Deux à quatre paragraphes.
2. **Les décisions techniques** : trois à six décisions avec leur pourquoi et, quand c'est possible, la preuve dans le code (fichier, classe, chiffre). C'est la section qui distingue une fiche d'un README.
3. **Ce que j'en ai appris** : ce qui a changé dans la manière de travailler, ce qu'on referait autrement.

Puis deux blocs courts : **En chiffres** (taille, tests, commits, durée) et **Liens** (dépôt, démo, documentation). Les titres de section sont libres mais ces cinq temps sont toujours présents. Le corps ne commence pas par un `# Titre` : le titre vient du frontmatter et le thème l'affiche en tête de page ; un h1 dans le corps le ferait apparaître deux fois.

Le projet fil rouge 4.0.2 suit le même patron avec, en plus, une section **Correspondance avec les étapes du fil rouge** qui, pour chaque étape de la consigne, pointe vers l'élément du projet qui la réalise.

## Frontmatter d'une page statique

Les pages `À propos` et `CV` utilisent le type natif `page` de WordPress ; le plugin y garde la description SEO et ses champs internes, rien d'autre :

```markdown
---
title: "À propos"
slug: "a-propos"                    # un slug naturel par langue : a-propos, ueber-mich, about
type: "page"
lang: "fr"
translation_key: "a-propos"         # slug de la page française ; facultatif en français
date: 2026-10-26
seo_description: "Une phrase complète de 160 caractères au plus."
---
```

Corps en GFM, sans `# Titre` en tête (même règle que les fiches), à la première personne, sans donnée de contact : l'adresse e-mail n'apparaît que sur la page Contact. La page CV est une version web lisible, pas une copie de la mise en page du PDF ; elle renvoie aux fiches projet par des liens relatifs (`../projects/<slug>.md`), que le pipeline réécrit en liens du site au rendu (`/fr/projets/<slug>/`, préfixé de la langue du document).

## Traductions

Chaque document existe dans les trois langues à terme (ADR-020). Les règles :

- **Un fichier traduit est autosuffisant** : il porte tout son frontmatter, rien n'est copié depuis l'original à l'import.
- **La clé de traduction est le slug du document français.** `momocad-de` et `momocad-en` portent `translation_key: "momocad"` ; `lebenslauf` et `resume` portent `cv`. Un groupe de traductions est identifié par son type et sa clé : un projet et une page de même clé ne forment pas un groupe. Une traduction dont l'original français n'existe pas est une orpheline, refusée.
- **Les slugs disent la langue pour les fiches et les articles** : suffixe `-de` ou `-en`, et un slug français ne finit jamais par l'un des deux. Les pages ont un slug naturel par langue (`cv`, `lebenslauf`, `resume` ; `a-propos`, `ueber-mich`, `about`), jamais le même dans deux langues : WordPress suffixerait le second en `-2`.
- **Les liens relatifs** gardent la forme `../projects/<slug>.md`, et l'auteur écrit la vraie cible : un CV allemand renvoie à `../projects/momocad-de.md`. Le validateur vérifie que la cible existe, y compris sous la forme `../../<lang>/projects/<slug>.md`. Le rendu réécrit les deux formes en liens du site : `../projects/momocad.md` dans un document français donne `/fr/projets/momocad/` (la langue du document), `../../de/projects/momocad-de.md` donne `/de/projets/momocad-de/` (la langue nommée par le lien).
- **`seo_description` se réécrit par langue**, 160 caractères au plus ; l'allemand est plus long, on ne traduit pas mot à mot.
- **Git est le seul écrivain des traductions** : une traduction ne se modifie pas depuis l'admin (la boîte « Document Markdown » passe en lecture seule pour elles au lot 2). Dès maintenant, **un post garde sa langue** : un document dont la `lang` diffère de celle du post qui porte son slug est refusé, par toutes les portes.
- Les pages d'accueil et de contact sont créées à la main dans WordPress, sans fichier : elles sont hors du périmètre du validateur.

Les cinq sections d'une fiche se reconnaissent par un mot entier de leur titre, dans la langue du document (plus la section du fil rouge quand `is_fil_rouge` vaut `true`) :

| Langue | Problème | Décisions | Appris | Chiffres | Liens | Fil rouge |
|---|---|---|---|---|---|---|
| `fr` | problème | décisions | appris | chiffres | liens | étapes du fil rouge |
| `de` | Problem | Entscheidungen | gelernt | Zahlen | Links | Etappen des roten Fadens |
| `en` | problem | decisions | learned | figures | links | storyline steps |

Les articles n'ont pas de section obligatoire.

## Import dans WordPress

La grille de compétences se synchronise avec la taxonomie `skill` par WP-CLI (le dossier `content/` est monté en lecture seule dans le conteneur `wpcli`, sous `/srv/portfolio-content`) :

```bash
podman-compose --profile tools run --rm wpcli wp portfolio skills sync /srv/portfolio-content/skills.yaml --dry-run
podman-compose --profile tools run --rm wpcli wp portfolio skills sync /srv/portfolio-content/skills.yaml
```

La commande est idempotente ; les termes présents dans WordPress mais absents de la grille sont signalés, jamais supprimés.

Les fiches projet (et, le jour venu, les articles) s'importent ensuite avec `wp portfolio import`, qui prend des fichiers ou des dossiers de documents (`<lang>/projects`, `<lang>/pages` : le dossier de langue dit la langue, le dossier de type dit le type) et exige `--user` pour donner un auteur aux posts :

```bash
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/fr/projects --dry-run --user=<login admin>
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/fr/projects --user=<login admin>
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/fr/pages /srv/portfolio-content/de/pages --user=<login admin>
```

Chaque fiche donne un post publié (`--draft` pour un brouillon), avec son Markdown verbatim dans `_portfolio_md_source` et son HTML Gutenberg dans `post_content`. Un second passage sur un fichier inchangé ne réécrit rien (`UNCHANGED`). Un fichier modifié met à jour le même post, sauf si le post a été modifié entre-temps depuis l'admin : l'import signale alors un `CONFLICT` et ne touche à rien tant qu'on ne relance pas avec `--force` (règle d'ADR-005). Les codes de compétences doivent exister dans la taxonomie, donc dans `skills.yaml` synchronisé ; un code inconnu fait échouer la fiche. Six garde-fous protègent les posts existants : le nom du fichier doit être le slug (`<slug>.md`), si bien qu'une fiche copiée d'une autre sans changer son `slug` est refusée au lieu d'écraser son modèle ; le dossier du fichier doit être celui de son `type` (une fiche `type: project` posée dans `fr/pages/` est refusée : « its file is in the "pages/" folder, which holds documents of type "page" » ; déplacer le fichier, ou corriger la ligne `type`) ; le dossier au-dessus doit être celui de sa `lang` (un document `lang: "de"` posé dans `fr/projects/` est refusé : « sits in the "fr/" language folder » ; un fichier encore à `content/<dossier>/<slug>.md` est refusé avec le déplacement à faire) ; un post garde sa langue (« a post keeps its language » : pour changer la langue d'un post, on supprime son fichier et on en écrit un autre **sous un autre slug**, un slug dont le post est à la corbeille restant bloqué ; jamais par une réécriture) ; un post mis à la corbeille dans l'admin bloque l'import de son fichier tant qu'il n'est pas restauré ou supprimé définitivement, pour ne jamais créer un second post à côté ; une `date` dans le futur est refusée pour un post publié (WordPress le programmerait au lieu de l'afficher). Les mêmes règles valent pour la porte Git, qui répond alors `failed` et fait échouer le job ; seul le fichier resté à l'ancienne profondeur est `skipped` chez elle, pas `failed`. À chaque écriture, et même quand le document est inchangé, le plugin dit la langue du post à sa passerelle de langue (`Service\LanguageGateway` : adaptateur nul jusqu'au lot 2, Polylang ensuite) et relie entre elles les traductions d'une même clé. Les pages (`type: page`) vont dans le type natif `page`, sans terme (`04-structure-wordpress.md` §3.4) ; une page importée s'édite ensuite comme une fiche, par sa boîte « Document Markdown », tandis qu'une page créée à la main dans WordPress garde l'éditeur de blocs.

Une fiche se modifie aussi depuis l'admin WordPress, dans la boîte « Document Markdown » de son écran d'édition (éditeur avec aperçu rendu par le même pipeline), ou se colle dans « Portfolio → Importer » : c'est le même document, et une fiche modifiée là est protégée du prochain import Git par la règle de conflit ci-dessus. Pour la ramener dans le dépôt, copier le document de la boîte dans son fichier : le hash redevient le même et l'import suivant ne signale rien.

## Règles

- Une fiche décrit le projet, pas le dépôt : pas de données client, pas de secrets, pas de noms de personnes tierces sans accord. Les projets sous dépôt privé (GitLab UHA 4.0, Kovelt) gardent `repo_url` vide et le disent dans **Liens**.
- Le Markdown est du GFM strict (ADR-002) : tableaux, listes de tâches, barré, liens automatiques. Pas de HTML inline : écrit malgré tout, il apparaîtrait en clair sur le site, le pipeline l'échappe. Une liste de tâches est conservée telle quelle, mais stockée en bloc HTML brut plutôt qu'en liste Gutenberg.
- Les images vivent à côté du fichier qui les utilise (`<lang>/projects/<slug>/`) et sont référencées en chemin relatif ; le pipeline les importera dans la médiathèque (`01-plugin-php.md` §8).
- Les antislashs sont permis partout (`\*`, `C:\chemin`) : le plugin les préserve (`MetaWriter`, ADR-013).

## Validation

Avant de proposer une fiche, passer tout le dossier au validateur (sans PHP sur la machine, via un conteneur) :

```bash
podman run --rm -v "$PWD:/repo:z" -w /repo docker.io/library/php:8.3-cli \
  php plugin/tools/validate-content.php content/skills.yaml content
```

Il lit ses listes dans le plugin lui-même (`Validation\ContentValidator` : clés autorisées, types, langues, statuts, forme des slugs, plafond SEO) et vérifie, fichier par fichier puis sur l'arbre entier :

1. les clés du frontmatter et leurs valeurs, dont les codes de compétences de `skills.yaml` ;
2. que le nom du fichier est le slug ;
3. que `lang` est la langue du dossier, et `type` le type du dossier ;
4. le suffixe `-de` ou `-en` des fiches et des articles, et son absence en français ;
5. `translation_key` : présente hors du français, égale au slug en français ;
6. `seo_description` de 160 caractères au plus ;
7. les sections de la langue du document, l'absence de HTML et de h1 dans le corps, de données de contact sur une page ;
8. qu'aucune traduction n'est orpheline et qu'une clé n'est portée qu'une fois par langue ;
9. qu'un slug n'est pas repris dans une autre langue du même type ;
10. que chaque lien de la forme `../<dossier>/<slug>.md` ou `../../<lang>/<dossier>/<slug>.md` pointe vers un fichier de `content/` qui existe, et qu'aucun lien vers un `.md` n'a une autre forme : le rendu ne réécrit que ces deux-là.

La première ligne du rapport dit la disposition reconnue, « avant migration » ou « par langue » ; le code de retour vaut 1 dès qu'un fichier ou l'arbre échoue.

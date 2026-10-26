# content/ — la source Markdown du portfolio

**Rôle** : ce dossier est la source canonique du contenu du site (ADR-001, ADR-002). Tout ce que WordPress affiche en est une projection : le plugin `portfolio-md` stockera chaque fichier tel quel dans `_portfolio_md_source` et en dérivera le HTML Gutenberg. Jusqu'à l'arrivée du pipeline (phase 2) et du webhook Git (phase 9), les fichiers sont importés à la main ; leur forme, elle, est déjà définitive.
**Langue** : le contenu est en français, c'est la langue du site. Les identifiants (slugs, codes, clés de frontmatter) restent en anglais.
**Décision associée** : contenu versionné dans le monorepo, décision du 26 octobre 2026 (à consigner dans `99-decisions.md`).

## Arborescence

```
content/
├── README.md            # ce document
├── skills.yaml          # grille de compétences UHA 4.0 → taxonomie skill (ADR-014)
├── projects/            # une fiche par projet → post type project
│   └── <slug>.md
└── pages/               # pages statiques → type page natif
    ├── a-propos.md
    └── cv.md
```

Les articles de blog (`type: "article"`) auront leur dossier `articles/` quand le premier sera écrit.

## Frontmatter d'une fiche projet

Le mapping complet frontmatter → WordPress est dans [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) §4.4 ; les règles de sanitization dans [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md) §5.6. Gabarit à copier :

```markdown
---
title: "Nom du projet"
slug: "nom-du-projet"
type: "project"
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

Pas de champ `category` : la taxonomie native n'est pas utilisée (`04-structure-wordpress.md` §4.4), les tags techniques suffisent. Les flags `needs_mermaid` / `needs_katex` viendront avec le pipeline (ADR-013).

## Corps d'une fiche projet : le patron en trois temps

Chaque fiche raconte le projet dans l'ordre où un recruteur ou un jury veut le lire :

1. **Le problème** : pour qui, quelle douleur, quelle contrainte. Deux à quatre paragraphes.
2. **Les décisions techniques** : trois à six décisions avec leur pourquoi et, quand c'est possible, la preuve dans le code (fichier, classe, chiffre). C'est la section qui distingue une fiche d'un README.
3. **Ce que j'en ai appris** : ce qui a changé dans la manière de travailler, ce qu'on referait autrement.

Puis deux blocs courts : **En chiffres** (taille, tests, commits, durée) et **Liens** (dépôt, démo, documentation). Les titres de section sont libres mais ces cinq temps sont toujours présents.

Le projet fil rouge 4.0.2 suit le même patron avec, en plus, une section **Correspondance avec les étapes du fil rouge** qui, pour chaque étape de la consigne, pointe vers l'élément du projet qui la réalise.

## Frontmatter d'une page statique

Les pages `À propos` et `CV` utilisent le type natif `page` de WordPress, sans champ personnalisé :

```markdown
---
title: "À propos"
slug: "a-propos"
type: "page"
date: 2026-10-26
seo_description: "Une phrase complète de 160 caractères au plus."
---
```

Corps en GFM, à la première personne, sans donnée de contact : l'adresse e-mail n'apparaît que sur la page Contact. La page CV est une version web lisible, pas une copie de la mise en page du PDF ; elle renvoie aux fiches projet par des liens relatifs (`../projects/<slug>.md`), que le pipeline réécrira en liens du site.

## Règles

- Une fiche décrit le projet, pas le dépôt : pas de données client, pas de secrets, pas de noms de personnes tierces sans accord. Les projets sous dépôt privé (GitLab UHA 4.0, Kovelt) gardent `repo_url` vide et le disent dans **Liens**.
- Le Markdown est du GFM strict (ADR-002) : tableaux, listes de tâches, barré, liens automatiques. Pas de HTML inline.
- Les images vivent à côté du fichier qui les utilise (`projects/<slug>/`) et sont référencées en chemin relatif ; le pipeline les importera dans la médiathèque (`01-plugin-php.md` §8).
- Les antislashs sont permis partout (`\*`, `C:\chemin`) : le plugin les préserve (`MetaWriter`, ADR-013).

## Validation

Avant de proposer une fiche, la passer au validateur, qui vérifie le frontmatter, les codes de compétences, les sections obligatoires et l'absence de HTML ou de données de contact (sans PHP sur la machine, via un conteneur) :

```bash
podman run --rm -v "$PWD:/repo:z" -w /repo docker.io/library/php:8.3-cli \
  php plugin/tools/validate-content.php content/skills.yaml content/projects/*.md content/pages/*.md
```

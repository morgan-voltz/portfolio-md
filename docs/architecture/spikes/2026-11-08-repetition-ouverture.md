# Répétition de l'ouverture des langues — constats du lot 5e d'ADR-022

**Fichier** : `docs/architecture/spikes/2026-11-08-repetition-ouverture.md`
**Rôle** : consigner ce qu'un vrai WordPress a répondu quand les langues ont été ouvertes puis refermées avec une fiche traduite dans les trois langues, une fois tout le code des lots 5a à 5d en place
**Date** : 8 novembre 2026
**Reproduction** : `plugin/tools/opening-rehearsal.sh http://localhost:8000 <login admin>` (fixtures `plugin/tests/Integration/wp-cli/rehearsal-fixtures.php`, importées puis supprimées par le script)

---

## 1. Ce que la répétition joue

La stack locale configurée par `site-setup.php` (accueil statique français, menu français, taxonomies `noindex`), les quatorze fiches françaises et quatre pages, plus trois fixtures importées par le vrai pipeline et liées par la passerelle Polylang : une fiche française `zz-rehearsal-project`, sa traduction allemande `zz-rehearsal-project-de` et son anglaise `zz-rehearsal-project-en`, chacune avec sous-titre et description. Quatre états : tout fermé, allemand ouvert (de force : la grille des fixtures n'est pas verte), allemand et anglais ouverts, tout refermé. Les langues sont fermées, les fixtures supprimées et l'option remise à la fin, quoi qu'il arrive.

## 2. Constats

| Où | Fermé | Allemand ouvert | Allemand et anglais ouverts | Refermé |
|---|---|---|---|---|
| `/` avec `Accept-Language: de` | 302 `/fr/` | 302 `/de/` | 302 `/de/` | 302 `/fr/` |
| `/` avec `Accept-Language: en` | 302 `/fr/` | 302 `/fr/` | 302 `/en/` | 302 `/fr/` |
| `/` avec le cookie `pll_language=de` | 302 `/fr/` | 302 `/de/` | 302 `/de/` | 302 `/fr/` |
| Fiche française du groupe | 200, canonique, **aucun hreflang** (une seule URL annonçable), description | hreflang `fr`, `de`, `x-default` → fr | hreflang `fr`, `de`, `en`, `x-default` → fr | comme fermé |
| Fiche allemande | 200, `noindex, follow`, pas de canonique, aucun hreflang | 200 indexable, canonique propre, hreflang `fr`, `de`, `x-default` → fr | idem avec `en` | 200 `noindex`, rien |
| Fiche anglaise | 200, `noindex, follow`, pas de canonique, aucun hreflang | 200 `noindex`, pas de canonique, **mais les hreflang `fr`, `de`, `x-default` de son groupe** | 200 indexable, canonique, hreflang complets | 200 `noindex`, rien |
| Fiche sans traduction (`/fr/projets/momocad/`) | aucun hreflang | aucun hreflang | aucun hreflang | aucun hreflang |
| `/tag/csharp/` | 200 `noindex, follow`, sans canonique | idem | idem | idem |
| `/de/tag/csharp/` | 404 | 404 | 404 | 404 |
| `/fr/` | canonique `/fr/`, aucun hreflang, 4 `<article>` (la page et trois cartes), pas de sélecteur | hreflang `fr`, `de`, `x-default` → `/` ; sélecteur `[fr] [de]` | hreflang `fr`, `de`, `en`, `x-default` → `/` ; sélecteur `[fr] [de] [en]` | comme fermé |
| `/de/projets/` | 200 `noindex`, 15 cartes dont 14 badges FR | indexable, canonique, hreflang `fr`, `de`, `x-default` → `/fr/projets/`, 15 cartes dont 14 badges | idem avec `en` | `noindex`, 15 cartes dont 14 badges |
| `/en/projets/` | 200 `noindex`, 15 cartes dont 14 badges | 200 `noindex`, pas de canonique, hreflang `fr`, `de` de l'archive (langue fermée) | indexable, canonique, hreflang complets | `noindex` |
| `project-sitemap.xml`, URL `/de/` | aucune | la fiche allemande et `/de/projets/` | idem | aucune |
| `project-sitemap.xml`, URL `/en/` | aucune | aucune | la fiche anglaise et `/en/projets/` | aucune |
| Fin | | | | fixtures supprimées pour de bon, option `["fr"]` remise |

## 3. Ce qu'il faut en retenir

1. **Tout ce qu'ADR-022 décide se voit à la requête suivante**, dans les deux sens : détection, cookie, hreflang, canonique, robots, sitemap, sélecteur, sans flush ni attente, et refermer ramène exactement l'état de départ.
2. **Une page d'une langue fermée porte les hreflang de son groupe dès que deux langues du groupe sont ouvertes** (ici la fiche anglaise fermée annonce `fr` et `de`). C'est Polylang qui imprime les hreflang d'un groupe sur chacune de ses pages ; la page fermée reste `noindex`, sans canonique et absente des sitemaps, et aucune page ouverte ne l'annonce : rien d'indexable ne mène à elle. Sans conséquence, à connaître pour ne pas y voir une régression.
3. **La détection du navigateur n'envoie vers une langue que si elle a au moins un post** (règle native `hide_if_empty` de Polylang) : en état 1, `Accept-Language: en` reste sur `/fr/` tant que l'anglais est fermé, et passe à `/en/` à l'ouverture parce que la fixture anglaise existe. Sur le site réel, l'anglais n'ira vers `/en/` qu'avec du contenu anglais, ce que la grille d'ouverture impose de toute façon.
4. **L'archive d'une langue ouverte qui a une fiche propre est indexable et dans le sitemap**, avec ses quatorze cartes françaises badgées à côté de la sienne : c'est le choix d'ADR-022 point 8, honnête par le badge, et le `noindex` ne vaut que pour l'archive sans fiche propre (lot 5c).

Rien n'a invalidé la décision ; les lots 5a à 5d sont recettés ensemble.

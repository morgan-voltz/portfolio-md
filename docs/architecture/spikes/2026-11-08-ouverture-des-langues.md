# Sonde « ouverture des langues » — constats du lot 5a d'ADR-022

**Fichier** : `docs/architecture/spikes/2026-11-08-ouverture-des-langues.md`
**Rôle** : consigner ce qu'un vrai WordPress a répondu aux mécanismes dont ADR-022 dépend (accueil par langue, langue fermée, sitemap, sélecteur, menus, description Yoast) avant d'écrire le code des lots 5b à 5d
**Date** : 8 novembre 2026
**Reproduction** : `plugin/tests/Integration/wp-cli/opening-spike.php` (état et nettoyage) puis `plugin/tools/opening-spike.sh` (observations HTTP), mode d'emploi en tête de chaque script ; tout ce que la sonde pose est annulé par le mode `cleanup`

---

## 1. Pourquoi une sonde

ADR-022 a été écrite sur les sources installées de Polylang 3.9.1, Yoast SEO 28.8 et GeneratePress 3.6.1, et sur des sondes partielles qui n'avaient pas exécuté deux cas : l'accueil d'une langue servi à `/<lang>/` par le réglage `redirect_lang`, et le retrait du sitemap des blocs d'archives que Polylang y ajoute pour les autres langues. L'ADR laissait aussi au lot 5a la vérification du filtre `pll_translation_url` renvoyant une chaîne vide pour la **langue courante** quand elle est fermée, du sélecteur rendu en PHP à partir des éléments bruts de `pll_the_languages()`, des deux filtres de préférence (navigateur et cookie), des menus par langue écrits par WP-CLI sous la clé du thème enfant, et de la description Yoast écrite par PHP. La sonde joue l'ensemble des filtres des lots 5b et 5c dans une extension must-use temporaire, avec `de` et `en` fermées, puis ouvre `de` pour observer le basculement.

## 2. Environnement

Stack locale du dépôt, thème enfant `portfolio-md-child` actif (lot 5a), `site-setup.php` passé (option `portfolio_md_published_languages = [fr]`, `noindex-tax-*` vrai pour les trois taxonomies), `force_lang = 1`, `browser = true`, 14 fiches françaises. La sonde ajoute : une page d'accueil française `zz-spike-accueil` liée à sa traduction allemande `zz-spike-startseite`, posée en page d'accueil statique ; `redirect_lang = true` ; une fiche française `zz-spike-projekt` et son jumeau allemand `zz-spike-projekt-de` (langue et groupe Polylang seulement, sans document : le catalogue REST du plugin les ignore, ce qui est voulu) ; une méta `_yoast_wpseo_metadesc` écrite par `update_post_meta()` sur la fiche française ; deux menus, français et allemand, liés à l'emplacement `primary` par le `theme_mod` et par l'option `nav_menus` de Polylang sous la clé `portfolio-md-child` ; l'extension must-use `zz-opening-spike.php` (préférences navigateur et cookie, URL de traduction vidée pour une langue fermée, x-default, `wpseo_robots`, `wpseo_sitemap_entry`, `wpseo_sitemap_<type>_content` à la priorité 20, sélecteur sur `generate_menu_bar_items`), pilotée par l'option `zz_spike_closed`.

## 3. Constats

| Hypothèse | Observé | Conséquence |
|---|---|---|
| `redirect_lang = true` sert l'accueil d'une langue à `/<lang>/` | `/fr/` et `/de/` répondent 200 avec le corps `home page` de la page d'accueil de la langue ; `/fr/zz-spike-accueil/` répond **301** vers `/fr/` et `/de/zz-spike-startseite/` vers `/de/` ; `/` répond **302** vers `/fr/` ; le canonique de `/fr/` est `/fr/` ; `/en/`, sans page d'accueil liée, montre la liste d'articles (corps `blog`) | **Point 11 confirmé** : une seule URL par accueil, le permalien de la page redirige ; une langue sans accueil traduit reste une liste d'articles, que le point 4 met en `noindex` tant qu'elle est fermée |
| Une langue fermée n'est pas annoncée mais reste servie | `de` fermée : `/de/`, `/de/projets/zz-spike-projekt-de/` et `/de/projets/` répondent **200** avec `robots: noindex, follow` ; aucun hreflang nulle part (la contrepartie française n'a plus qu'une URL, Polylang n'émet rien) ; Yoast n'émet **pas de canonique** sur une page `noindex` | **Point 3 confirmé**, y compris `pll_translation_url` renvoyant `''` pour la langue **courante** : aucune boucle de redirection, aucun effet sur le rendu ; l'absence de canonique sur une page `noindex` est le comportement normal de Yoast |
| Les deux filtres de préférence empêchent `/` de rebondir vers une langue fermée | `de` fermée, avec du contenu allemand : `Accept-Language: de` → `/fr/`, cookie `pll_language=de` → `/fr/`, cookie et en-tête ensemble → `/fr/` ; `de` ouverte : les trois → `/de/` ; `en` fermée et vide → `/fr/` | **Point 3 confirmé** : `pll_languages_for_browser_preferences` seul aurait laissé passer le cookie |
| Les entrées et les blocs d'archives d'une langue fermée sortent du sitemap | `de` fermée : `project-sitemap.xml` sans aucune URL `/de/` ni `/en/`, une seule archive `/fr/projets/` ; `page-sitemap.xml` sans `/de/`, accueil `/fr/` seul ; `de` ouverte (cache vidé) : `/de/projets/zz-spike-projekt-de/`, `/de/projets/` et `/de/` reviennent | **Point 4 tranché** : `wpseo_sitemap_entry` retire les entrées, et le filtre `wpseo_sitemap_<type>_content` à la priorité 20 retire les blocs `<url>` que Polylang concatène à la priorité 10 ; le drapeau `active` n'a pas à être écrit |
| Les hreflang redeviennent réciproques à l'ouverture, avec x-default | `de` ouverte : `/fr/` et `/de/` portent `fr`, `de` et `x-default → /` (natif sur l'accueil) ; la fiche française et son jumeau portent `fr`, `de` et `x-default → /fr/projets/zz-spike-projekt/` (ajouté par le filtre) ; `/de/projets/` porte `fr`, `de`, `x-default → /fr/projets/` ; `/fr/a-propos/`, sans traduction, n'émet rien | **Point 3 confirmé** : x-default hors accueil pointe la version française ; une page sans traduction n'annonce rien |
| Le sélecteur rendu en PHP suit l'option, sans avertissement | `pll_the_languages(['raw' => 1, 'hide_if_empty' => 0, 'hide_if_no_translation' => 0, 'hide_current' => 0, 'show_wrapper' => 0])` renvoie les trois langues, `de` et `en` comprises malgré leurs zéro ou un post ; filtré par la liste fermée, le sélecteur montre `fr` seule puis `fr` et `de` ; sur `/fr/a-propos/` le lien `de` va à `/de/` (classe `no-translation`), sur la fiche il va au jumeau ; aucun `_doing_it_wrong` ni avis dans la page ; le crochet `generate_menu_bar_items` est appelé **deux fois** par GeneratePress (navigation principale et mobile) | **Point 5 confirmé** ; le thème devra tolérer le double appel (deux sélecteurs identiques dans le HTML, ou un garde) |
| Un menu par langue par WP-CLI, sous la clé du thème enfant | `theme_mod nav_menu_locations = [primary => menu fr]` et `nav_menus[portfolio-md-child][primary] = [fr => …, de => …]` : `/fr/` affiche le menu français, `/de/` le menu allemand, `/en/` aucun menu (pas de liste de pages de repli) | **Point 12 confirmé** : le script de configuration écrit les deux, sous `get_option('stylesheet')` |
| Une description Yoast écrite par PHP est servie | `update_post_meta($id, '_yoast_wpseo_metadesc', …)` : la fiche sort `<meta name="description" …>` avec le texte, ouverte comme fermée | **Point 13 confirmé** (indexables reconstruits à la volée en local ; la persistance de production reste à voir au premier import, `wp yoast index --reindex` au besoin) |
| Tout est annulable | `cleanup` supprime les quatre posts, les deux menus, l'option, l'extension must-use, et remet `show_on_front`, `page_on_front`, `redirect_lang`, le `theme_mod` et `nav_menus` à leur valeur d'avant ; `/` redirige à nouveau vers la liste d'articles de `/fr/` | La sonde est rejouable sur tout environnement local |

## 4. Ce que la sonde change dans ADR-022

1. **Point 4** : le crochet des blocs d'archives est désigné, `wpseo_sitemap_<type>_content` à la priorité 20 ; le repli par le drapeau `active` est retiré.
2. **Point 11** : `redirect_lang = true` est confirmé ; le repli « accueil au permalien et `/de/` en `noindex` » n'a plus lieu d'être.
3. **Point 5** : le sélecteur est rendu deux fois par GeneratePress ; le thème le gardera idempotent (un garde statique) ou assumera les deux.
4. Yoast n'émet pas de canonique sur une page `noindex` : à connaître pour les contrôles d'acceptation d'une langue fermée (absence attendue, pas une régression).

Rien n'a invalidé la décision.

## 5. Reste à vérifier plus tard

- La persistance des indexables de Yoast en production (`WP_ENVIRONMENT_TYPE` de production) : test de fumée après le premier import, `wp yoast index --reindex` au besoin (point 13 et Conséquences d'ADR-022).
- Le gabarit `archive-project.php` qui ignore la boucle principale (lot 5c) : statut, canonique et classes de corps de `/de/projets/` avec la liste à repli.
- La commande `wp portfolio languages` et la grille d'ouverture (lot 5b) sur des pages réelles (`accueil`, `contact` et leurs traductions, lot 6).
- WP-CLI en SSH chez o2switch (phase 11), avant la production.

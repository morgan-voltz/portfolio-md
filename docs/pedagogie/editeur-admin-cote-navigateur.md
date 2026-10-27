# L'admin côté navigateur : éditeur, aperçu rendu par le serveur et route REST

**Public visé** : développeur junior qui veut comprendre comment la boîte « Document Markdown » de l'admin WordPress a reçu un éditeur et un aperçu, et pourquoi chaque pièce est là où elle est.

**Prérequis** : avoir lu [06 — WordPress sous le capot](06-wordpress-sous-le-capot.md) (hooks, actions, filtres) et connaître les bases de JavaScript dans le navigateur (DOM, promesses, modules ES).

**Statut** : guide vivant, écrit le 27 octobre 2026 à partir du lot qui a livré l'éditeur (ADR-016). La référence technique reste [`01-plugin-php.md`](../architecture/01-plugin-php.md) §6.1 et §7.4 ; ce guide explique le *pourquoi* et les pièges rencontrés.

---

## 1. Le problème : voir ce que le site affichera, sans le publier

Depuis le 26 octobre 2026, l'écran d'édition d'un article ou d'un projet n'a plus d'éditeur de blocs : il a une boîte avec le document Markdown entier, frontmatter compris. C'est voulu, le document est la seule source du titre, du slug, de la date, des termes et des champs. Mais un `<textarea>` nu a deux défauts. Tu n'y vois rien (pas de coloration, un frontmatter YAML qui se confond avec le corps), et tu ne sais ce que le serveur pense du document qu'après avoir cliqué sur « Mettre à jour » : une faute dans le frontmatter n'apparaît qu'au refus.

Il fallait donc deux choses : un éditeur qui comprenne ce qu'il affiche, et un aperçu. Pour l'aperçu, deux chemins existaient.

Le premier est de rendre le Markdown **dans le navigateur**, avec une bibliothèque JavaScript. C'est ce que font la plupart des éditeurs Markdown « tout-en-un » (EasyMDE, par exemple, utilise `marked`). C'est rapide et ça marche hors ligne. Mais le site, lui, ne rend pas le Markdown avec `marked` : il le rend avec `league/commonmark` et ses extensions GFM, colore les blocs de code côté serveur, transforme le HTML en blocs Gutenberg et passe le tout par `wp_kses()`. Deux moteurs de rendu, ce sont deux vérités, et l'aperçu mentirait sur les détails (tableaux, listes de tâches, coloration, caractères échappés).

Le second est de demander **au serveur** de rendre le document avec le vrai pipeline, et d'afficher le résultat. C'est plus lent (une requête par pause de frappe) et ça demande une route, de l'authentification et une isolation côté navigateur. Mais l'aperçu est alors ce que le site affichera, et c'est ce qui a été retenu ([ADR-016](../architecture/99-decisions.md#adr-016--éditeur-codemirror-6-avec-prévisualisation-rendue-par-le-serveur)). Tout ce guide découle de ce choix.

## 2. Une route REST dans WordPress

### 2.1. Enregistrer une route

WordPress expose une API REST sous `/wp-json/`. Chaque extension peut y ajouter ses routes sous son propre **namespace** ; le nôtre est `portfolio/v1`, et sa première route est `POST /portfolio/v1/preview`. L'enregistrement se fait sur le hook `rest_api_init`, par `Rest\RouteRegistrar`, que `Plugin::registerRoutes()` ne construit qu'à ce moment-là : la plupart des requêtes (une page du site, un écran d'admin) ne passent jamais par l'API, inutile d'assembler des contrôleurs pour rien.

`register_rest_route()` prend trois choses qui méritent d'être comprises :

- **La méthode HTTP**, ici `POST` (`WP_REST_Server::CREATABLE`). Un `GET` aurait mis le document dans l'URL, ce qui ne tient pas pour un texte de plusieurs kilooctets et laisserait des traces dans les journaux.
- **Le schéma des paramètres** (`args`). Notre seul paramètre, `markdown`, est déclaré `type: string`, `required: true`, `maxLength: 1000000`. WordPress vérifie ce schéma **avant** d'appeler notre code : un document absent répond 400 `rest_missing_callback_param`, un document trop long 400 `rest_invalid_param`, sans qu'une ligne de `PreviewController` ait tourné. C'est la validation d'entrée déplacée dans le framework, et c'est pour ça que tu la déclares plutôt que de la coder.
- **`permission_callback`**, une fonction qui dit si l'appelant a le droit. La nôtre répond `current_user_can('edit_posts')`. Quand elle rend `false`, WordPress répond lui-même `rest_forbidden`, avec le code 401 si personne n'est connecté et 403 si quelqu'un l'est sans avoir le droit. Tu ne choisis pas le code : tu dis oui ou non.

### 2.2. Qui est connecté ? Le nonce REST

Le navigateur envoie les cookies de session de l'admin avec chaque requête, mais WordPress **n'en tient pas compte** pour l'API tant que la requête ne porte pas un nonce REST valide (un jeton lié à l'utilisateur et à l'action `wp_rest`, passé dans l'en-tête `X-WP-Nonce`). Sans ce nonce, l'appelant est traité comme anonyme : c'est la protection contre la falsification de requête (CSRF), où un site tiers ferait faire à ton navigateur une requête vers ton admin avec tes cookies. Le contrôle HTTP du lot le montre : la même requête avec le nonce répond 200, sans le nonce 401.

Un nonce expire (entre douze et vingt-quatre heures, selon le moment où il a été émis). Plutôt que de gérer ça nous-mêmes, le script dépend de `wp-api-fetch`, le client REST que WordPress fournit : quand on déclare cette dépendance à `wp_enqueue_script()`, WordPress imprime avant notre script le réglage de ce client (URL racine de l'API, nonce courant, et l'adresse `admin-ajax.php?action=rest-nonce` où en redemander un). `wp.apiFetch({ path: '/portfolio/v1/preview', method: 'POST', data: { markdown } })` ajoute le nonce, sérialise le corps en JSON, et, si le serveur répond que le nonce est périmé, en obtient un nouveau et rejoue la requête. Ce sont des lignes que tu n'écris pas et que tu ne maintiens pas.

### 2.3. Tester une route sans navigateur

Deux moyens ont servi. Dans WP-CLI, `rest_do_request()` fait passer un objet `WP_REST_Request` par le vrai serveur REST (schéma, permission, contrôleur) sans HTTP : c'est ce que fait `tests/Integration/wp-cli/preview-smoke.php`, qui vérifie les 400, le 401 d'un anonyme, le 403 d'un abonné (créé puis supprimé par le script) et le contenu des réponses. Et avec `curl`, en se connectant à `wp-login.php` puis en lisant le nonce dans la page d'édition (il est imprimé dans le réglage de `wp-api-fetch`), on rejoue exactement ce que fait le navigateur.

## 3. Ce que « le site affiche » veut dire, et ce que la relecture a trouvé

### 3.1. Le même kses que le stockage

Le pipeline du plugin rend le corps en blocs Gutenberg puis, avant d'écrire `post_content`, passe le HTML par `wp_kses()` avec les balises autorisées du contexte `post` (plus la case à cocher des listes de tâches). Sur du contenu réel ce passage ne retire rien, le parseur Markdown échappe déjà le HTML brut ; c'est une dernière ligne de défense. L'aperçu doit appliquer **exactement** le même passage, sinon il pourrait montrer une balise que le stockage retirera. C'est pourquoi ce passage a été sorti du dépôt dans `Storage\HtmlSanitizer::sanitize()`, appelé par les deux : une règle, un endroit.

### 3.2. Le filtre `the_content` n'est pas une fonction

Sur le site, WordPress n'affiche jamais `post_content` tel quel : il le passe par le filtre `the_content`, auquel le cœur accroche une dizaine de fonctions, chacune avec une priorité. Dans l'ordre : l'insertion des blocs accrochés (8), les gestionnaires d'embeds de la classe `WP_Embed` (8), `do_blocks` qui rend les blocs (9), `wptexturize` qui pose la typographie (10), `wpautop` (10, mais retiré quand le contenu est en blocs), `do_shortcode` qui exécute les codes courts comme `[gallery]` (11), `wp_filter_content_tags` qui ajoute les attributs des images (12), et `convert_smilies` (20). Les thèmes et les extensions en ajoutent d'autres.

La première version de la route appelait simplement `apply_filters('the_content', $html)` : « ce que le site affiche », littéralement. La relecture indépendante du lot a démontré, en direct sur le conteneur, que c'était une faute. Un document contenant la ligne `[embed]http://example.com/[/embed]`, du texte ordinaire que ni le parseur Markdown ni kses ne touchent, faisait partir **une requête HTTP du serveur** vers cette URL (la découverte oEmbed, `wp_safe_remote_get()`) et écrivait **une entrée de cache en base** (un post de type `oembed_cache`). Sur le site, ce mécanisme ne joue que pour un contenu publié après relecture ; dans l'aperçu, il jouait à chaque pause de frappe, pour tout utilisateur ayant `edit_posts`, c'est-à-dire tout contributeur. De même, `do_shortcode` exécutait n'importe quel code court enregistré par n'importe quelle extension, et plusieurs filtres attendent un post « dans la boucle » (une variable globale `$post`) qui n'existe pas dans une requête REST.

La leçon dépasse ce lot : **une chaîne de filtres n'est pas une fonction pure**. Elle exécute du code que d'autres ont accroché, avec des effets de bord (réseau, base de données) et des attentes de contexte. Tu ne l'appelles pas « pour faire comme le site » depuis un endroit qui n'est pas le site. La route appelle désormais les trois passes de texte, dans leur ordre : `do_blocks()`, `wptexturize()`, `convert_smilies()`. Un code court s'affiche tel quel dans l'aperçu (le site, lui, l'exécutera), et le test de fumée vérifie qu'un embed ne produit ni lien ni cache.

## 4. Le navigateur : CodeMirror 6

### 4.1. Une famille de modules, pas un éditeur

CodeMirror 6 n'est pas un script à déposer dans une page. C'est une dizaine de paquets npm qui s'assemblent par **extensions** : `@codemirror/state` (le document, la sélection, l'historique, sous forme de valeurs immuables modifiées par transactions), `@codemirror/view` (le DOM, les raccourcis, les gouttières), `@codemirror/language` (les analyseurs syntaxiques Lezer et la coloration), `@codemirror/commands` (les commandes de base et l'historique), `@codemirror/search` (Ctrl+F), et les langages. `plugin/assets/src/editor.js` est cette liste d'extensions, et rien d'autre : retire `lineNumbers()`, les numéros de ligne disparaissent ; ajoute `EditorView.lineWrapping`, les longues lignes se replient.

Notre document a une particularité : un bloc YAML entre deux lignes `---`, puis du Markdown. `@codemirror/lang-yaml` fournit exactement ça avec `yamlFrontmatter({ content: markdown({ base: markdownLanguage }) })` : le frontmatter est analysé comme du YAML et le reste comme du Markdown, chacun avec sa coloration. Le `base: markdownLanguage` n'est pas décoratif : `markdown()` seul analyse du CommonMark nu, et c'est `markdownLanguage` qui apporte les extensions GFM (tableaux, listes de tâches, texte barré), celles que nos fiches utilisent. C'est le genre de besoin qu'un éditeur monolithique ne couvre pas et qu'une famille de modules couvre en une ligne.

### 4.2. Le textarea reste le champ du formulaire

L'éditeur ne remplace pas le `<textarea>`, il le cache. À chaque transaction qui change le document (l'extension `updateListener`), le texte entier est recopié dans le textarea, et il l'est une dernière fois à la soumission du formulaire. Quand on clique sur « Mettre à jour », WordPress reçoit donc le champ `portfolio_md_source` comme avant, et le code d'enregistrement du 26 octobre n'a pas changé d'une ligne. C'est le principe de l'**amélioration progressive** : la page fonctionne sans le script (le textarea est utilisable, et une notice dit comment construire le bundle s'il manque), et le script ajoute par-dessus.

Un détail d'accessibilité : l'extension `indentWithTab` fait indenter par la touche Tab, ce qui est utile dans des listes et des blocs de code, mais piège le clavier dans l'éditeur. CodeMirror prévoit la sortie : Échap puis Tab.

## 5. La boucle d'aperçu : une machine à états sans DOM

`plugin/assets/src/preview.js` ne touche pas au DOM. Il reçoit une fonction `request` (qui appellera `wp.apiFetch`), deux minuteurs (`setTimeout`, `clearTimeout`) et une fonction `onState`, et il produit des **états** sous forme de données : `{ kind: 'empty' }`, `{ kind: 'loading' }`, `{ kind: 'rendered', title, html, summary, problems }`, `{ kind: 'refused', problems }`, `{ kind: 'unavailable', message }`. Le montage (`mount.js`) traduit chaque état en DOM. Séparer les deux a une conséquence concrète : la boucle se teste dans Node en quelques millisecondes, avec de faux minuteurs qu'on avance à la main et de fausses requêtes qu'on résout quand on veut.

Deux mécanismes méritent d'être nommés.

Le **debounce** : la boucle ne demande pas un rendu à chaque touche, elle attend 600 ms sans frappe. Chaque frappe annule le minuteur précédent et en pose un nouveau ; seule la dernière, suivie d'une pause, déclenche la requête.

Le **numéro de séquence** contre les réponses périmées. Imagine deux requêtes envoyées à une seconde d'intervalle : rien ne garantit que la première réponse arrive avant la seconde (le premier document était plus long à rendre, ou le réseau a hoqueté). Si on affichait les réponses dans l'ordre d'arrivée, l'aperçu pourrait finir sur un rendu plus ancien que le texte. La boucle numérote chaque requête ; une réponse dont le numéro n'est plus le dernier est ignorée, qu'elle soit un succès ou un échec. C'est une condition de concurrence classique, et le test `drops an answer that arrives after a newer request was sent` la joue noir sur blanc.

## 6. Isoler l'aperçu : `<iframe sandbox>`

Le HTML renvoyé par le serveur est inséré dans la page… mais pas avec `innerHTML` dans une `<div>`. Deux raisons. La feuille de style de l'admin s'appliquerait au rendu (listes sans puces, titres de l'admin, tableaux de réglages), et l'aperçu ne ressemblerait pas au site. Et surtout, tout ce qui peut s'exécuter dans ce HTML s'exécuterait **dans la session de l'admin**. Kses retire les scripts, mais une défense ne doit jamais reposer sur une seule ligne.

L'aperçu vit donc dans un `<iframe>` dont le document est fourni par l'attribut `srcdoc` et dont l'attribut `sandbox` retire tous les pouvoirs par défaut : pas de script, pas de formulaire, pas de navigation de la page parente, et une **origine opaque** (le document du cadre n'est pas considéré comme venant de ton site, il ne voit donc ni cookies ni stockage). Le cadre ne reçoit que deux pouvoirs : `allow-popups`, pour que les liens s'ouvrent, et `allow-popups-to-escape-sandbox`, pour que l'onglet ouvert soit une page normale (sans ça, le site visé s'ouvrirait lui aussi sans script). Le document du cadre porte un `<base target="_blank">`, et chaque lien reçoit `rel="noopener noreferrer"` : l'onglet ouvert n'a aucune poignée sur le cadre et ne connaît pas l'URL de l'admin. Ce dernier point vient lui aussi de la relecture.

Le document du cadre charge les feuilles de style des blocs du cœur de WordPress (celles qui habillent `wp-block-table`, `wp-block-code`, etc.), pas celle du thème : l'admin n'y a pas accès, et le thème GeneratePress n'est de toute façon pas encore choisi dans ses détails. L'aperçu est donc fidèle au HTML, pas aux couleurs.

Un bouton au-dessus du cadre masque ou réaffiche l'aperçu ; le masquer annule la requête en cours et arrête les suivantes, et le choix est mémorisé dans le `localStorage` du navigateur, donc propre à cet ordinateur, pas au compte WordPress.

Il y a enfin ce que l'aperçu ne peut jamais savoir : si le slug du frontmatter est déjà pris par un autre post, ou si le document diverge de la version importée depuis Git (la règle de conflit d'ADR-005). Ces deux vérifications regardent les autres posts et le hash stocké, que `PreviewService` ne consulte jamais : il reçoit un texte, pas un post. Un document peut donc s'afficher sans le moindre problème listé et être refusé à l'enregistrement ; c'est alors la métabox qui garde ton texte et dit pourquoi.

## 7. Construire le JavaScript : modules ES, npm et esbuild

Le navigateur charge un seul fichier, `plugin/assets/dist/admin-editor.js`. Il est produit par **esbuild** à partir de `plugin/assets/src/admin-editor.js` et de ses imports, en suivant les `import` de module en module jusque dans `node_modules` (CodeMirror et ses dépendances), puis en concaténant et minifiant le tout dans une fonction immédiatement invoquée (format `iife`) : rien ne fuit dans l'espace global, et l'admin de WordPress n'a pas besoin de savoir charger des modules. Le résultat pèse environ 560 Ko (les grammaires Markdown, YAML et HTML en font l'essentiel), servi une fois puis mis en cache.

Ce fichier **n'est pas versionné**, pas plus que `node_modules/`. Il se reconstruit en deux commandes dans `plugin/assets` :

```bash
npm ci          # installe exactement les versions de package-lock.json
npm run build   # esbuild → dist/admin-editor.js
```

`npm ci` plutôt que `npm install` : le premier refuse de dévier du fichier de verrouillage, le second peut le réécrire. C'est l'équivalent de `composer install` avec `composer.lock`, et le parallèle va plus loin : `vendor/` n'est pas versionné non plus, et le plugin a toujours eu besoin d'une étape de construction après un clone.

Côté PHP, `Admin\EditorAssets` fait trois choses sur le hook `admin_enqueue_scripts`, et seulement sur les écrans concernés (il lit `get_current_screen()->post_type` sur `post.php` et `post-new.php`, et reconnaît la page de collage par son identifiant de hook). Il enfile la feuille de style et le script avec `wp_enqueue_script()`, en déclarant la dépendance `wp-api-fetch` et en passant la date de modification du fichier comme version (`?ver=…` change à chaque reconstruction, les caches ne servent jamais un vieux bundle). Il imprime la configuration du script avec `wp_add_inline_script(…, 'before')` : le chemin de la route, les feuilles de style du cadre, les libellés en français. Et si le bundle n'existe pas, il n'enfile rien et accroche une notice sur `admin_notices`.

## 8. Tester sans navigateur

Il n'y a pas de navigateur dans la chaîne de tests, et pourtant presque tout est couvert.

- **Les fonctions pures** (`preview.js`, `preview-frame.js`) sont testées avec le lanceur intégré de Node (`node --test`), sans aucune dépendance : faux minuteurs, fausses requêtes, assertions sur les états et sur le HTML du cadre.
- **Le montage sur le textarea** (`mount.js`) est joué dans **jsdom**, un DOM en JavaScript pur. Il reçoit une fabrique d'éditeur factice (un objet avec `getValue` et une méthode `type` qui simule la frappe) et une requête factice : les tests vérifient que le textarea est caché mais reste dans le formulaire, qu'il reçoit chaque frappe, que la soumission le resynchronise, que masquer l'aperçu arrête les requêtes, que le cadre porte bien son `sandbox`. C'est l'injection de dépendances appliquée au DOM : tout ce que le navigateur fournit est passé en paramètre.
- **CodeMirror lui-même n'est pas testé** : c'est une bibliothèque mature avec ses propres tests. Ce qui est testé, c'est ce qu'on en fait.
- Côté PHP, la route et le service ont leurs tests unitaires avec les doublures de `tests/Support/wp-stubs.php` (requête et réponse REST, `apply_filters`, kses, enfilement des scripts), puis le test de fumée `preview-smoke.php` passe par le vrai WordPress.

Ce qui reste hors de portée : la CSS (voir le piège de l'attribut `hidden` ci-dessous) et le rendu réel dans un navigateur. C'est ce qu'il faut vérifier à l'œil après chaque lot.

## 9. Pièges rencontrés pendant le lot

- **`the_content` a des effets de bord** (section 3.2). Ne jamais appeler une chaîne de filtres depuis un contexte qui n'est pas le sien sans lister ce qu'elle contient.
- **L'attribut `hidden` perd contre `display`**. Le navigateur cache un élément `hidden` par une règle `display: none` de faible priorité ; une règle `display: flex` dans ta feuille de style la bat, et l'élément reste visible. La feuille de l'éditeur pose une règle `display: none !important` sur ses éléments `[hidden]` (sélecteurs `.portfolio-md-editor [hidden]` et `textarea[data-portfolio-md-editor][hidden]`). Aucun test JavaScript ne pouvait l'attraper : jsdom ne calcule pas les styles.
- **`??` masque un `null` attendu**. En PHP, `$data['html'] ?? 'x'` vaut `'x'` quand `html` vaut `null`, puisque `??` traite `null` comme absent. Pour vérifier qu'une clé vaut `null`, il faut `array_key_exists()` puis `=== null`. Un contrôle du test de fumée a échoué pour cette seule raison.
- **`node --test tests/` ne parcourt pas un dossier** : il faut un motif, `node --test "tests/**/*.test.js"`, entre guillemets pour que Node, et non le shell, développe le motif.
- **npm 11 signale les scripts d'installation des paquets** qui ne figurent pas dans sa liste d'autorisations (`npm warn install-scripts`, ici pour le `postinstall` d'esbuild), mais il les exécute quand même par défaut : l'avertissement invite à les passer en revue (`npm install-scripts ls`), il ne bloque rien. Et esbuild n'en dépend de toute façon pas : son binaire vient d'un paquet optionnel par plateforme (`@esbuild/linux-x64` ici).
- **`git reset --hard` efface une modification non commitée** d'un fichier qui n'existe pas dans le commit cible. Une correction CSS faite avant un `reset --hard` a dû être refaite. Commiter, ou mettre de côté (`git stash`), avant de réécrire l'historique local.
- **WordPress 7.1 charge `wp-block-editor` sur toutes les pages de l'admin** (pour sa palette de commandes), y compris la liste des projets. Pour vérifier que l'éditeur de blocs est bien coupé, chercher son interface (`wp-edit-post`, le conteneur `#editor`), pas ce script.
- **Le style `wp-block-library` ne pointe plus vers la feuille complète** des blocs : dans WordPress 7.1, ce handle sert `common.min.css`, les styles de chaque bloc étant chargés à la demande. Le cadre charge explicitement `css/dist/block-library/style.min.css` et `theme.min.css`, qui existent toujours et contiennent tout.

## 10. Pour aller plus loin

- [REST API Handbook — Adding Custom Endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/) et [Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) (le nonce, `X-WP-Nonce`).
- [`@wordpress/api-fetch`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-api-fetch/) : les middlewares de nonce et d'URL racine.
- [CodeMirror 6 — System Guide](https://codemirror.net/docs/guide/) : état, vue, transactions et extensions.
- [MDN — attribut `sandbox` de `<iframe>`](https://developer.mozilla.org/fr/docs/Web/HTML/Element/iframe#sandbox) et [`rel="noopener"`](https://developer.mozilla.org/fr/docs/Web/HTML/Attributes/rel/noopener).
- [esbuild — Getting Started](https://esbuild.github.io/getting-started/) et [`node --test`](https://nodejs.org/api/test.html).

---

**Retour à** : [documentation pédagogique](README.md) • [`01-plugin-php.md` §6.1](../architecture/01-plugin-php.md) • [ADR-016](../architecture/99-decisions.md)

# 01 — Prise en main de WordPress

**Fichier** : `docs/guides/01-prise-en-main-wordpress.md`
**Rôle** : guide opérationnel et progressif pour apprendre WordPress dans le contexte de ce projet. Doc « par où je commence ce matin », par opposition à `docs/architecture/04-structure-wordpress.md` qui est la référence durable.
**Lectorat** : Morgan, en cours d'apprentissage WordPress.
**Statut** : guide actif, mis à jour à mesure que l'apprentissage progresse.
**Dernière mise à jour** : 25 avril 2026 (étapes 1 et 2 terminées, étape 3 redéfinie)

---

## À quoi sert ce document

Ce document est un **carnet de bord d'apprentissage** : il accompagne pas à pas la prise en main de WordPress, dans l'ordre où ça fait sens d'apprendre les choses.

Il a une particularité : il est **destiné à devenir caduc**. Quand tu maîtriseras WordPress, tu n'auras plus besoin de revenir lire ce guide. À ce moment-là, on pourra le marquer comme `archivé` ou le retirer de la doc active. C'est normal — c'est ce qui distingue un guide d'apprentissage d'une référence d'architecture.

En attendant, il sert deux objectifs : te donner un fil clair pour ne pas te perdre dans l'admin WordPress (qui est riche et désordonnée), et **éviter les pièges classiques** qui font perdre du temps aux débutants.

---

## Le piège majeur à éviter dès le départ

Avant tout, un avertissement qui va te faire gagner des semaines : **WordPress sans page builder, jamais.**

Les plugins comme Elementor, Divi, WPBakery, Beaver Builder donnent l'impression magique qu'on peut « tout faire visuellement ». Le piège est triple :

D'abord, ils génèrent du HTML imbuvable, lent, et truffé de classes CSS qui ne respectent aucune convention. Tout ce qu'on a posé dans `docs/branding/03-direction-graphique.md` (sobriété, basse densité visuelle, signature SCADA) devient inapplicable.

Ensuite, ils créent une **dépendance permanente**. Une fois qu'un contenu est créé avec Elementor, il est inutilisable sans Elementor.

Enfin et surtout pour ce projet, ils sont **incompatibles avec le format pivot Markdown** (voir `docs/02-le-markdown-comme-format-pivot.md`). Tu as construit toute ton architecture autour de Markdown comme source unique de vérité. Un page builder casse ce pivot.

**La règle pour ce projet** : tu utilises Gutenberg (l'éditeur natif de WordPress depuis 2018) et c'est tout.

---

## Démarrage rapide (TL;DR)

Procédure condensée pour relancer WordPress depuis zéro :

```bash
# 1. Cloner le repo si pas déjà fait
git clone <url-du-repo> portfolio-double && cd portfolio-double

# 2. Copier le modèle d'environnement
cp .env.example .env

# 3. Modifier .env si tu veux (par défaut admin/admin/admin@example.local)
# nano .env

# 4. Installer les dépendances Composer du plugin
docker run --rm -v "$(pwd)/plugin:/app" composer:latest install

# 5. Lancer Docker
docker compose up -d

# 6. Bootstrapper WordPress (installation + configuration)
./bootstrap.sh

# 7. Activer le plugin Portfolio MD
# Soit dans wp-admin > Extensions, soit en ligne de commande :
docker compose --profile tools run --rm wpcli wp plugin activate portfolio-md

# 8. Ouvrir http://localhost:8000
```

Pour **tout repartir de zéro** :

```bash
docker compose down -v
rm -rf BDD_data wordpress_data
docker compose up -d
./bootstrap.sh
docker compose --profile tools run --rm wpcli wp plugin activate portfolio-md
```

---

## Comprendre ce qui se passe sous le capot

Avant d'aller plus loin, il faut que tu comprennes pourquoi ces commandes existent.

### Les volumes Docker et la persistance

Quand un container Docker démarre, il se base sur une **image** (`wordpress:latest`, `mariadb:11`). Une image est immuable. Si le container écrit des fichiers (par exemple, une base de données qui stocke ses tables), ces fichiers sont dans le système de fichiers du container.

Par défaut, **quand tu détruis un container, ses fichiers disparaissent**. Pour qu'ils persistent, on utilise des **volumes**. Un volume est une zone du système de fichiers de l'hôte qui est montée dans le container.

Dans notre `docker-compose.yml`, on utilise des **bind mounts** :

```yaml
volumes:
  - ./BDD_data:/var/lib/mysql:z              # données MariaDB
  - ./wordpress_data:/var/www/html:z         # WordPress complet
  - ./plugin:/var/www/html/wp-content/plugins/portfolio-md:z   # notre plugin
```

Le troisième volume est une particularité de ce projet : on monte le dossier `plugin/` (versionné, à toi) directement à l'emplacement du plugin dans WordPress. Cela évite les problèmes de permissions (le plugin reste à toi, pas à `www-data`) et les liens symboliques.

**Mais attention** : `BDD_data/` et `wordpress_data/` sont dans le `.gitignore`. Ils ne sont **pas synchronisés** entre tes différentes machines. Quand tu clones le projet sur une nouvelle machine, ils n'existent pas, donc WordPress part vierge. C'est précisément pourquoi on a écrit `bootstrap.sh` — pour reconstruire l'état initial automatiquement.

### Le rôle de `bootstrap.sh`

`bootstrap.sh` utilise **WP-CLI** (l'outil officiel en ligne de commande de WordPress) pour faire automatiquement ce que tu ferais manuellement dans l'admin lors d'une première installation : installation, permaliens propres, désactivation des commentaires, suppression des contenus de démo, configuration timezone et format de date.

Le script est **idempotent** : tu peux le relancer plusieurs fois sans casser quoi que ce soit.

### Le service `wpcli`

Dans le `docker-compose.yml`, le service `wpcli` a un `profiles: [tools]`. Ce profil signifie qu'il **ne démarre pas automatiquement** avec `docker compose up -d`. Il est invoqué à la demande :

```bash
docker compose --profile tools run --rm wpcli wp <commande>
```

Tu peux l'utiliser à la main pour explorer WP-CLI :

```bash
docker compose --profile tools run --rm wpcli wp option list
docker compose --profile tools run --rm wpcli wp user list
docker compose --profile tools run --rm wpcli wp plugin list
docker compose --profile tools run --rm wpcli wp post-type list
```

---

## La progression d'apprentissage

L'apprentissage est découpé en trois étapes. Les étapes 1 et 2 sont terminées, l'étape 3 est ouverte.

### Étape 1 — Familiarisation avec l'admin ✅

**Statut** : terminée le 25 avril 2026.

**Objectif** : savoir où sont les choses, pas en produire un site utilisable.

À ce stade, on a cliqué partout dans l'admin avec une attitude exploratoire. Le but était de comprendre l'outil, pas de construire le portfolio. Tu as exploré les Articles natifs, les Pages, les Médias, les Réglages, l'éditeur Gutenberg.

Conclusion : WordPress est intuitif pour les actions simples mais il faut rester vigilant sur les fonctionnalités séduisantes en surface qui peuvent compromettre le format pivot Markdown (médias avec versions multiples, widgets, menus, options inline).

### Étape 2 — Structure de contenu en code propre ✅

**Statut** : terminée le 25 avril 2026.

**Objectif initial du guide** : comprendre les notions de **custom post type**, **taxonomie**, **champ personnalisé**, **hook**, en les manipulant concrètement avec des plugins visuels (CPT UI, ACF), avant de recoder en étape 3.

**Choix retenu** : chemin B (saut direct au code propre, sans passer par les plugins visuels). Décision prise en cours de session sur la base du profil de l'apprenant (déjà à l'aise en PHP, parcours d'automaticien, goût pour le contrôle).

**Ce qui a été produit** :

- Squelette du plugin `portfolio-md` avec namespace `Voltz\PortfolioMd`, autoloading PSR-4, typage strict PHP 8.2.
- Composition root `Plugin.php` qui orchestre l'instanciation des services et l'accrochage des hooks WordPress.
- Service `PostTypeRegistrar` qui enregistre les custom post types `article` et `project` selon les spécifications de `docs/architecture/04-structure-wordpress.md` sections 3.2 et 3.3.
- Service `TaxonomyRegistrar` qui enregistre les taxonomies `tech_tag` (partagée) et `project_status` (réservée aux projets, valeurs fermées) selon les sections 4.1 et 4.2 du même document.
- Auto-insertion des quatre statuts fixes (En cours, Stable, Archivé, Prototype) à l'activation du plugin via `ensureProjectStatusTerms`.
- Tous les libellés de l'admin sont passés par les fonctions de traduction WordPress (`__()` et `_x()`), prêts pour l'i18n.

**Ce qui n'a pas été fait à cette étape** (et qui sera abordé plus tard) :

- Champs personnalisés (custom fields) sur `project` (date de début, date de fin, URL du dépôt, etc.). Reportés à plus tard.
- Pipeline de conversion Markdown ↔ WordPress. C'est l'étape 3.
- Endpoint REST `/sync` pour la synchronisation Git → WordPress. Reporté.
- Désactivation des post types natifs `post` (à évaluer plus tard).
- Durcissement de `project_status` pour interdire la création de nouveaux statuts via l'admin (capabilities custom). Reporté.

**Validation finale** : un projet de test créé, statut « En cours » assigné, tag `Rust` assigné, URLs `/statut/en-cours/` et `/tag/rust/` qui répondent correctement avec le projet listé. Acquis fonctionnel confirmé.

### Étape 3 — Pipeline Markdown ⏳

**Statut** : ouverte, à attaquer dans une prochaine session.

**Objectif** : implémenter le pipeline qui transforme un fichier Markdown (frontmatter YAML + corps Markdown) en contenus WordPress (post avec son titre, son slug, son corps en blocs Gutenberg, ses tags, son statut, ses champs personnalisés). Et inversement : recomposer un Markdown propre depuis un contenu WordPress.

C'est le cœur fonctionnel du plugin, défini dans `docs/architecture/01-plugin-php.md`. Cela inclut :

- Le parser YAML pour le frontmatter.
- La transformation Markdown → blocs Gutenberg (`core/paragraph`, `core/heading`, `core/code`, `core/list`, etc.).
- Les Repository qui manipulent les contenus côté WordPress.
- Les services orchestrateurs (`UpsertContent`, `DumpContent`).
- L'enregistrement des champs personnalisés (custom fields) dans le bon format.
- L'endpoint REST `/sync` pour recevoir des contenus depuis un script Git.
- La résolution de conflits si un contenu a été modifié à la fois dans Git et dans l'admin WordPress.

C'est l'étape la plus dense techniquement. Elle se fera probablement sur plusieurs sessions, par paliers visibles.

---

## Conventions et bonnes habitudes

**Permaliens propres dès le début.** Déjà fait par `bootstrap.sh`.

**Désactive les commentaires.** Déjà fait par `bootstrap.sh`.

**Crée un utilisateur secondaire pour la rédaction.** Le `bootstrap.sh` crée un utilisateur `morgan` (ou ce que tu mets dans `.env`). Ne te connecte jamais avec un compte nommé `admin` — c'est la première cible des attaques par force brute en production.

**Sauvegarde régulièrement la base de test.** Avec `bootstrap.sh`, tu peux aussi simplement réinitialiser proprement.

**Régénère l'autoloader Composer après chaque ajout de classe.** Quand tu crées un nouveau fichier `.php` dans `plugin/src/`, lance `composer dump-autoload` (ou via Docker) pour que Composer mette à jour sa map. Sinon tu auras une erreur `Class not found` au runtime.

---

## Articulation avec les autres docs

- **`docs/architecture/04-structure-wordpress.md`** est la **référence durable** de la structure. Les classes `PostTypeRegistrar` et `TaxonomyRegistrar` du plugin l'implémentent.
- **`docs/architecture/01-plugin-php.md`** détaille **la cible architecturale complète du plugin** (pipeline, REST, etc.). À l'heure actuelle, seules les fondations et l'étape 2 sont implémentées.
- **`docs/02-le-markdown-comme-format-pivot.md`** explique **pourquoi WordPress n'est pas la source de vérité** ; le Markdown l'est.
- **`docs/branding/03-direction-graphique.md`** définit **la direction graphique** que le thème WordPress devra respecter à terme.
- **`docs/99-dette-technique.md`** liste les **points d'attention** sur l'environnement.

---

## Journal de progression

**2026-04-25** — Création du guide. Étape 1 en cours.

**2026-04-25 (mise à jour 1)** — Ajout du bootstrap automatique : `bootstrap.sh` + `.env`. La procédure de mise en route est passée de « installation manuelle dans le navigateur » à « une commande ».

**2026-04-25 (mise à jour 2)** — Étape 1 terminée. Familiarisation avec l'admin acquise.

**2026-04-25 (mise à jour 3)** — Étape 2 terminée en chemin B (code propre direct, pas de plugins visuels). Plugin `portfolio-md` opérationnel avec ses deux custom post types et ses deux taxonomies. Étape 3 redéfinie comme « pipeline Markdown », ouverte pour la suite.

---

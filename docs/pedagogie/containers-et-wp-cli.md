# Containers, Podman et WP-CLI : guide opérationnel et pièges courants

**Public visé** : développeur junior qui découvre le projet et veut comprendre le pourquoi de la stack containers, ou qui rencontre une erreur sans savoir comment la diagnostiquer.

**Prérequis** : connaître les bases de Docker (image, container, volume, port mapping). Si ce vocabulaire n'est pas familier, lire d'abord la [doc officielle Docker — Get started](https://docs.docker.com/get-started/) (les chapitres 1 à 4 suffisent).

**Statut** : guide vivant. Mis à jour à chaque fois qu'un nouveau piège est découvert pendant le développement.

---

## 1. La stack containers du projet

L'environnement de développement local repose sur trois services définis dans `docker-compose.yml` à la racine du dépôt. Tous les trois utilisent des images officielles tirées de Docker Hub. On ne construit aucune image custom : tout est paramétré par variables d'environnement et bind mounts.

### 1.1. Le service `db`

Image : `mariadb:11`. C'est la base de données de WordPress. On utilise MariaDB plutôt que MySQL parce que MySQL 5.7 est en fin de vie depuis 2023, et que MariaDB est devenue le défaut chez la plupart des hébergeurs WordPress modernes. La compatibilité avec WordPress est totale : du point de vue du PHP, c'est exactement le même protocole.

Les données sont persistées sur l'hôte par un **bind mount** (voir §1.4) : `./BDD_data:/var/lib/mysql:z`. Cela veut dire que tu peux arrêter, supprimer, recréer le container `db` autant de fois que tu veux : tant que tu ne touches pas au dossier `BDD_data/` sur ton disque, tes tables restent.

Un *healthcheck* est configuré : `healthcheck.sh --connect --innodb_initialized`. Il sert au container `wordpress` à attendre que MariaDB soit *vraiment* prête (et pas seulement que le process ait démarré) avant de tenter la connexion.

### 1.2. Le service `wordpress`

Image : `wordpress:latest`. C'est le WordPress lui-même : Apache, PHP 8.3, et WordPress core préinstallé. Le port 80 du container est exposé sur le port `${WP_PORT:-8000}` de l'hôte — par défaut 8000.

Deux bind mounts importants :

| Bind mount | Rôle |
|---|---|
| `./wordpress_data:/var/www/html:z` | Tout le WordPress (core + wp-content). Permet d'inspecter et modifier les fichiers depuis l'hôte. |
| `./plugin:/var/www/html/wp-content/plugins/portfolio-md:z` | Le plugin du projet, monté directement à son emplacement final dans WordPress. Toute modification dans `./plugin` est immédiatement visible côté serveur, sans rebuild ni copie. |

Les variables d'environnement (`WORDPRESS_DB_HOST`, `WORDPRESS_DB_USER`, etc.) sont injectées par WordPress dans le `wp-config.php` au premier démarrage, en remplaçant les placeholders de l'image officielle.

### 1.3. Le service `wpcli`

Image : `wordpress:cli`. C'est un container **éphémère** dédié à l'exécution de commandes WP-CLI. Il est sous le profil `tools` (cf. §1.5), ce qui veut dire qu'il ne démarre **pas** automatiquement avec `up -d`. On l'invoque à la demande.

Il partage les mêmes bind mounts que `wordpress` (`wordpress_data` et `plugin`), donc il agit sur la même installation. Il dépend aussi de `db` pour les commandes qui touchent à la base.

Détail technique important : il tourne en `user: "33:33"`, qui est l'UID de `www-data` dans l'image WordPress. WP-CLI refuse de tourner en root par défaut (mécanisme de sécurité), donc on doit forcer un user non-root, et il faut que ce user puisse écrire dans les fichiers.

### 1.4. Bind mounts vs volumes nommés

Docker (et Podman) propose deux mécanismes pour persister des données entre les redémarrages de container :

**Volume nommé** : Docker gère lui-même le stockage, dans un emplacement opaque (`/var/lib/docker/volumes/...`). Tu y accèdes uniquement via les commandes Docker. Avantage : portable, sans soucis de permissions sur l'hôte. Inconvénient : tu ne peux pas inspecter ou éditer les fichiers facilement.

**Bind mount** : tu mappes un dossier précis de ton hôte vers un dossier dans le container. Avantage : tu vois et modifies les fichiers depuis ton OS habituel. Inconvénient : tu hérites des problèmes de permissions et de filesystem de l'hôte.

Ce projet utilise **uniquement des bind mounts**, par choix pédagogique : on veut que les données et le code soient tangibles, inspectables, versionnables (le `.gitignore` exclut bien sûr les dossiers de données mais leur structure est observable). Les dossiers concernés à la racine :

| Dossier | Contenu | Origine |
|---|---|---|
| `BDD_data/` | Tables MariaDB binaires (`ibdata1`, `mysql/`, `portfolio_wp/`, etc.) | Créé au premier `up` du service `db` |
| `wordpress_data/` | WordPress complet (core, wp-config.php, wp-content) | Créé au premier `up` du service `wordpress` |

**Conséquence opérationnelle** : `podman-compose down -v` ne supprime que les *volumes nommés* (or il n'y en a aucun ici). Pour repartir d'un WordPress vierge, il faut **aussi** supprimer manuellement `BDD_data/` et `wordpress_data/`. Le `bootstrap.sh` documente cette séquence.

### 1.5. Le profil `tools`

Docker Compose v2 (et Podman Compose récents) supporte la notion de **profils** : un service marqué avec un profil ne démarre **que** si ce profil est explicitement activé. C'est utile pour les services qui ne tournent pas en permanence — typiquement, des outils ponctuels.

Dans notre `docker-compose.yml`, le service `wpcli` est marqué `profiles: [tools]`. Conséquences :

- `podman-compose up -d` ne démarre que `db` et `wordpress`. Pas `wpcli`.
- Pour utiliser `wpcli`, il faut activer le profil :
  ```bash
  podman-compose --profile tools run --rm wpcli wp <commande>
  ```
- Si tu oublies `--profile tools`, tu obtiens :
  ```
  WARNING:podman_compose:missing services [wpcli]
  ```
  Ce n'est pas un bug : c'est le filtre du profil qui masque le service, comme prévu.

Variante équivalente avec une variable d'environnement :
```bash
COMPOSE_PROFILES=tools podman-compose run --rm wpcli wp <commande>
```
Pratique si tu veux enchaîner plusieurs commandes sans répéter `--profile tools`.

---

## 2. `podman compose` ≠ `podman-compose` : le piège fondamental

C'est **le** piège qui peut casser ta journée. Lis attentivement.

### 2.1. Deux outils, même format YAML

Il existe deux outils différents qui consomment le même `docker-compose.yml` et qui s'invoquent presque pareil :

| Outil | Commande | Origine | Implémentation |
|---|---|---|---|
| **`podman-compose`** | avec un **tiret** | Projet communautaire | Script Python qui traduit le YAML en commandes `podman` |
| **`podman compose`** | avec un **espace** | Sous-commande native de `podman` | Wrapper qui appelle `docker-compose` (Python) ou `compose` (Go) selon ce qui est installé |

Le format YAML est identique. Les services définis sont les mêmes. Mais les **noms internes** que chaque outil donne aux containers diffèrent :

| Outil | Convention de nommage |
|---|---|
| `podman-compose` | `<projet>_<service>_1` (avec **underscores**) |
| `podman compose` | `<projet>-<service>-1` (avec **tirets**) |

Donc pour le service `db` du projet `portfolio-md`, on obtient :
- Avec `podman-compose` : `portfolio-md_db_1`
- Avec `podman compose` : `portfolio-md-db-1`

### 2.2. Pourquoi c'est un problème

Du point de vue de Podman, **ce sont deux containers totalement différents**. Aucune relation. Ils peuvent même coexister.

Mais ils ont été générés depuis le **même** `docker-compose.yml`, donc ils pointent sur les **mêmes** bind mounts (`./BDD_data`, `./wordpress_data`). Si tu lances les deux en parallèle, deux instances de MariaDB tentent d'écrire dans le même `BDD_data/ibdata1`. La deuxième échoue à prendre le verrou `fcntl` exclusif et **crashe en boucle**.

Cas vécu (28 avril 2026, cette session) : `bootstrap.sh` utilisait à un endroit `podman compose` (espace) et l'utilisateur lançait en manuel `podman-compose` (tiret). Résultat dans `podman ps -a` :

```
NAMES                     STATUS                    IMAGE
portfolio-md-db-1         Up 4 minutes (healthy)    mariadb:11      ← stack 1 (tirets)
portfolio-md-wordpress-1  Up 4 minutes              wordpress       ← stack 1
portfolio-md_db_1         Up 23 seconds (starting)  mariadb:11      ← stack 2 (underscores), crashe en boucle
portfolio-md_wordpress_1  Created                   wordpress       ← stack 2, jamais démarré
```

Et dans les logs de la stack 2 :
```
[ERROR] InnoDB: Unable to lock ./ibdata1 error: 11
[Note] InnoDB: Check that you do not already have another mariadbd process using the same InnoDB data
[ERROR] mariadbd: Can't lock aria control file '/var/lib/mysql/aria_log_control' for exclusive use
```

`error: 11` = `EAGAIN` (« Resource temporarily unavailable ») = quelqu'un d'autre tient le lock.

### 2.3. Comment diagnostiquer

Trois commandes te révèlent le problème :

```bash
# 1. Lister TOUS les containers, y compris ceux qui ne tournent pas
podman ps -a

# 2. Lister les processus mariadbd vus depuis l'hôte
ps -eo pid,user,cmd | grep -E 'mariadb|mysqld' | grep -v grep

# 3. Lire les logs du container qui crashe
podman logs --tail 50 <nom_du_container>
```

Si tu vois deux conteneurs `db` (un avec underscores, un avec tirets) ou deux process `mariadbd` distincts, c'est diagnostiqué.

### 2.4. Comment réparer

```bash
# 1. Arrêter chaque stack avec son outil de création
podman-compose down                       # nettoie la stack underscores
podman compose down 2>/dev/null || true   # nettoie la stack tirets (silencieux si non installé)

# 2. Filet de sécurité : forcer la suppression si le down a échoué
podman rm -f portfolio-md-db-1 portfolio-md-wordpress-1 \
              portfolio-md_db_1 portfolio-md_wordpress_1 2>/dev/null

# 3. Vérifier qu'aucun mariadbd ne tourne plus
ps -eo pid,user,cmd | grep -E 'mariadb|mysqld' | grep -v grep
# (sortie attendue : vide)

# 4. Repartir d'UN SEUL outil
podman-compose up -d
```

**Aucune commande ci-dessus n'a `-v`**, donc les bind mounts (`BDD_data/`, `wordpress_data/`) ne sont **pas** touchés. Tes données sont préservées.

### 2.5. Règle du projet

**On utilise `podman-compose` (avec tiret, le projet Python).** Toujours. Partout.

- Le `bootstrap.sh` utilise `podman-compose`.
- Le `README.md` documente `podman-compose`.
- Les exemples de cette doc utilisent `podman-compose`.

Si tu utilises Docker au lieu de Podman, substitue `docker compose` (avec espace, v2) à toutes les occurrences. Docker n'a pas le doublon `docker compose` / `docker-compose` problématique : `docker-compose` (avec tiret, v1) est en fin de vie et il ne faut plus l'utiliser.

---

## 3. WP-CLI dans une stack containerisée

[WP-CLI](https://wp-cli.org/) est l'outil en ligne de commande officiel de WordPress. Il permet d'installer, configurer, gérer un WordPress sans passer par l'interface admin. Quasi-tout ce que tu peux faire dans `wp-admin` est faisable via `wp <commande>`, et c'est scriptable.

Dans une stack containerisée, on ne l'installe **pas** sur l'hôte : on l'utilise via le container `wpcli`.

### 3.1. Pourquoi un service séparé

L'image `wordpress:latest` contient WordPress, Apache, PHP. Mais **pas WP-CLI**. C'est intentionnel : l'image `wordpress:latest` est conçue pour servir WordPress, pas pour l'administrer. WP-CLI est livré dans une image séparée, `wordpress:cli`, qui contient PHP + WP-CLI mais pas Apache.

Conséquence : si tu fais `podman exec wordpress wp <commande>`, tu obtiens :
```
Error: crun: executable file `wp` not found in $PATH
```
Parce que tu cherches WP-CLI dans le mauvais container.

### 3.2. `exec` vs `run --rm` : deux primitives, deux usages

| Primitive | Effet | Quand l'utiliser |
|---|---|---|
| `exec <container> <cmd>` | Lance `<cmd>` dans un container **déjà running**. Réutilise un container existant. | Quand tu veux inspecter ou agir sur un service qui tourne déjà (ex : `podman exec wordpress bash` pour ouvrir un shell dans le container WordPress en marche). |
| `run --rm <service> <cmd>` | Crée un **nouveau** container éphémère depuis l'image définie pour `<service>`, lance `<cmd>`, supprime le container à la fin. | Quand tu veux exécuter une commande ponctuelle dans un environnement « propre », sans toucher aux services qui tournent. C'est le bon mode pour WP-CLI. |

Pour WP-CLI, on utilise toujours `run --rm` parce que :
1. Le service `wpcli` n'a aucune raison de tourner en permanence (c'est un outil, pas un démon).
2. Il est sous le profil `tools`, donc `up -d` ne le démarre pas — `exec` n'aurait rien à attaquer.
3. `--rm` garantit qu'on n'accumule pas des dizaines de containers `wpcli` morts dans `podman ps -a`.

Forme canonique d'une commande WP-CLI dans ce projet :
```bash
podman-compose --profile tools run --rm wpcli wp <commande WP-CLI>
```

### 3.3. Erreurs typiques

#### `wp: executable not found`
```
Error: crun: executable file `wp` not found in $PATH
```
**Cause** : tu utilises `exec wordpress wp ...` au lieu de `run --rm wpcli wp ...`. WP-CLI n'est pas dans l'image WordPress.
**Fix** : remplace `exec wordpress` par `--profile tools run --rm wpcli`.

#### `missing services [wpcli]`
```
WARNING:podman_compose:missing services [wpcli]
```
**Cause** : tu as oublié `--profile tools`. Le service `wpcli` est masqué tant que le profil n'est pas activé.
**Fix** : ajoute `--profile tools` avant `run` (ou exporte `COMPOSE_PROFILES=tools`).

#### `Too many positional arguments: ...`
```
Error: Too many positional arguments: echo exit=1
```
**Cause** : tu as collé deux commandes sur une seule ligne sans séparateur. La deuxième est passée comme argument à `wp`.
**Fix** : sépare avec `;`, `&&`, ou un saut de ligne.

#### `Error establishing a database connection` côté WP-CLI
**Cause possible** : le service `db` n'est pas en état healthy au moment où `wpcli` démarre, ou le `.env` a des credentials incohérents avec ceux qu'utilise déjà la base.
**Fix** : vérifier `podman-compose ps` (db healthy ?), et que `DB_NAME`, `DB_USER`, `DB_PASSWORD` du `.env` correspondent à ce qui est en base. Si la base a été créée avec d'autres credentials et que tu as ensuite changé le `.env`, la base ne se reconfigurera pas toute seule — il faut soit mettre à jour les users dans la base, soit purger `BDD_data/` et repartir.

### 3.4. Cheat-sheet

Toutes ces commandes sont précédées de `podman-compose --profile tools run --rm wpcli` (omis ici pour la lisibilité — ajoute le préfixe à chaque ligne).

```bash
# Etat de l'installation
wp core is-installed              # exit 0 si oui, 1 si non
wp core version                   # version de WordPress
wp option get siteurl             # URL configurée
wp option get blogname            # titre du site

# Installation depuis zéro (utilisé par bootstrap.sh)
wp core install --url=http://localhost:8000 --title="..." \
                --admin_user=morgan --admin_password=... \
                --admin_email=...@... --skip-email

# Plugins
wp plugin list                                    # tous les plugins, état actif/inactif
wp plugin activate portfolio-md                   # activer le plugin du projet
wp plugin deactivate portfolio-md                 # désactiver

# Custom post types et taxonomies (utile pour vérifier l'enregistrement)
wp post-type list                                 # tous les CPT enregistrés
wp taxonomy list                                  # toutes les taxonomies
wp post-type get article                          # détails d'un CPT précis

# Posts
wp post list --post_type=article --format=table   # lister les articles du CPT
wp post create --post_type=article --post_title="Test" --post_status=publish

# Eval PHP (utile pour debug rapide d'une fonction WP)
wp eval "var_dump(get_registered_meta_keys('post', 'article'));"

# Shell interactif (REPL PHP avec WordPress chargé)
wp shell

# Database (accès direct à mariadb client via WP-CLI, pratique pour requêtes ad hoc)
wp db cli           # ouvre un client mariadb interactif
wp db query "SELECT ID, post_title FROM wp_posts WHERE post_type='article' LIMIT 5;"

# Réinitialisation rapide pendant le développement
wp post delete $(wp post list --post_type=article --format=ids) --force
```

Doc de référence : [WP-CLI Commands](https://developer.wordpress.org/cli/commands/).

---

## 4. Troubleshooting

Récapitulatif des galères vécues et de leur diagnostic.

### 4.1. WordPress ne répond pas dans le navigateur

**Symptômes** : `localhost:8000` (ou le port configuré) renvoie « connexion refusée », « ERR\_CONNECTION\_REFUSED », ou un timeout.

**Diagnostic, dans l'ordre** :

```bash
# 1. Le container WordPress tourne-t-il ?
podman ps --filter "name=wordpress"

# 2. Le port est-il bien exposé sur l'hôte ?
ss -tlnp | grep -E ':(8000|8080)'

# 3. Apache répond-il depuis le WSL2 ?
curl -sI http://localhost:8000/

# 4. Quel port le compose expose-t-il vraiment ?
grep -E 'WP_PORT|ports:' docker-compose.yml
grep -E '^WP_PORT' .env
```

**Causes fréquentes** :

- Tu tapes la mauvaise URL (ex : `localhost:8080` alors que `WP_PORT=8000`). Le port par défaut du projet est 8000.
- Tu testes depuis Windows mais Podman tourne dans WSL2. Le forwarding `localhost` Windows → WSL2 fonctionne en général, mais peut être cassé selon la version de WSL2. Test alternatif : depuis Windows, utilise l'IP WSL2 directement (`http://172.20.x.x:8000`, IP qui change à chaque redémarrage de WSL2 — récupère-la avec `ip addr show eth0`).
- Tu confonds `https` et `http`. En local on utilise toujours `http`.

### 4.2. MariaDB redémarre en boucle

**Symptômes** : `podman ps` montre `db` en `Up 30 seconds (starting)` qui reset périodiquement, ou en `Restarting`. WordPress affiche « Error establishing a database connection ».

**Diagnostic** :

```bash
# Lecture des logs MariaDB
podman logs --tail 50 portfolio-md_db_1
```

**Causes fréquentes** :

- **Conflit de stacks parallèles** (cf. §2). Cherche `error: 11` ou `Unable to lock ./ibdata1` dans les logs. Suis la procédure de §2.4.
- **Permissions cassées sur `BDD_data/`**. Si tu as fait un `chmod` ou `chown` sur le dossier depuis l'hôte sans comprendre les UID du container, MariaDB ne peut plus lire ses fichiers. Fix : remettre les bonnes permissions, ou en dernier recours purger `BDD_data/` (perte de données).
- **Filesystem qui ne supporte pas les locks fcntl**. Cas typique : si tu mets `BDD_data` sur un partage réseau ou sur `/mnt/c/...` (NTFS via WSL2). Solution : garder le dossier sur le filesystem ext4 natif de la distrib WSL2 (ce que fait le projet par défaut).

### 4.3. `bootstrap.sh` échoue

**Symptômes** : le script s'arrête avec une erreur, ou n'arrive jamais à `wp core install`.

**Diagnostic** :

```bash
# Vérifier que .env contient toutes les variables attendues
grep -E '^(DB_|WP_)' .env | cut -d= -f1
# Doit lister AU MINIMUM :
# DB_ROOT_PASSWORD, DB_NAME, DB_USER, DB_PASSWORD,
# WP_PORT, WP_ADMIN_USER, WP_ADMIN_PASSWORD,
# WP_ADMIN_EMAIL, WP_SITE_TITLE, WP_SITE_URL

# Vérifier que la stack tourne avant de lancer le script
podman-compose ps
```

**Causes fréquentes** :

- `.env` incomplet : il manque `WP_SITE_URL`, `WP_ADMIN_EMAIL`, etc. Le `set -u` du script fait sauter à la première variable manquante.
- WordPress n'est pas encore healthy (`db` met du temps à initialiser au tout premier démarrage). Le script attend déjà 60 secondes mais ce n'est parfois pas assez sur une machine lente. Solution : relancer le script — il est idempotent.
- La stack a été créée avec `podman compose` (espace) et le script utilise `podman-compose` (tiret). Cf. §2.

### 4.4. `wpcli` ne « voit » pas le plugin

**Symptômes** : `wp plugin list` ne montre pas `portfolio-md`, ou `wp plugin activate portfolio-md` répond « plugin not found ».

**Diagnostic** :

```bash
# Vérifier que le bind mount est bien monté DANS le container wpcli
podman-compose --profile tools run --rm wpcli ls -la /var/www/html/wp-content/plugins/

# Vérifier que le fichier d'entrée du plugin existe
ls -la plugin/portfolio-md.php
```

**Causes fréquentes** :

- Tu as lancé une stack alternative (cf. §2) qui n'a pas le même bind mount. Le `wpcli` de la « bonne » stack ne voit pas ton dossier `plugin/`.
- Le fichier `plugin/portfolio-md.php` (ou son équivalent) n'a pas l'en-tête WordPress requis (`Plugin Name:`, etc.). Sans cet en-tête, WordPress n'identifie pas le dossier comme un plugin valide.

---

## 5. Pour aller plus loin

- [Documentation officielle WP-CLI](https://wp-cli.org/) — toutes les commandes.
- [Image officielle WordPress sur Docker Hub](https://hub.docker.com/_/wordpress) — variables d'environnement disponibles.
- [Compose profiles spec](https://docs.docker.com/compose/profiles/) — spec officielle du mécanisme `profiles`.
- [`docs/00-overview.md`](../architecture/00-overview.md) — vue d'ensemble du projet et placement de la stack containers dans l'architecture globale.
- [`README.md`](../../README.md) — instructions de démarrage rapide.

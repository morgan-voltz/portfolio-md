# Déploiement — un domaine, trois hôtes

**Fichier** : `docs/architecture/04-deployment.md`
**Rôle** : le document que `00-overview.md` §6 promettait pour la phase onze : où tourne chaque composant, comment on l'y met, quels secrets existent et où ils vivent, dans quel ordre on déploie la première fois
**Prérequis** : avoir lu `00-overview.md` ; pour WordPress, le runbook `docs/guides/02-exploitation-site-trilingue.md`
**Lectorat** : Morgan, seul à tenir le clavier en production ; un relecteur qui veut savoir ce qui est en ligne et comment
**Statut** : Accepté avec ADR-023 (8 novembre 2026) ; complété à chaque pas réel du premier déploiement (WordPress installé le 8 novembre 2026, en attente du DNS)
**Dernière mise à jour** : 8 novembre 2026

Ce dépôt est public : aucun identifiant, aucune adresse IP, aucun nom de serveur, aucun secret n'y figure. Ce document dit **où** chaque chose vit et **comment** on y va, pas **avec quoi** on s'y connecte.

---

## 1. Les trois hôtes et le domaine

| Hôte | Composant | Où | Sert | Certificat |
|---|---|---|---|---|
| `voltz.dev` (et `www`, qui redirige) | Site WordPress (plugin `portfolio-md`, thème enfant, Polylang, Yoast) | o2switch, hébergement mutualisé | les trois langues sous `/fr/`, `/de/`, `/en/`, l'API REST du plugin sous `/wp-json/portfolio/v1/` | Let's Encrypt géré par cPanel |
| `api.voltz.dev` | API C# `Portfolio.Api` | VPS Kovelt (Hetzner), conteneur derrière Traefik v3 | `/api/*`, `/health/*` ; `/metrics` n'existe que sur le port interne 9090, que rien ne route | Let's Encrypt géré par Traefik |
| `app.voltz.dev` | Front React | Cloudflare Pages | le portfolio technique, qui ne parle qu'à `api.voltz.dev` | géré par Cloudflare |

La zone DNS de `voltz.dev` est chez Cloudflare (ADR-023), avec `A voltz.dev` et `CNAME www` vers o2switch (sans proxy), `A api` vers le VPS (sans proxy : Traefik fait le TLS), `CNAME app` vers Cloudflare Pages ; aucun enregistrement `AAAA` tant que le VPS n'est pas testé en IPv6. Le domaine est parké au 8 novembre 2026 ; poser ces enregistrements est le premier pas de la phase onze.

## 2. WordPress chez o2switch

**Fait le 8 novembre 2026** : WordPress 7.1.4 installé par WP-CLI dans le dossier racine du domaine additionnel, les trois langues, la grille, les 54 documents (langues allemande et anglaise fermées), la configuration du site ; le certificat et la recette attendent le DNS. Tout est dans le runbook `docs/guides/02-exploitation-site-trilingue.md`, partie 2 : prérequis (PHP 8.5, la version d'o2switch confirmée le 8 novembre 2026, le plugin exigeant 8.2 ou plus, la stack locale alignée le même jour ; sous cette version Yoast 28.8 journalise six dépréciations par page, sans effet, runbook partie 5 ; WordPress 7.1.4, Polylang 3.9.1, Yoast 28.8, GeneratePress 3.6.1, WP-CLI par SSH), dépôt du plugin **construit** (`composer install --no-dev`, bundle de l'éditeur) et du thème enfant, langues, grille et contenu, `site-setup.php`, test de fumée Yoast, recette, puis ouverture d'une langue quand sa grille est verte. `WP_ENVIRONMENT_TYPE` reste `production` : Yoast ne persiste ses indexables qu'ainsi.

La porte Git (ADR-019) s'active avec trois secrets GitHub, `PORTFOLIO_SYNC_URL`, `PORTFOLIO_SYNC_USER`, `PORTFOLIO_SYNC_PASSWORD` (un mot de passe d'application WordPress, affiché une fois, collé directement dans le secret) ; le premier import passe par un lancement manuel du workflow, ensuite chaque push sur `main` qui touche `content/` synchronise la différence.

## 3. L'API sur le VPS Kovelt

### 3.1. L'image

Construite depuis `api/` par `api/deploy/Dockerfile` (`api/.dockerignore` tient les `bin/` et `obj/` de la machine hors du contexte) : étage SDK .NET 10 (restauration sur les fichiers de projet seuls, puis `dotnet publish` en Release), étage d'exécution `aspnet:10.0`, utilisateur non root de l'image, écoute sur `8080` (l'API) et `9090` (`/metrics`, §3.4), pas de `HEALTHCHECK` (la santé se lit de l'extérieur). Publiée sur GHCR sous `ghcr.io/morgan-voltz/portfolio-api:X.Y.Z` par une action GitHub de ce dépôt déclenchée par un tag `api-vX.Y.Z` (le dépôt tient trois composants : un préfixe par composant). **Le paquet GHCR est rendu public une fois, après la première publication** : un paquet créé par une action naît privé, et le `pull` du serveur échouerait sinon ; l'image ne contient aucun secret, et le label `org.opencontainers.image.source` la relie au dépôt. Le workflow s'ajoute quand la solution de la phase six existe ; sa forme :

```yaml
# .github/workflows/release-api.yml (à ajouter avec la phase six)
name: Release API image
on:
  push:
    tags: ['api-v*']
permissions:
  contents: read
  packages: write
jobs:
  image:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@<sha épinglé>
      - uses: docker/login-action@<sha épinglé>
        with: { registry: ghcr.io, username: ${{ github.actor }}, password: ${{ secrets.GITHUB_TOKEN }} }
      - id: version
        # Un pas shell : les entrées `with:` ne sont pas évaluées par un shell, seules les
        # expressions ${{ }} le sont, et aucune ne sait retirer le préfixe du tag.
        run: echo "version=${GITHUB_REF_NAME#api-v}" >> "$GITHUB_OUTPUT"
      - uses: docker/build-push-action@<sha épinglé>
        with:
          context: api
          file: api/deploy/Dockerfile
          push: true
          tags: ghcr.io/morgan-voltz/portfolio-api:${{ steps.version.outputs.version }}
```

### 3.2. Le service sur le serveur

Un dossier par service, comme les autres services Kovelt : `/opt/nuvoracode/portfolio-api/` tient `docker-compose.yml` (copie de `api/deploy/docker-compose.prod.yml`, versionnée aussi dans le dépôt d'infrastructure) et `.env` (mode 600, jamais commité ; les clés sont dans `api/deploy/.env.example`). L'image y est épinglée **par tag et par digest** (le gabarit porte un digest factice qui fait échouer `compose config` tant qu'il n'est pas remplacé) ; aucun port n'est publié sur l'hôte ; Traefik route `api.voltz.dev` par labels, émet le certificat, sonde `/health/ready` ; les en-têtes transmis sont lus par l'API ; `no-new-privileges`, toutes les capacités retirées, racine en lecture seule, 256 Mo et 256 processus au plus, `restart: unless-stopped`.

### 3.3. Déployer une version

À la main, par SSH, chaque pas relu :

1. `docker pull ghcr.io/morgan-voltz/portfolio-api:X.Y.Z` (le VPS tourne sous Docker, Traefik lit ses labels par le fournisseur Docker ; `podman` ne sert qu'à la construction locale), puis lire le digest : `docker inspect ghcr.io/morgan-voltz/portfolio-api:X.Y.Z --format '{{index .RepoDigests 0}}'`.
2. `cp docker-compose.yml docker-compose.yml.bak-avant-X.Y.Z`, remplacer la ligne `image:` par le tag@digest.
3. `docker compose config -q` (le fichier est valide), puis `docker compose up -d portfolio-api`.
4. Vérifier : `docker logs portfolio-api` (version, « prêt »), `curl https://api.voltz.dev/health/ready` (200), un `GET /api/projects?language=fr` (200, la liste), `https://api.voltz.dev/metrics` et `/Metrics` depuis l'extérieur (404 : la route n'existe que sur le port interne), et depuis le VPS `docker exec` d'un conteneur du réseau vers `portfolio-api:9090/metrics` (200).
5. Mettre à jour la ligne « déployé » du `CLAUDE.md` du serveur et du dépôt d'infrastructure ; commiter le compose dans le dépôt d'infrastructure (config seule).

Retour arrière : remettre la copie de sauvegarde du compose et `up -d`. L'API n'a pas de base de données : rien à migrer, rien à restaurer ; le cache se reconstruit depuis WordPress au démarrage.

### 3.4. Santé et observation

`GET /health/live` répond dès que le processus tourne ; `GET /health/ready` répond 503 jusqu'à la première lecture réussie du `/sitemap` du plugin, puis 200, et le reste : une panne ultérieure de WordPress fait servir le cache tel quel plutôt que couper l'API. Traefik sonde `/health/ready` et retire le conteneur du routage quand il échoue (avec une seule réplique, un démarrage pendant une panne de WordPress donne des 503 jusqu'à son retour : accepté, Uptime Kuma le dit) ; Uptime Kuma surveille `https://api.voltz.dev/health/ready` et alerte. Prometheus (déjà sur le VPS) scrute `portfolio-api:9090/metrics` sur le réseau interne : `/metrics` n'est servi que sur ce port, qu'aucun label ne route, de sorte que le nom public ne le sert jamais, en minuscules comme en majuscules. Les journaux vont sur la sortie standard (`docker logs`).

### 3.5. Configuration

Par l'environnement, préfixe `PORTFOLIO__` (patron Options, `__` = séparateur de section, une liste en clés indexées) : `PORTFOLIO__WORDPRESSBASEURL`, la racine des routes du plugin avec son chemin (`https://voltz.dev/wp-json/portfolio/v1/`) ; `PORTFOLIO__ALLOWEDORIGINS__0`, `__1`… (`https://app.voltz.dev` ; `localhost` en développement ; en production l'API refuse de démarrer sans origine) ; `PORTFOLIO__CACHE__SITEMAPREFRESHSECONDS`. Deux réglages d'ASP.NET Core dans le compose : `ASPNETCORE_HTTP_PORTS=8080;9090` et `ASPNETCORE_FORWARDEDHEADERS_ENABLED=true`, pour que la limitation de débit et les journaux voient le client et non Traefik. Aucun secret aujourd'hui ; le jour où il y en a un, il va dans le `.env` du serveur.

## 4. Le front sur Cloudflare Pages

Projet Pages relié au dépôt GitHub, dossier racine `frontend/`, commande `npm run build`, sortie `dist/`, variable de build `VITE_API_URL=https://api.voltz.dev`, domaine personnalisé `app.voltz.dev`. Chaque pull request reçoit une URL d'aperçu ; `main` déploie la production. Détails et alternatives : `03-frontend-react.md` §9.

## 5. Inventaire des secrets et des accès

| Quoi | Où il vit | Où il ne vit jamais |
|---|---|---|
| Identifiants cPanel et SSH o2switch | gestionnaire de mots de passe de Morgan | le dépôt, les docs |
| Clé SSH du VPS | `~/.ssh` de Morgan | le dépôt, une action GitHub |
| Mot de passe d'application WordPress de la porte Git | secret GitHub `PORTFOLIO_SYNC_PASSWORD`, créé par `wp user application-password create <login> <nom> --porcelain` | un fichier, un message |
| `.env` de l'API | `/opt/nuvoracode/portfolio-api/.env`, mode 600 | le dépôt d'infrastructure (config seule), ce dépôt |
| `GITHUB_TOKEN` de l'action de release | fourni par GitHub à l'exécution | nulle part |
| Lecture du paquet GHCR depuis le VPS | aucun secret : le paquet est public (ADR-023) ; s'il devait redevenir privé, un jeton `read:packages` dans la configuration Docker du serveur | le dépôt, l'action |
| Jeton Cloudflare | l'interface Cloudflare (intégration GitHub de Pages) | le dépôt |

## 6. Ordre du premier déploiement

1. La zone DNS chez Cloudflare et ses quatre enregistrements (§1). *(À faire ; les deux premiers enregistrements suffisent au site.)*
2. WordPress : runbook partie 2, puis recette partie 4 sur `https://voltz.dev`. *(Installation faite le 8 novembre 2026, avant le DNS : WP-CLI n'a pas besoin que le nom résolve ; AutoSSL et la recette, si.)*
3. L'API, quand la phase six existe : tag `api-v0.1.0`, image, dossier du service, `.env`, premier `up -d`, vérifications du §3.3, moniteur Uptime Kuma.
4. Le front, quand la phase sept existe : projet Pages, variable, domaine.
5. L'ouverture des langues, une à la fois, quand leur grille est verte (runbook partie 3).

## 7. Ce qui reste à décider ou à compléter

- Le proxy et le cache de Cloudflare devant WordPress : après la mise en ligne et la recette des langues, pas avant.
- ~~Le chemin du dépôt sur le serveur o2switch et le login WP-CLI~~ : tranchés le 8 novembre 2026 (runbook, partie 2.3 : la racine du domaine additionnel, un dossier de contenu hors de la racine web, le compte d'administration pour les imports).
- La version de PHP du vhost dans MultiPHP Manager, à régler explicitement sur 8.5 ; le certificat AutoSSL et le mot de passe d'administration, dès que le DNS pointe.
- Les images « chiseled » de .NET pour l'API : après quelques semaines de production.
- Ce document est complété à chaque pas réel : ce qu'on a tapé, ce qu'on a vu.

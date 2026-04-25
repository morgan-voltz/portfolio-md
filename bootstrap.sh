#!/usr/bin/env bash
# bootstrap.sh — installation et configuration automatique de WordPress
#
# Ce script utilise WP-CLI pour mettre WordPress dans son état « initial propre »
# défini par l'architecture du projet. Il est idempotent : on peut le relancer
# sans craindre de tout casser. Il vérifie ce qui est déjà fait avant de faire.
#
# Usage :
#   ./bootstrap.sh
#
# Pré-requis :
#   - Docker Compose en cours d'exécution (docker compose up -d)
#   - Fichier .env présent à la racine
#
# À chaque fois que tu changes de machine, ou que tu veux remettre WordPress
# à zéro, il suffit de :
#   1. docker compose down -v   (purge tout)
#   2. rm -rf BDD_data wordpress_data   (purge les bind mounts)
#   3. docker compose up -d
#   4. ./bootstrap.sh

set -euo pipefail
# set -e : sortir au premier échec
# set -u : erreur sur variable non définie
# set -o pipefail : la sortie d'un pipe est l'échec si un maillon échoue

# ─── Couleurs pour la lisibilité ──────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info()    { echo -e "${BLUE}[INFO]${NC}  $1"; }
log_success() { echo -e "${GREEN}[OK]${NC}    $1"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC}  $1"; }

# ─── Vérification des pré-requis ──────────────────────
if [ ! -f .env ]; then
    log_warn ".env manquant. Copie de .env.example en .env."
    cp .env.example .env
    log_warn "Édite .env avec tes valeurs avant de relancer ce script."
    exit 1
fi

# Charger les variables d'environnement
# shellcheck disable=SC1091
set -a; . ./.env; set +a

# ─── Helper : exécuter une commande WP-CLI ────────────
# On utilise `docker compose run --rm wpcli` pour invoquer WP-CLI sur
# l'installation montée dans le volume.
wp() {
    docker compose --profile tools run --rm -T wpcli wp "$@"
}

# ─── Étape 1 : attendre que WordPress soit prêt ───────
log_info "Attente que WordPress soit prêt sur ${WP_SITE_URL}..."
for i in {1..30}; do
    if curl -sSf -o /dev/null "${WP_SITE_URL}"; then
        log_success "WordPress répond."
        break
    fi
    if [ "$i" -eq 30 ]; then
        log_warn "WordPress ne répond toujours pas. Vérifie 'docker compose ps'."
        exit 1
    fi
    sleep 2
done

# ─── Étape 2 : installer WordPress si nécessaire ──────
if wp core is-installed 2>/dev/null; then
    log_success "WordPress est déjà installé."
else
    log_info "Installation de WordPress..."
    wp core install \
        --url="${WP_SITE_URL}" \
        --title="${WP_SITE_TITLE}" \
        --admin_user="${WP_ADMIN_USER}" \
        --admin_password="${WP_ADMIN_PASSWORD}" \
        --admin_email="${WP_ADMIN_EMAIL}" \
        --skip-email
    log_success "WordPress installé."
fi

# ─── Étape 3 : configurer les permaliens ──────────────
# On utilise une structure /%postname%/ qui produit des URLs propres
# du type /mon-article/ au lieu de /?p=42.
log_info "Configuration des permaliens..."
wp rewrite structure '/%postname%/' --hard
log_success "Permaliens en mode 'nom de l'article'."

# ─── Étape 4 : désactiver les commentaires ────────────
log_info "Désactivation des commentaires (inutiles pour un portfolio)..."
wp option update default_comment_status 'closed'
wp option update default_ping_status 'closed'
# Désactiver les commentaires sur tous les contenus existants
wp post list --format=ids --post_type=any | xargs -r -n1 -I{} wp post update {} --comment_status=closed --ping_status=closed >/dev/null 2>&1 || true
log_success "Commentaires désactivés."

# ─── Étape 5 : nettoyer les contenus de démo ──────────
log_info "Suppression des contenus de démo (Hello World, page exemple)..."
# `--force` supprime définitivement (sinon ça met en corbeille)
wp post delete 1 --force 2>/dev/null || true   # Hello World
wp post delete 2 --force 2>/dev/null || true   # Page exemple
wp post delete 3 --force 2>/dev/null || true   # Politique de confidentialité
log_success "Contenus de démo supprimés."

# ─── Étape 6 : configurer la timezone et la langue ────
log_info "Configuration timezone et langue..."
wp option update timezone_string 'Europe/Paris'
wp option update date_format 'Y-m-d'
wp option update time_format 'H:i'
wp option update start_of_week 1  # Lundi
log_success "Timezone Europe/Paris, format ISO."

# ─── Étape 7 : désactiver les emojis et autres bricoles ───
# WordPress charge par défaut un script d'emojis qui ralentit légèrement.
# Pour un portfolio sobre, on s'en passe.
log_info "Désactivation des emojis et de wp-embed..."
wp option update use_smilies 0
log_success "Emojis désactivés."

# ─── Récapitulatif final ──────────────────────────────
echo ""
log_success "🎉 WordPress est prêt à l'emploi."
echo ""
echo "  URL du site  : ${WP_SITE_URL}"
echo "  Admin URL    : ${WP_SITE_URL}/wp-admin"
echo "  Identifiant  : ${WP_ADMIN_USER}"
echo "  Mot de passe : ${WP_ADMIN_PASSWORD}"
echo ""
echo "  Pour relancer ce script proprement :"
echo "    docker compose down -v"
echo "    rm -rf BDD_data wordpress_data"
echo "    docker compose up -d"
echo "    ./bootstrap.sh"
echo ""

#!/usr/bin/env bash
# Acceptance checks of the opening of a language (ADR-022, point 16), as an
# anonymous visitor and a robot see it: with German closed, then opened with
# `wp portfolio languages publish de --force`, then closed again. Since lot 5c
# each page also reports what the theme renders: cards and badges of the
# project archive, languages of the switcher; since lot 5d the meta
# description of each page and where the permalink of the home page goes. Only GET
# requests on the site plus the two commands; the option is put back as it
# was. Run from the repository root with the stack up; prints observations,
# compare them with the expectations written in the lot 5b to 5d notes of
# ADR-022 (docs/architecture/99-decisions.md).
#
#   plugin/tools/opening-check.sh [base-url]        (default http://localhost:8000)
#
# Needs curl, python3 and podman-compose; the helpers are in lib/observe.sh. Replay
# it after any update of Polylang, Yoast or GeneratePress; the full rehearsal of an
# opening with translated fixtures is opening-rehearsal.sh.
set -u
BASE=${1:-http://localhost:8000}
BASE=${BASE%/}
for tool in curl python3 podman-compose; do command -v "$tool" >/dev/null || { echo "missing: $tool" >&2; exit 1; }; done
curl -s -o /dev/null "$BASE/" || { echo "no answer from $BASE" >&2; exit 1; }

HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=lib/observe.sh
. "$HERE/lib/observe.sh"
cd "$HERE/../.." || exit 1      # podman-compose needs the compose file of the repository root
wp_answers || { echo "WP-CLI does not answer: is the stack up (podman-compose up -d)?" >&2; exit 1; }

# The option as JSON (a line starting with `[`), empty when the option does not exist.
before=$(wp option get portfolio_md_published_languages --format=json | grep -E '^\[' | head -1)
echo "published languages before: ${before:-(option absent)}"
# Put the option back whatever happens, a Ctrl-C in the middle of an observation included.
restore() {
  if [ -n "$before" ]; then wp option update portfolio_md_published_languages "$before" --format=json >/dev/null; else wp option delete portfolio_md_published_languages >/dev/null; fi
  after=$(wp option get portfolio_md_published_languages --format=json | grep -E '^\[' | head -1)
  echo
  echo "published languages after: ${after:-(option absent)}"
}
trap restore EXIT

observe() {
  section "$1"
  printf '   Accept-Language de on /          → %s\n' "$(status_and_target -H 'Accept-Language: de' "$BASE/")"
  printf '   Cookie pll_language=de on /      → %s\n' "$(status_and_target -H 'Cookie: pll_language=de' "$BASE/")"
  printf '   /fr/accueil/ (expected 301 → /fr/) → %s\n' "$(status_and_target "$BASE/fr/accueil/")"
  for u in /fr/ /de/ /fr/projets/ /de/projets/ /fr/projets/momocad/ /fr/projets/page/2/; do printf '   %-28s %s\n' "$u" "$(code "$BASE$u")"; head_tags "$BASE$u"; theme_tags "$BASE$u"; done
  printf '   project-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" '/de/')"
  printf '   page-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/page-sitemap.xml" '/de/')"
}

wp portfolio languages unpublish de >/dev/null
observe "1. German closed: unannounced, noindex, out of the sitemap"
wp portfolio languages publish de --force | sed 's/^/   /'
observe "2. German open (forced): announced where content exists, indexable, in the sitemap"
wp portfolio languages unpublish de >/dev/null
observe "3. German closed again"
# The EXIT trap restores the option and prints it.

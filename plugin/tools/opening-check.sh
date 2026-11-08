#!/usr/bin/env bash
# Acceptance checks of the opening of a language (ADR-022, point 16), as an
# anonymous visitor and a robot see it: with German closed, then opened with
# `wp portfolio languages publish de --force`, then closed again. Since lot 5c
# each page also reports what the theme renders: cards and badges of the
# project archive, languages of the switcher. Only GET
# requests on the site plus the two commands; the option is put back as it
# was. Run from the repository root with the stack up; prints observations,
# compare them with the expectations in docs/architecture/01-plugin-php.md §7.1.
#
#   plugin/tools/opening-check.sh [base-url]        (default http://localhost:8000)
#
# Needs curl, python3 and podman-compose. Replay it after any update of
# Polylang, Yoast or GeneratePress.
set -u
BASE=${1:-http://localhost:8000}
BASE=${BASE%/}
for tool in curl python3 podman-compose; do command -v "$tool" >/dev/null || { echo "missing: $tool" >&2; exit 1; }; done
curl -s -o /dev/null "$BASE/" || { echo "no answer from $BASE" >&2; exit 1; }

# podman-compose prints the container names and ids and a TTY warning around the real output.
wp() { podman-compose --profile tools run --rm wpcli wp "$@" 2>/dev/null | grep -v -E '^($|[0-9a-f]{64}$|portfolio-md_|time=)'; }
section() { printf '\n── %s\n' "$1"; }
code()    { curl -s -o /dev/null -w '%{http_code}' "$@"; }
location(){ curl -s -o /dev/null -D - "$@" | tr -d '\r' | grep -i '^location:' | sed 's/^[^:]*: *//'; }
status_and_target() { local c; c=$(code "$@"); local l; l=$(location "$@"); printf '%s%s' "$c" "${l:+  → $l}"; }
head_tags() {
  local b; b=$(curl -s "$1")
  local hl; hl=$(grep -o '<link rel="alternate" href="[^"]*" hreflang="[^"]*"' <<<"$b" | sed -E 's/.*href="([^"]*)" hreflang="([^"]*)"/\2→\1/' | tr '\n' ' ')
  local rob; rob=$(grep -o "<meta name='robots' content='[^']*'" <<<"$b" | head -1 | sed "s/.*content=//")
  printf '      hreflang: %s\n      robots: %s\n' "${hl:-none}" "${rob:-none}"
}
sitemap_locs() { curl -s "$1" | grep -o '<loc>[^<]*</loc>' | sed 's/<\/*loc>//g' | grep -E "$2" | tr '\n' ' '; }

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

# What the theme renders on a page (lot 5c): the cards of the project archive and
# their badges, and the languages of the switcher (empty with a single open language).
theme_tags() {
  local body; body=$(curl -s "$1")
  printf '      cards: %s, badges: %s, switcher: %s\n' \
    "$(printf '%s' "$body" | grep -o '<article id="post-' | wc -l)" \
    "$(printf '%s' "$body" | grep -o 'class="lang-badge"' | wc -l)" \
    "$(printf '%s' "$body" | grep -o '<span class="lang-switcher"[^>]*>.*' | head -1 | grep -o 'lang-item[^"]*"><\(a[^>]*\|span[^>]*\) lang="[a-z]*"' | sed 's/.*lang="\([a-z]*\)"/\1/; s/^/[/; s/$/]/' | tr '\n' ' ' | sed 's/ $//')"
}

observe() {
  section "$1"
  printf '   Accept-Language de on /          → %s\n' "$(status_and_target -H 'Accept-Language: de' "$BASE/")"
  printf '   Cookie pll_language=de on /      → %s\n' "$(status_and_target -H 'Cookie: pll_language=de' "$BASE/")"
  for u in /fr/ /de/ /fr/projets/ /de/projets/ /fr/projets/momocad/; do printf '   %-28s %s\n' "$u" "$(code "$BASE$u")"; head_tags "$BASE$u"; theme_tags "$BASE$u"; done
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

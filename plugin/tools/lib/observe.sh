#!/usr/bin/env bash
# Shared helpers of the HTTP observation scripts (opening-check.sh, opening-rehearsal.sh):
# WP-CLI through the compose stack with its noise filtered, and what a visitor or a robot
# sees on a page (status, redirect, canonical, hreflang, robots, meta description, cards,
# badges, switcher). Source it after setting BASE; needs curl and podman-compose.

# podman-compose prints the container names and ids and a TTY warning around the real output.
wp() { podman-compose --profile tools run --rm wpcli wp "$@" 2>/dev/null | grep -v -E '^($|[0-9a-f]{64}$|portfolio-md_|time=)'; }
section() { printf '\n── %s\n' "$1"; }
code()    { curl -s -o /dev/null -w '%{http_code}' "$@"; }
location(){ curl -s -o /dev/null -D - "$@" | tr -d '\r' | grep -i '^location:' | sed 's/^[^:]*: *//'; }
status_and_target() { local c; c=$(code "$@"); local l; l=$(location "$@"); printf '%s%s' "$c" "${l:+  → $l}"; }
# The head of a page: canonical, hreflang, robots and description as Yoast and Polylang print them.
head_tags() {
  local b; b=$(curl -s "$1")
  local can; can=$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$b" | head -1 | sed 's/.*href="//; s/"$//')
  local hl; hl=$(grep -o '<link rel="alternate" href="[^"]*" hreflang="[^"]*"' <<<"$b" | sed -E 's/.*href="([^"]*)" hreflang="([^"]*)"/\2→\1/' | tr '\n' ' ')
  local rob; rob=$(grep -o "<meta name='robots' content='[^']*'" <<<"$b" | head -1 | sed "s/.*content=//")
  local desc; desc=$(grep -oE "<meta name=[\"']description[\"'] content=[\"'][^\"']*[\"']" <<<"$b" | head -1 | sed -E "s/.*content=[\"']//; s/[\"']$//")
  printf '      canonical: %s\n      hreflang: %s\n      robots: %s\n      description: %s\n' "${can:-none}" "${hl:-none}" "${rob:-none}" "${desc:-none}"
}
sitemap_locs() { curl -s "$1" | grep -o '<loc>[^<]*</loc>' | sed 's/<\/*loc>//g' | grep -E "$2" | tr '\n' ' '; }
# What the theme renders on a page (lot 5c): the cards of a list and their badges, and the
# languages of the switcher (empty with a single open language).
theme_tags() {
  local body; body=$(curl -s "$1")
  printf '      cards: %s, badges: %s, switcher: %s\n' \
    "$(printf '%s' "$body" | grep -o '<article id="post-' | wc -l)" \
    "$(printf '%s' "$body" | grep -o 'class="lang-badge"' | wc -l)" \
    "$(printf '%s' "$body" | grep -o '<span class="lang-switcher"[^>]*>.*' | head -1 | grep -o 'lang-item[^"]*"><\(a[^>]*\|span[^>]*\) lang="[a-z]*"' | sed 's/.*lang="\([a-z]*\)"/\1/; s/^/[/; s/$/]/' | tr '\n' ' ' | sed 's/ $//')"
}

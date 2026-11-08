#!/usr/bin/env bash
# Full rehearsal of the opening of the languages (ADR-022, lot 5e), as an
# anonymous visitor and a robot see it, with a translated sheet in each
# language (the fixtures of tests/Integration/wp-cli/rehearsal-fixtures.php,
# imported first and deleted at the end): everything closed, German opened,
# English opened, both closed again. For each state: where `/` sends a German
# and an English browser, the three versions of the translated sheet
# (canonical, hreflang, robots, description), an untranslated sheet, the
# untranslated tag archive with and without a language prefix, the project
# archive of each language (cards, badges, robots), the sitemaps and the
# switcher. The languages are opened with --force: the fixtures are not a
# full grid. The publication option is put back as it was. Only GET requests
# on the site plus WP-CLI commands. The script places itself at the root of
# the repository (podman-compose needs its compose file) and stops when the
# fixtures do not import. Compare the output with
# docs/architecture/spikes/2026-11-08-repetition-ouverture.md.
#
#   plugin/tools/opening-rehearsal.sh [base-url] [admin login]   (default http://localhost:8000, morgan)
#
# Needs curl and podman-compose; the helpers are in lib/observe.sh.
set -u
BASE=${1:-http://localhost:8000}
BASE=${BASE%/}
ADMIN=${2:-morgan}
for tool in curl podman-compose; do command -v "$tool" >/dev/null || { echo "missing: $tool" >&2; exit 1; }; done
curl -s -o /dev/null "$BASE/" || { echo "no answer from $BASE" >&2; exit 1; }
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=lib/observe.sh
. "$HERE/lib/observe.sh"
cd "$HERE/../.." || exit 1      # podman-compose needs the compose file of the repository root
wp_answers || { echo "WP-CLI does not answer: is the stack up (podman-compose up -d)?" >&2; exit 1; }

FIXTURES=wp-content/plugins/portfolio-md/tests/Integration/wp-cli/rehearsal-fixtures.php
before=$(wp option get portfolio_md_published_languages --format=json | grep -E '^\[' | head -1)
echo "published languages before: ${before:-(option absent)}"
# Close the languages, delete the fixtures and put the option back, whatever happened; a
# second Ctrl-C does not interrupt it, and a failed cleanup says what to run by hand.
restore() {
  trap '' INT
  echo
  echo "── cleanup"
  wp portfolio languages unpublish en >/dev/null
  wp portfolio languages unpublish de >/dev/null
  local out; out=$(wp eval-file "$FIXTURES" cleanup --user="$ADMIN")
  printf '%s\n' "$out" | sed 's/^/   /'
  grep -q 'Success: cleanup' <<<"$out" || echo "   the fixtures may still be there: run \`podman-compose --profile tools run --rm wpcli wp eval-file $FIXTURES cleanup --user=$ADMIN\` by hand" >&2
  if [ -n "$before" ]; then wp option update portfolio_md_published_languages "$before" --format=json >/dev/null; else wp option delete portfolio_md_published_languages >/dev/null; fi
  after=$(wp option get portfolio_md_published_languages --format=json | grep -E '^\[' | head -1)
  echo "published languages after: ${after:-(option absent)}"
}
trap restore EXIT

echo "── fixtures"
setup=$(wp eval-file "$FIXTURES" setup --user="$ADMIN")
printf '%s\n' "$setup" | sed 's/^/   /'
grep -q 'Success: setup' <<<"$setup" || { echo "the fixtures did not import (login \"$ADMIN\"? Polylang active? stack up?): nothing to rehearse" >&2; exit 1; }
wp portfolio languages unpublish de >/dev/null
wp portfolio languages unpublish en >/dev/null

observe() {
  section "$1"
  printf '   Accept-Language de on /          → %s\n' "$(status_and_target -H 'Accept-Language: de' "$BASE/")"
  printf '   Accept-Language en on /          → %s\n' "$(status_and_target -H 'Accept-Language: en' "$BASE/")"
  printf '   Cookie pll_language=de on /      → %s\n' "$(status_and_target -H 'Cookie: pll_language=de' "$BASE/")"
  for u in /fr/projets/zz-rehearsal-project/ /de/projets/zz-rehearsal-project-de/ /en/projets/zz-rehearsal-project-en/ /fr/projets/momocad/; do
    printf '   %-44s %s\n' "$u" "$(code "$BASE$u")"; head_tags "$BASE$u"
  done
  printf '   %-44s %s\n' "/tag/csharp/ (untranslated archive)" "$(code "$BASE/tag/csharp/")"; head_tags "$BASE/tag/csharp/"
  printf '   %-44s %s\n' "/de/tag/csharp/ (expected 404)" "$(code "$BASE/de/tag/csharp/")"
  for u in /fr/ /de/projets/ /en/projets/; do printf '   %-44s %s\n' "$u" "$(code "$BASE$u")"; head_tags "$BASE$u"; theme_tags "$BASE$u"; done
  printf '   project-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" '/de/')"
  printf '   project-sitemap, /en/ URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" '/en/')"
}

observe "0. German and English closed"
wp portfolio languages publish de --force | sed 's/^/   /'
observe "1. German open, English closed"
wp portfolio languages publish en --force | sed 's/^/   /'
observe "2. German and English open"
wp portfolio languages unpublish en >/dev/null
wp portfolio languages unpublish de >/dev/null
observe "3. both closed again"
# The EXIT trap closes the languages, deletes the fixtures and restores the option.

#!/usr/bin/env bash
# HTTP part of the opening spike (lot 5a of ADR-022): what a visitor, a robot
# and Yoast see once tests/Integration/wp-cli/opening-spike.php has set the
# state up (static front page zz-spike-accueil with its German translation,
# redirect_lang=true, a French project with a German twin, two menus bound
# through Polylang's nav_menus, and a must-use plugin that plays the filters
# of a closed language, de and en closed at first). Run the PHP script, then
# `wp rewrite flush --hard`, then this script; it opens `de` halfway through
# (option zz_spike_closed) to observe the flip, and closes it again at the
# end. Nothing here writes anything else; the PHP script's `cleanup` reverts
# the whole state.
#
#   plugin/tools/opening-spike.sh [base-url]        (default http://localhost:8000)
#
# Only GET requests. Needs curl and python3; `wp` is podman-compose's wpcli
# service, run from the repository root.
set -u
BASE=${1:-http://localhost:8000}
BASE=${BASE%/}
for tool in curl python3 podman-compose; do command -v "$tool" >/dev/null || { echo "missing: $tool" >&2; exit 1; }; done
curl -s -o /dev/null "$BASE/" || { echo "no answer from $BASE" >&2; exit 1; }

wp() { podman-compose --profile tools run --rm wpcli wp "$@" 2>/dev/null | grep -v -E '^(\[|$|[0-9a-f]{64}$|portfolio-md_)'; }
section() { printf '\n── %s\n' "$1"; }
code()    { curl -s -o /dev/null -w '%{http_code}' "$@"; }
location(){ curl -s -o /dev/null -D - "$@" | tr -d '\r' | grep -i '^location:' | sed 's/^[^:]*: *//'; }
status_and_target() { local c; c=$(code "$@"); local l; l=$(location "$@"); printf '%s%s' "$c" "${l:+  → $l}"; }
body()    { curl -s "$@"; }
head_tags() { # hreflang / canonical / robots / description of a page, without following redirects
  local b; b=$(body "$1")
  local hl; hl=$(grep -o '<link rel="alternate" href="[^"]*" hreflang="[^"]*"' <<<"$b" | sed -E 's/.*href="([^"]*)" hreflang="([^"]*)"/\2→\1/' | tr '\n' ' ')
  local can; can=$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$b" | head -1 | sed 's/.*href=//')
  local rob; rob=$(grep -o "<meta name='robots' content='[^']*'" <<<"$b" | head -1 | sed "s/.*content=//")
  local desc; desc=$(grep -o '<meta name="description" content="[^"]*"' <<<"$b" | head -1 | sed 's/.*content=//')
  local cls; cls=$(grep -o '<body class="[^"]*"' <<<"$b" | head -1 | grep -o -E '\b(home|blog|page|archive|post-type-archive|single)\b' | tr '\n' ' ')
  printf '      hreflang: %s\n      canonical: %s  robots: %s\n      body: %s%s\n' "${hl:-none}" "${can:-none}" "${rob:-none}" "${cls:-?}" "${desc:+  description: $desc}"
}
switcher() { # the links of the spike switcher and the count of raw elements
  local b; b=$(body "$1")
  local n; n=$(grep -o 'zz-spike-switcher" data-count="[0-9]*"' <<<"$b" | head -1 | grep -o '[0-9]\+')
  local links; links=$(grep -o '<a class="zz-lang[^"]*" href="[^"]*"' <<<"$b" | sed -E 's/<a class="zz-lang zz-lang-([a-z]+)([^"]*)" href="([^"]*)"/\1\2→\3/' | tr '\n' ' ')
  local notices; notices=$(grep -c -i 'doing_it_wrong\|Notice:\|Deprecated:' <<<"$b")
  printf '      elements: %s  shown: %s  notices in body: %s\n' "${n:-none}" "${links:-none}" "$notices"
}
menu_items() { body "$1" | grep -o -E 'menu-item[^>]*><a [^>]*>zz-spike [^<]*' | sed -E 's/.*>(zz-spike [^<]*)/\1/' | tr '\n' '|'; }
sitemap_locs() { body "$1" | grep -o '<loc>[^<]*</loc>' | sed 's/<\/*loc>//g' | grep -E "$2" | tr '\n' ' '; }

section "1. redirect_lang=true with a linked FR/DE home pair (de closed)"
for u in / /fr/ /de/ /en/ /fr/zz-spike-accueil/ /de/zz-spike-startseite/; do
  printf '   %-26s → %s\n' "$u" "$(status_and_target "$BASE$u")"
done
printf '   /fr/ (page served at the language root?)\n'; head_tags "$BASE/fr/"
printf '   /de/ (closed language, translated home)\n'; head_tags "$BASE/de/"
printf '   /en/ (no home page in English)\n'; head_tags "$BASE/en/"

section "2. Browser and cookie preferences on / (de and en closed; de has content)"
for al in "de-CH,de;q=0.9" "en-US,en;q=0.9" "fr-FR,fr;q=0.9"; do
  printf '   Accept-Language %-16s → %s\n' "$al" "$(status_and_target -H "Accept-Language: $al" "$BASE/")"
done
printf '   Cookie pll_language=de        → %s\n' "$(status_and_target -H 'Cookie: pll_language=de' "$BASE/")"
printf '   Cookie pll_language=de + AL de → %s\n' "$(status_and_target -H 'Cookie: pll_language=de' -H 'Accept-Language: de' "$BASE/")"

section "3. Head of the pages while de is closed (translation URLs emptied for de and en)"
for u in /fr/projets/zz-spike-projekt/ /de/projets/zz-spike-projekt-de/ /fr/a-propos/ /de/projets/ /fr/projets/; do
  printf '   %-32s %s\n' "$u" "$(code "$BASE$u")"; head_tags "$BASE$u"
done

section "4. Sitemaps while de is closed (project and page sitemaps, filtered entries and archive blocks)"
printf '   index: %s\n' "$(sitemap_locs "$BASE/sitemap_index.xml" '.')"
printf '   project-sitemap, /de/ or /en/ URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" '/(de|en)/')"
printf '   project-sitemap, archive URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" 'projets/$')"
printf '   project-sitemap, spike sheets: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" 'zz-spike')"
printf '   page-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/page-sitemap.xml" '/de/')"
printf '   page-sitemap, home URLs: %s\n' "$(sitemap_locs "$BASE/page-sitemap.xml" '^[^ ]*/(fr|de|en)/$|zz-spike')"

section "5. The PHP switcher (generate_menu_bar_items, raw elements filtered by the closed list)"
for u in /fr/ /fr/projets/zz-spike-projekt/ /fr/a-propos/ /tag/csharp/; do printf '   %-32s' "$u"; echo; switcher "$BASE$u"; done

section "6. Menus per language (nav_menus under the child theme + theme_mod)"
for u in /fr/ /de/ /en/; do printf '   %-6s items: %s\n' "$u" "$(menu_items "$BASE$u")"; done

section "7. Yoast meta description written by PHP"
printf '   /fr/projets/zz-spike-projekt/\n'; head_tags "$BASE/fr/projets/zz-spike-projekt/"

section "8. Opening de (zz_spike_closed = [en]) and observing the flip"
wp option update zz_spike_closed '["en"]' --format=json >/dev/null && wp eval 'PLL()->model->clean_languages_cache(); if (class_exists("WPSEO_Sitemaps_Cache")) { WPSEO_Sitemaps_Cache::clear(); }' >/dev/null
for al in "de-CH,de;q=0.9" "en-US,en;q=0.9"; do printf '   Accept-Language %-16s → %s\n' "$al" "$(status_and_target -H "Accept-Language: $al" "$BASE/")"; done
printf '   Cookie pll_language=de        → %s\n' "$(status_and_target -H 'Cookie: pll_language=de' "$BASE/")"
for u in /fr/ /de/ /fr/projets/zz-spike-projekt/ /de/projets/zz-spike-projekt-de/ /de/projets/ /fr/a-propos/; do
  printf '   %-32s %s\n' "$u" "$(code "$BASE$u")"; head_tags "$BASE$u"
done
printf '   project-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" '/de/')"
printf '   page-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/page-sitemap.xml" '/de/')"
for u in /fr/ /fr/projets/zz-spike-projekt/ /fr/a-propos/; do printf '   switcher on %-24s' "$u"; echo; switcher "$BASE$u"; done

section "9. Closing de again (zz_spike_closed = [de, en]) : the same requests flip back"
wp option update zz_spike_closed '["de","en"]' --format=json >/dev/null && wp eval 'PLL()->model->clean_languages_cache(); if (class_exists("WPSEO_Sitemaps_Cache")) { WPSEO_Sitemaps_Cache::clear(); }' >/dev/null
printf '   Accept-Language de            → %s\n' "$(status_and_target -H 'Accept-Language: de' "$BASE/")"
printf '   /fr/projets/zz-spike-projekt/\n'; head_tags "$BASE/fr/projets/zz-spike-projekt/"
printf '   project-sitemap, /de/ URLs: %s\n' "$(sitemap_locs "$BASE/project-sitemap.xml" '/de/')"
printf '\nDone. Revert everything with: wp eval-file wp-content/plugins/portfolio-md/tests/Integration/wp-cli/opening-spike.php cleanup --user=<admin login>\n'

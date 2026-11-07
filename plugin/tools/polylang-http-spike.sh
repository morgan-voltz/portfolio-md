#!/usr/bin/env bash
# HTTP part of the Polylang spike (lot 0 of ADR-020): what a visitor and the
# API consumers see once Polylang is configured by tests/Integration/wp-cli/
# polylang-spike.php (languages fr/de/en, directory mode, browser detection,
# the zz-spike posts: the DE copy `momocad-de` of the FR sheet `momocad`, a DE
# page inserted as `cv`). Read-only: it only sends GET requests.
#
#   plugin/tools/polylang-http-spike.sh [base-url]      (default http://localhost:8000)
set -u
BASE=${1:-http://localhost:8000}

section() { printf '\n── %s\n' "$1"; }
code()    { curl -s -o /dev/null -w '%{http_code}' "$@"; }
headers() { curl -s -o /dev/null -D - "$@" | tr -d '\r' | grep -i -E '^(HTTP/|location:|set-cookie:|vary:)' | sed 's/^/      /'; }
total()   { curl -s -o /dev/null -D - "$1" | tr -d '\r' | grep -i '^x-wp-total:' | sed 's/^[^:]*: *//'; }
count_json() { python3 -I -c 'import json,sys; d=json.load(sys.stdin); print(len(d) if isinstance(d,list) else d)'; }

section "Home: redirection by browser language and cookie (browser option on, visible default)"
for al in "de-CH,de;q=0.9,fr;q=0.5" "en-US,en;q=0.8" "fr-FR,fr;q=0.9" "xx-XX" ""; do
  printf '   Accept-Language: %-24s\n' "${al:-(none)}"
  if [ -n "$al" ]; then headers -H "Accept-Language: $al" "$BASE/"; else headers "$BASE/"; fi
done
printf '   Cookie pll_language=en, Accept-Language: de\n'; headers -H 'Cookie: pll_language=en' -H 'Accept-Language: de' "$BASE/"
printf '   Googlebot UA, Accept-Language: de\n'; headers -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' -H 'Accept-Language: de' "$BASE/"

section "Language homes and the prefix-less URLs"
for u in /fr/ /de/ /en/ /projets/momocad/ /fr/projets/momocad/ /de/projets/momocad-de/ /projets/momocad-de/ /de/projets/momocad/ /en/projets/momocad/ /cv/ /fr/cv/ /de/cv-2/ /de/lebenslauf/; do
  printf '   %-26s → %s' "$u" "$(code "$BASE$u")"
  loc=$(curl -s -o /dev/null -D - "$BASE$u" | tr -d '\r' | grep -i '^location:' | sed 's/^[^:]*: *//'); [ -n "$loc" ] && printf '  → %s' "$loc"; echo
done

section "hreflang and canonical on the pages (Polylang + Yoast together)"
for u in /fr/projets/momocad/ /de/projets/momocad-de/ /fr/projets/atlas/ /fr/cv/ /de/cv-2/ /fr/ /de/; do
  body=$(curl -s -L "$BASE$u")
  hl=$(grep -o 'hreflang="[^"]*"' <<<"$body" | sort | uniq -c | awk '{printf "%s×%s ", $2, $1}')
  nlinks=$(grep -c '<link rel="alternate"' <<<"$body" || true)
  can=$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$body" | head -1 | sed 's/<link rel="canonical" href=//')
  yoast=$(grep -c 'This site is optimized with the Yoast' <<<"$body" || true)
  printf '   %-24s hreflang: %-40s alternate links: %-3s canonical: %s  yoast-block: %s\n' "$u" "${hl:-none}" "$nlinks" "${can:-none}" "$yoast"
done

section "REST, plugin routes: lang (Polylang's parameter) versus language (ours, not yet implemented)"
for q in "" "?lang=de" "?lang=fr" "?lang=xx" "?language=de"; do
  printf '   /portfolio/v1/projects%-14s → X-WP-Total %s\n' "$q" "$(total "$BASE/wp-json/portfolio/v1/projects$q")"
done
for u in /projects/momocad /projects/momocad?lang=de /projects/momocad?lang=fr /projects/momocad-de /projects/momocad-de?lang=de /pages/cv?lang=de; do
  printf '   /portfolio/v1%-28s → %s\n' "$u" "$(code "$BASE/wp-json/portfolio/v1$u")"
done
printf '   /portfolio/v1/sitemap projects: %s   ?lang=de: %s\n' \
  "$(curl -s "$BASE/wp-json/portfolio/v1/sitemap" | python3 -I -c 'import json,sys; print(len(json.load(sys.stdin)["projects"]))')" \
  "$(curl -s "$BASE/wp-json/portfolio/v1/sitemap?lang=de" | python3 -I -c 'import json,sys; print(len(json.load(sys.stdin)["projects"]))')"

section "REST, native routes"
for q in "?per_page=100" "?per_page=100&lang=de" "?per_page=100&lang=fr"; do
  printf '   /wp/v2/project%-22s → %s items\n' "$q" "$(curl -s "$BASE/wp-json/wp/v2/project$q" | count_json)"
done

section "Taxonomy archives under a language prefix (tech_tag is not translated)"
tag=$(curl -s "$BASE/wp-json/portfolio/v1/projects/momocad" | python3 -I -c 'import json,sys; d=json.load(sys.stdin); print(d["tags"][0]["slug"] if d.get("tags") else "")')
echo "   tag of momocad: ${tag:-none}"
for u in "/tag/$tag/" "/fr/tag/$tag/" "/de/tag/$tag/" "/en/tag/$tag/" "/fr/projets/" "/de/projets/" "/en/projets/"; do
  body=$(curl -s -L "$BASE$u"); n=$(grep -c '<article ' <<<"$body" || true)
  printf '   %-20s → %s, %s <article>\n' "$u" "$(code "$BASE$u")" "$n"
done

section "Yoast sitemap"
printf '   /sitemap_index.xml → %s\n' "$(code "$BASE/sitemap_index.xml")"
curl -s "$BASE/project-sitemap.xml" | grep -o '<loc>[^<]*</loc>' | head -4 | sed 's/^/   /'
printf '   hreflang in the sitemap: %s\n' "$(curl -s "$BASE/project-sitemap.xml" | grep -c hreflang || true)"

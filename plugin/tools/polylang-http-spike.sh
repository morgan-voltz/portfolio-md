#!/usr/bin/env bash
# HTTP part of the Polylang spike (lot 0 of ADR-020): what a visitor, the API
# consumers and, optionally, an administrator see once Polylang has been
# configured by tests/Integration/wp-cli/polylang-spike.php (languages
# fr/de/en, directory mode, browser detection, and its zz-spike posts: the DE
# copy `momocad-de` of the FR sheet `momocad`, a DE page inserted as `cv`, an
# EN sheet). Run the PHP script first, then `wp rewrite flush --hard`.
#
#   plugin/tools/polylang-http-spike.sh [base-url]        (default http://localhost:8000)
#
# Only GET requests, except the optional administration section: when
# ADMIN_USER and ADMIN_PASSWORD are set in the environment, the script logs
# into wp-login.php (a session cookie, nothing written) to look at the edit
# and list screens of the projects. Needs curl and python3.
set -u
BASE=${1:-http://localhost:8000}
BASE=${BASE%/}
for tool in curl python3; do command -v "$tool" >/dev/null || { echo "missing: $tool" >&2; exit 1; }; done
curl -s -o /dev/null "$BASE/" || { echo "no answer from $BASE" >&2; exit 1; }

section() { printf '\n── %s\n' "$1"; }
code()    { curl -s -o /dev/null -w '%{http_code}' "$@"; }
headers() { curl -s -o /dev/null -D - "$@" | tr -d '\r' | grep -i -E '^(HTTP/|location:|set-cookie:|vary:)' | sed 's/^/      /'; }
total()   { curl -s -o /dev/null -D - "$1" | tr -d '\r' | grep -i '^x-wp-total:' | sed 's/^[^:]*: *//'; }
count_json() { python3 -I -c 'import json,sys
try:
    d = json.load(sys.stdin); print(len(d) if isinstance(d, list) else d)
except Exception as e:
    print("unreadable: " + e.__class__.__name__)'; }
# fetch URL → prints "<status> <final url>" on the first line, then the body
fetch() { curl -s -L --max-redirs 5 -w '\n%{http_code} %{url_effective}' "$1"; }

section "Home: redirection by browser language and cookie (browser option on, visible default)"
for al in "de-CH,de;q=0.9,fr;q=0.5" "en-US,en;q=0.9" "fr-FR,fr;q=0.9" "xx-XX" ""; do
  printf '   Accept-Language: %-24s\n' "${al:-(none)}"
  if [ -n "$al" ]; then headers -H "Accept-Language: $al" "$BASE/"; else headers "$BASE/"; fi
done
printf '   Cookie pll_language=en, Accept-Language: de\n'; headers -H 'Cookie: pll_language=en' -H 'Accept-Language: de' "$BASE/"
printf '   Googlebot UA, Accept-Language: de\n'; headers -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' -H 'Accept-Language: de' "$BASE/"

section "Language homes and the prefix-less URLs (status, then the redirection target if any)"
for u in /fr/ /de/ /en/ /projets/momocad/ /fr/projets/momocad/ /de/projets/momocad-de/ /projets/momocad-de/ /de/projets/momocad/ /en/projets/momocad/ /cv/ /fr/cv/ /de/cv-2/ /en/projets/zz-spike-en/; do
  printf '   %-28s → %s' "$u" "$(code "$BASE$u")"
  loc=$(curl -s -o /dev/null -D - "$BASE$u" | tr -d '\r' | grep -i '^location:' | sed 's/^[^:]*: *//'); [ -n "$loc" ] && printf '  → %s' "$loc"; echo
done

section "hreflang and canonical on the pages (Polylang + Yoast together), after redirections"
for u in /fr/projets/momocad/ /de/projets/momocad-de/ /fr/projets/atlas/ /fr/cv/ /de/cv-2/ /fr/ /de/; do
  resp=$(fetch "$BASE$u"); status=$(tail -n1 <<<"$resp"); body=$(sed '$d' <<<"$resp")
  hl=$(grep -o 'hreflang="[^"]*"' <<<"$body" | sort | uniq -c | awk '{printf "%s:%s ", $2, $1}')
  can=$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$body" | head -1 | sed 's/<link rel="canonical" href=//')
  printf '   %-24s %s\n      hreflang: %s  canonical: %s\n' "$u" "$status" "${hl:-none}" "${can:-none}"
done

section "REST, plugin routes: lang (read by Polylang) versus language (ours, not yet implemented)"
for q in "" "?lang=de" "?lang=fr" "?lang=xx" "?language=de"; do
  printf '   /portfolio/v1/projects%-14s → X-WP-Total %s\n' "$q" "$(total "$BASE/wp-json/portfolio/v1/projects$q")"
done
for u in /projects/momocad /projects/momocad?lang=de /projects/momocad-de /projects/momocad-de?lang=de /pages/cv?lang=de; do
  printf '   /portfolio/v1%-28s → %s\n' "$u" "$(code "$BASE/wp-json/portfolio/v1$u")"
done
printf '   /portfolio/v1/sitemap projects: %s   ?lang=de: %s\n' \
  "$(curl -s "$BASE/wp-json/portfolio/v1/sitemap" | python3 -I -c 'import json,sys
try: print(len(json.load(sys.stdin)["projects"]))
except Exception as e: print("unreadable")')" \
  "$(curl -s "$BASE/wp-json/portfolio/v1/sitemap?lang=de" | python3 -I -c 'import json,sys
try: print(len(json.load(sys.stdin)["projects"]))
except Exception as e: print("unreadable")')"

section "REST, native routes"
for q in "?per_page=100" "?per_page=100&lang=de" "?per_page=100&lang=fr"; do
  printf '   /wp/v2/project%-22s → %s items\n' "$q" "$(curl -s "$BASE/wp-json/wp/v2/project$q" | count_json)"
done

section "Taxonomy archives under a language prefix (tech_tag is not translated; the DE copy carries the tags of momocad)"
tag=$(curl -s "$BASE/wp-json/portfolio/v1/projects/momocad" | python3 -I -c 'import json,sys
try:
    d = json.load(sys.stdin); print(d["tags"][0]["slug"] if d.get("tags") else "")
except Exception: print("")')
if [ -z "$tag" ]; then echo "   no tag found on momocad: section skipped"; else
  echo "   tag of momocad: $tag"
  for u in "/tag/$tag/" "/fr/tag/$tag/" "/de/tag/$tag/" "/en/tag/$tag/" "/fr/projets/" "/de/projets/" "/en/projets/"; do
    resp=$(fetch "$BASE$u"); status=$(tail -n1 <<<"$resp"); body=$(sed '$d' <<<"$resp")
    n=$(grep -c '<article ' <<<"$body" || true); de=$(grep -c 'projets/momocad-de/' <<<"$body" || true)
    printf '   %-20s → %s, %s <article>, links to the DE copy: %s\n' "$u" "$status" "$n" "$de"
  done
  echo "   the prefix-less archive, served in the language of the cookie or of the browser:"
  for v in "" "Accept-Language: de" "Cookie: pll_language=en"; do
    if [ -n "$v" ]; then body=$(curl -s -L -H "$v" "$BASE/tag/$tag/"); else body=$(curl -s -L "$BASE/tag/$tag/"); fi
    printf '   %-28s → %s <article>, links to the DE copy: %s, <html lang>: %s\n' "${v:-(no header)}" "$(grep -c '<article ' <<<"$body" || true)" "$(grep -c 'projets/momocad-de/' <<<"$body" || true)" "$(grep -o '<html[^>]*lang="[^"]*"' <<<"$body" | head -1 | sed 's/.*lang=//')"
  done
fi

section "Yoast sitemap"
printf '   /sitemap_index.xml → %s\n' "$(code "$BASE/sitemap_index.xml")"
curl -s "$BASE/project-sitemap.xml" | grep -o '<loc>[^<]*</loc>' | head -3 | sed 's/^/   /'
printf '   hreflang in the sitemap: %s\n' "$(curl -s "$BASE/project-sitemap.xml" | grep -c hreflang || true)"

if [ -n "${ADMIN_USER:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
  section "Administration (logged in as $ADMIN_USER): what Polylang adds to the screens"
  jar=$(mktemp); trap 'rm -f "$jar"' EXIT
  curl -s -c "$jar" -o /dev/null "$BASE/wp-login.php"
  curl -s -b "$jar" -c "$jar" -o /dev/null --data-urlencode "log=$ADMIN_USER" --data-urlencode "pwd=$ADMIN_PASSWORD" --data 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
  id=$(curl -s "$BASE/wp-json/wp/v2/project?slug=momocad" | python3 -I -c 'import json,sys
try: print(json.load(sys.stdin)[0]["id"])
except Exception: print("")')
  if [ -z "$id" ] || ! curl -s -b "$jar" "$BASE/wp-admin/" | grep -q 'wp-admin-bar-logout'; then echo "   login failed or momocad not found: section skipped"; else
    edit=$(curl -s -b "$jar" "$BASE/wp-admin/post.php?post=$id&action=edit")
    printf '   edit screen of momocad: Polylang box (ml_box): %s  language select: %s  translations table: %s  Markdown box: %s\n' \
      "$(grep -c 'id="ml_box"' <<<"$edit" || true)" "$(grep -c 'post_lang_choice' <<<"$edit" || true)" "$(grep -c 'post_tr_lang' <<<"$edit" || true)" "$(grep -c -i 'Document Markdown' <<<"$edit" || true)"
    list=$(curl -s -b "$jar" "$BASE/wp-admin/edit.php?post_type=project")
    printf '   list screen of projects: language column: %s  quick-edit language select in the template: %s  quick-edit links on the rows: %s\n' \
      "$(grep -c 'column-language_' <<<"$list" || true)" "$(grep -c 'inline_lang_choice' <<<"$list" || true)" "$(grep -c 'editinline' <<<"$list" || true)"
  fi
else
  section "Administration: skipped (set ADMIN_USER and ADMIN_PASSWORD to look at the screens)"
fi

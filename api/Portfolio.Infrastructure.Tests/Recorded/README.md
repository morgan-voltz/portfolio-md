# Recorded answers of the plugin

Real answers of the WordPress plugin in production, which the tests of the client replay instead of calling the site (ADR-026, point 10). Recorded on 2026-11-22 from `https://morganvoltz.dev/wp-json/portfolio/v1`, with the very requests the client sends. Each answer is a pair: `<name>.headers`, the status line and the headers as curl writes them, and `<name>.json`, the body.

| Answer | Request |
|---|---|
| `sitemap` | `GET sitemap` |
| `{articles,projects,pages}-{fr,de,en}` | `GET {collection}?language={code}&page=1&per_page=100`, the complete list of a collection in a language |
| `projects-fr-page-2` | `GET projects?language=fr&page=2&per_page=100`: a page beyond the last, which the plugin answers 400 |
| `project-momocad-de-in-fr` | `GET projects/momocad-de?language=fr`: what the catalog must answer for that slug in French |
| `page-lebenslauf-in-en` | `GET pages/lebenslauf?language=en`: the same for a page with one slug per language |

`firewall-challenge.html` is not a recording: the host's firewall cannot be made to answer on demand. It is the minimal reconstruction the tests of the content sync already use (`.github/scripts/test_sync_content.py`), a 503 with an HTML page that posts to `/o2s-cgi/security-challenge`, the mark both clients look for.

To record again, from this folder (the tests state counts and values of the content of 2026-11-22, which a new recording may require to update):

```bash
BASE=https://morganvoltz.dev/wp-json/portfolio/v1
UA='portfolio-md-api/1.0 (+https://github.com/morgan-voltz/portfolio-md)'
get() { curl -sS -A "$UA" -H 'Accept: application/json' -D "$1.headers" -o "$1.json" "$BASE/$2"; sed -i 's/\r$//' "$1.headers"; }
get sitemap 'sitemap'
for c in articles projects pages; do for l in fr de en; do get "$c-$l" "$c?language=$l&page=1&per_page=100"; done; done
get projects-fr-page-2 'projects?language=fr&page=2&per_page=100'
get project-momocad-de-in-fr 'projects/momocad-de?language=fr'
get page-lebenslauf-in-en 'pages/lebenslauf?language=en'
```

#!/usr/bin/env bash
# Deploy a version of the plugin, the child theme and the content to the WordPress site at o2switch
# (docs/guides/02-exploitation-site-trilingue.md, part 2.4): the built plugin (vendor/ and
# assets/dist/ present, tests and editor sources left behind), the child theme, and the content
# to a folder OUTSIDE the web root, where `wp portfolio import` reads it. Nothing of the server is
# written here: the SSH target comes from the environment, the web root and the content folder too.
#
#   PORTFOLIO_DEPLOY_SSH=<user>@<host> plugin/tools/deploy-o2switch.sh <built checkout>
#
# <built checkout> is a folder holding plugin/ (built), theme/ and content/ of the commit to deploy
# (for example `git archive main plugin theme content`, then `composer install --no-dev` and `npm ci && npm run build`
# in plugin/ and plugin/assets/). Optional: PORTFOLIO_DEPLOY_ROOT (web root, default voltz.dev) and
# PORTFOLIO_DEPLOY_CONTENT (content folder, default portfolio-content), both relative to the home.
set -euo pipefail
SRC=${1:?usage: PORTFOLIO_DEPLOY_SSH=<user>@<host> $0 <built checkout>}
: "${PORTFOLIO_DEPLOY_SSH:?set PORTFOLIO_DEPLOY_SSH=<user>@<host> (never written in the repository)}"
ROOT=${PORTFOLIO_DEPLOY_ROOT:-voltz.dev}
CONTENT=${PORTFOLIO_DEPLOY_CONTENT:-portfolio-content}
for f in "$SRC/plugin/vendor/autoload.php" "$SRC/plugin/assets/dist/admin-editor.js" "$SRC/theme/portfolio-md-child/style.css" "$SRC/content/skills.yaml"; do
  [ -f "$f" ] || { echo "missing $f: build the checkout first (composer install --no-dev, npm ci && npm run build)" >&2; exit 1; }
done
rsync -az --delete --exclude 'assets/node_modules' --exclude 'assets/src' --exclude 'tests' --exclude 'phpunit.xml.dist' --exclude '.phpunit.cache' "$SRC/plugin/" "$PORTFOLIO_DEPLOY_SSH:$ROOT/wp-content/plugins/portfolio-md/"
rsync -az --delete "$SRC/theme/portfolio-md-child/" "$PORTFOLIO_DEPLOY_SSH:$ROOT/wp-content/themes/portfolio-md-child/"
rsync -az --delete "$SRC/content/" "$PORTFOLIO_DEPLOY_SSH:$CONTENT/"
ssh "$PORTFOLIO_DEPLOY_SSH" "cd $ROOT && echo \"plugin \$(wp plugin get portfolio-md --field=version) uploaded, theme \$(wp theme get portfolio-md-child --field=version), \$(find ~/$CONTENT -mindepth 3 -name '*.md' | wc -l) documents; next: wp portfolio skills sync ~/$CONTENT/skills.yaml, then wp portfolio import ~/$CONTENT/<lang>/<type> ... --user=<login>\""

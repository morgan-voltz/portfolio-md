/**
 * The document shown in the preview frame: a page of its own, with the
 * stylesheets of the core blocks and nothing of the admin, so that the
 * rendering looks like the front end and not like wp-admin. The frame is
 * sandboxed by the mount: nothing in it runs.
 */

/** The summary lines, in the order they are shown. */
const SUMMARY_ORDER = ['type', 'slug', 'date', 'status', 'skills', 'tags']

const FRAME_STYLE = `
  html { font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; background: #fff; }
  body { margin: 0; padding: 24px 32px 48px; }
  .portfolio-md-preview { max-width: 760px; margin: 0 auto; }
  .portfolio-md-preview__title { font-size: 2em; line-height: 1.2; margin: 0 0 .5em; }
  .portfolio-md-preview__summary { display: grid; grid-template-columns: max-content 1fr; gap: 2px 16px; margin: 0 0 2em; padding: 12px 16px; border-left: 3px solid #c3c4c7; background: #f6f7f7; font-size: .875em; color: #50575e; }
  .portfolio-md-preview__row { display: contents; }
  .portfolio-md-preview__summary dt { font-weight: 600; }
  .portfolio-md-preview__summary dd { margin: 0; }
  .entry-content > * { max-width: 100%; }
  .entry-content img { max-width: 100%; height: auto; }
  .entry-content pre { overflow: auto; }
`

export function escapeHtml(text) {
  return String(text).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character])
}

/**
 * Links of the frame open in a new tab (its `<base target="_blank">`),
 * outside the sandbox: they get no handle on the frame and no referrer,
 * whatever the browser's defaults. The HTML comes from the server's
 * renderer, whose anchors are well formed.
 */
export function detachLinks(html) {
  return html.replace(/<a\s(?![^>]*\brel=)/gi, '<a rel="noopener noreferrer" ')
}

/**
 * @param {object} rendering
 * @param {string} rendering.title
 * @param {string} rendering.html        trusted: the server already passed it through kses
 * @param {object} [rendering.summary]   frontmatter lines keyed by name
 * @param {string[]} [rendering.stylesheets]
 * @param {object} [rendering.labels]    `untitled` and `summary.<key>`
 */
export function frameDocument({ title, html, summary = {}, stylesheets = [], labels = {} }) {
  const links = stylesheets.map((href) => `<link rel="stylesheet" href="${escapeHtml(href)}">`).join('')
  const rows = SUMMARY_ORDER
    .filter((key) => typeof summary[key] === 'string')
    .map((key) => {
      const label = escapeHtml(labels.summary?.[key] ?? key)
      const value = summary[key] === '' ? '—' : escapeHtml(summary[key])

      return `<div class="portfolio-md-preview__row"><dt>${label}</dt><dd>${value}</dd></div>`
    })
    .join('')
  const heading = title === '' ? escapeHtml(labels.untitled ?? '') : escapeHtml(title)

  return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><base target="_blank">'
    + links
    + `<style>${FRAME_STYLE}</style></head><body><article class="portfolio-md-preview">`
    + `<h1 class="portfolio-md-preview__title">${heading}</h1>`
    + (rows === '' ? '' : `<dl class="portfolio-md-preview__summary">${rows}</dl>`)
    + `<div class="entry-content">${detachLinks(html)}</div></article></body></html>`
}

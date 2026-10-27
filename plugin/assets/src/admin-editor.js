/**
 * Entry point of the bundle (assets/dist/admin-editor.js): mounts the
 * editor and the preview on every marked textarea of the page.
 *
 * `window.portfolioMdEditor` is printed by Admin\EditorAssets before this
 * script; `wp.apiFetch` comes from the `wp-api-fetch` dependency and
 * carries the REST nonce (renewing it when it expires).
 */
import { createEditor } from './editor.js'
import { mountAll } from './mount.js'

function boot() {
  const config = window.portfolioMdEditor
  const apiFetch = window.wp && window.wp.apiFetch
  if (!config || typeof apiFetch !== 'function') {
    return
  }

  mountAll(document, {
    createEditor,
    config,
    request: (markdown) => apiFetch({ path: config.previewPath, method: 'POST', data: { markdown } }),
    timers: { setTimeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window) },
    storage: storageOrNull(),
  })
}

function storageOrNull() {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot)
} else {
  boot()
}

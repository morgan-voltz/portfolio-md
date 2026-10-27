/**
 * Mounts the editor and the preview on a marked textarea of the admin.
 *
 * The textarea stays in the form, hidden: it is what WordPress receives at
 * the save, so every change of the editor is copied into it at once. Next
 * to the editor, a sandboxed frame shows what the server renders for the
 * document after each pause in typing; the frame runs no script, whatever
 * the document contains, and opens links in a new tab.
 *
 * Everything the browser provides is injected (editor factory, request,
 * timers, storage), so that this wiring is tested in Node with jsdom.
 */
import { createPreviewer } from './preview.js'
import { frameDocument } from './preview-frame.js'

export const MARKER = 'data-portfolio-md-editor'

/** Where the "preview shown or hidden" choice is remembered, per browser. */
const PREFERENCE_KEY = 'portfolioMdEditor.preview'

/** Quiet time after the last change before the preview is asked, in ms. */
const PREVIEW_DELAY = 600

/**
 * Mounts on every marked textarea of the document.
 *
 * @returns {object[]} what mountEditor() returned for each
 */
export function mountAll(doc, deps) {
  return Array.from(doc.querySelectorAll(`textarea[${MARKER}]`)).map((textarea) => mountEditor(textarea, deps))
}

/**
 * @param {HTMLTextAreaElement} textarea
 * @param {object} deps
 * @param {Function} deps.createEditor    (parent, value, { onChange }) => { getValue, focus, destroy }
 * @param {Function} deps.request         (markdown) => Promise<object>
 * @param {object} deps.config            previewPath, stylesheets, labels
 * @param {object} deps.timers            setTimeout, clearTimeout
 * @param {object|null} [deps.storage]    a Storage for the preference, or null
 */
export function mountEditor(textarea, { createEditor, request, config, timers, storage = null }) {
  const doc = textarea.ownerDocument
  const labels = config.labels ?? {}
  const element = (tag, className, text) => {
    const node = doc.createElement(tag)
    if (className) {
      node.className = className
    }
    if (text !== undefined) {
      node.textContent = text
    }

    return node
  }

  const root = element('div', 'portfolio-md-editor')
  const toolbar = element('div', 'portfolio-md-editor__toolbar')
  const toggle = element('button', 'button portfolio-md-editor__toggle')
  toggle.type = 'button'
  const status = element('span', 'portfolio-md-editor__status')
  toolbar.append(toggle, status)

  const panes = element('div', 'portfolio-md-editor__panes')
  const code = element('div', 'portfolio-md-editor__code')
  const preview = element('div', 'portfolio-md-editor__preview')
  const problems = element('div', 'portfolio-md-editor__problems notice notice-error inline')
  problems.hidden = true
  const frame = element('iframe', 'portfolio-md-editor__frame')
  frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox')
  frame.setAttribute('title', labels.preview ?? 'Preview')
  preview.append(problems, frame)
  panes.append(code, preview)
  root.append(toolbar, panes)

  textarea.before(root)
  textarea.hidden = true

  let shown = readPreference(storage) !== 'hidden'

  const previewer = createPreviewer({
    request,
    onState: show,
    delay: PREVIEW_DELAY,
    setTimeout: timers.setTimeout,
    clearTimeout: timers.clearTimeout,
  })

  const editor = createEditor(code, textarea.value, {
    onChange: (value) => {
      textarea.value = value
      if (shown) {
        previewer.schedule(value)
      }
    },
  })

  if (textarea.form) {
    textarea.form.addEventListener('submit', () => {
      textarea.value = editor.getValue()
    })
  }

  toggle.addEventListener('click', () => {
    shown = !shown
    writePreference(storage, shown ? 'shown' : 'hidden')
    applyVisibility()
    if (shown) {
      previewer.run(editor.getValue())
    } else {
      previewer.cancel()
    }
  })

  applyVisibility()
  if (shown) {
    previewer.run(textarea.value)
  }

  function applyVisibility() {
    root.dataset.preview = shown ? 'shown' : 'hidden'
    preview.hidden = !shown
    toggle.textContent = shown ? labels.hidePreview ?? 'Hide preview' : labels.showPreview ?? 'Show preview'
    if (!shown) {
      status.textContent = ''
    }
  }

  function show(state) {
    switch (state.kind) {
      case 'empty':
        status.textContent = labels.empty ?? ''
        showProblems([])
        frame.classList.remove('is-stale')
        frame.srcdoc = ''
        break
      case 'loading':
        status.textContent = labels.rendering ?? ''
        break
      case 'rendered':
        status.textContent = ''
        showProblems(state.problems)
        frame.classList.remove('is-stale')
        frame.srcdoc = frameDocument({
          title: state.title,
          html: state.html,
          summary: state.summary,
          stylesheets: config.stylesheets ?? [],
          labels,
        })
        break
      case 'refused':
        // The last rendering stays, dimmed: the text is being fixed.
        status.textContent = ''
        showProblems(state.problems)
        frame.classList.add('is-stale')
        break
      case 'unavailable':
        status.textContent = `${labels.unavailable ?? ''} ${state.message}`.trim()
        frame.classList.add('is-stale')
        break
      default:
        break
    }
  }

  function showProblems(list) {
    problems.replaceChildren()
    if (list.length === 0) {
      problems.hidden = true

      return
    }
    problems.append(element('p', '', labels.refused ?? ''))
    const items = element('ul')
    for (const problem of list) {
      items.append(element('li', '', problem))
    }
    problems.append(items)
    problems.hidden = false
  }

  return { root, editor, previewer }
}

function readPreference(storage) {
  try {
    return storage ? storage.getItem(PREFERENCE_KEY) : null
  } catch {
    return null
  }
}

function writePreference(storage, value) {
  try {
    storage?.setItem(PREFERENCE_KEY, value)
  } catch {
    // A browser that refuses storage just forgets the choice.
  }
}

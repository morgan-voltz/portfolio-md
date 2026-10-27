import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'
import { MARKER, mountAll, mountEditor } from '../src/mount.js'
import { fakeRequest, fakeTimers, flush, rendered } from './support.js'

const labels = {
  preview: 'Aperçu',
  showPreview: 'Afficher l’aperçu',
  hidePreview: 'Masquer l’aperçu',
  rendering: 'Rendu en cours…',
  empty: 'Rien à prévisualiser.',
  refused: 'Ce document serait refusé à l’enregistrement :',
  unavailable: 'Aperçu indisponible :',
  untitled: '(sans titre)',
  summary: { type: 'Type', slug: 'Slug', date: 'Date', status: 'Statut', skills: 'Compétences', tags: 'Tags' },
}

const config = { previewPath: '/portfolio/v1/preview', stylesheets: ['http://site/blocks.css'], labels }

/** An editor double: a value, and a way to "type" that fires onChange like CodeMirror would. */
function fakeEditorFactory() {
  const created = []
  const factory = (parent, value, { onChange }) => {
    const editor = {
      parent,
      value,
      type(text) {
        editor.value = text
        onChange(text)
      },
      getValue: () => editor.value,
      focus() {},
      destroy() {},
    }
    parent.append(parent.ownerDocument.createElement('div'))
    created.push(editor)

    return editor
  }
  factory.created = created

  return factory
}

function fakeStorage(initial = {}) {
  const store = new Map(Object.entries(initial))

  return {
    store,
    getItem: (key) => store.get(key) ?? null,
    setItem: (key, value) => store.set(key, value),
  }
}

describe('mountEditor', () => {
  let dom
  let doc
  let textarea
  let deps

  beforeEach(() => {
    dom = new JSDOM(`<form id="post"><textarea name="portfolio_md_source" ${MARKER}="1">---\ntitle: "x"\n---\n\nCorps\n</textarea></form>`)
    doc = dom.window.document
    textarea = doc.querySelector('textarea')
    deps = { createEditor: fakeEditorFactory(), request: fakeRequest(), config, timers: fakeTimers(), storage: fakeStorage() }
  })

  it('puts the editor before the textarea, which stays in the form hidden', () => {
    const { root, editor } = mountEditor(textarea, deps)

    assert.equal(root.nextElementSibling, textarea)
    assert.equal(textarea.hidden, true)
    assert.equal(textarea.name, 'portfolio_md_source')
    assert.equal(editor.value, textarea.value)
    assert.equal(root.querySelector('.portfolio-md-editor__code'), editor.parent)
  })

  it('asks for a preview of the stored document at once and shows it in a sandboxed frame', async () => {
    const { root } = mountEditor(textarea, deps)
    const frame = root.querySelector('iframe')

    assert.equal(frame.getAttribute('sandbox'), 'allow-popups allow-popups-to-escape-sandbox')
    assert.equal(frame.getAttribute('title'), 'Aperçu')
    assert.equal(root.querySelector('.portfolio-md-editor__status').textContent, 'Rendu en cours…')
    assert.equal(deps.request.calls.length, 1)
    assert.equal(deps.request.calls[0].markdown, textarea.value)

    deps.request.calls[0].answer.resolve(rendered('Mon titre', '<p>Corps rendu</p>'))
    await flush()

    assert.ok(frame.srcdoc.includes('<h1 class="portfolio-md-preview__title">Mon titre</h1>'))
    assert.ok(frame.srcdoc.includes('<p>Corps rendu</p>'))
    assert.ok(frame.srcdoc.includes('href="http://site/blocks.css"'))
    assert.equal(root.querySelector('.portfolio-md-editor__status').textContent, '')
    assert.equal(root.querySelector('.portfolio-md-editor__problems').hidden, true)
  })

  it('copies every change into the textarea at once and asks for a preview after a pause', async () => {
    const { editor } = mountEditor(textarea, deps)
    deps.request.calls[0].answer.resolve(rendered('x'))
    await flush()

    editor.type('# a')
    editor.type('# ab')
    assert.equal(textarea.value, '# ab')
    assert.equal(deps.request.calls.length, 1)

    deps.timers.advance(600)
    assert.equal(deps.request.calls.length, 2)
    assert.equal(deps.request.calls[1].markdown, '# ab')
  })

  it('copies the editor into the textarea once more when the form is submitted', () => {
    const { editor } = mountEditor(textarea, deps)
    editor.value = 'typed without onChange'

    textarea.form.dispatchEvent(new dom.window.Event('submit', { cancelable: true }))

    assert.equal(textarea.value, 'typed without onChange')
  })

  it('lists the problems of a refused document and dims the last rendering until it is fixed', async () => {
    const { root, editor } = mountEditor(textarea, deps)
    const frame = root.querySelector('iframe')
    const problems = root.querySelector('.portfolio-md-editor__problems')
    deps.request.calls[0].answer.resolve(rendered('Avant'))
    await flush()

    editor.type('broken')
    deps.timers.advance(600)
    deps.request.calls[1].answer.resolve({ importable: false, html: null, title: null, summary: {}, problems: ['Missing key "title"', 'Second'] })
    await flush()

    assert.equal(problems.hidden, false)
    assert.equal(problems.querySelector('p').textContent, labels.refused)
    assert.deepEqual([...problems.querySelectorAll('li')].map((li) => li.textContent), ['Missing key "title"', 'Second'])
    assert.ok(frame.classList.contains('is-stale'))
    assert.ok(frame.srcdoc.includes('Avant'))

    editor.type('fixed')
    deps.timers.advance(600)
    deps.request.calls[2].answer.resolve(rendered('Après', '<p>ok</p>', { problems: ['Unknown competency code(s): 4.0.2/E99'] }))
    await flush()

    assert.ok(!frame.classList.contains('is-stale'))
    assert.ok(frame.srcdoc.includes('Après'))
    assert.equal(problems.hidden, false)
    assert.deepEqual([...problems.querySelectorAll('li')].map((li) => li.textContent), ['Unknown competency code(s): 4.0.2/E99'])
  })

  it('says when the server could not be reached and keeps the last rendering', async () => {
    const { root } = mountEditor(textarea, deps)
    deps.request.calls[0].answer.reject({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' })
    await flush()

    assert.equal(root.querySelector('.portfolio-md-editor__status').textContent, 'Aperçu indisponible : Cookie check failed')
    assert.ok(root.querySelector('iframe').classList.contains('is-stale'))
  })

  it('hides the preview on demand, stops asking, remembers the choice, and resumes when shown again', async () => {
    const { root, editor } = mountEditor(textarea, deps)
    const toggle = root.querySelector('.portfolio-md-editor__toggle')
    const pane = root.querySelector('.portfolio-md-editor__preview')
    deps.request.calls[0].answer.resolve(rendered('x'))
    await flush()
    assert.equal(toggle.textContent, 'Masquer l’aperçu')
    assert.equal(root.dataset.preview, 'shown')

    toggle.click()

    assert.equal(pane.hidden, true)
    assert.equal(root.dataset.preview, 'hidden')
    assert.equal(toggle.textContent, 'Afficher l’aperçu')
    assert.equal(deps.storage.getItem('portfolioMdEditor.preview'), 'hidden')
    editor.type('typed while hidden')
    deps.timers.advance(600)
    assert.equal(deps.request.calls.length, 1)
    assert.equal(textarea.value, 'typed while hidden')

    toggle.click()

    assert.equal(pane.hidden, false)
    assert.equal(deps.storage.getItem('portfolioMdEditor.preview'), 'shown')
    assert.equal(deps.request.calls.length, 2)
    assert.equal(deps.request.calls[1].markdown, 'typed while hidden')
  })

  it('starts hidden when the browser remembers that choice', () => {
    deps.storage = fakeStorage({ 'portfolioMdEditor.preview': 'hidden' })

    const { root } = mountEditor(textarea, deps)

    assert.equal(root.dataset.preview, 'hidden')
    assert.equal(root.querySelector('.portfolio-md-editor__preview').hidden, true)
    assert.equal(deps.request.calls.length, 0)
  })

  it('works without storage and without a form', () => {
    const loose = new JSDOM(`<textarea ${MARKER}="1"></textarea>`)
    const area = loose.window.document.querySelector('textarea')

    const { root } = mountEditor(area, { ...deps, storage: null })

    assert.equal(root.querySelector('.portfolio-md-editor__status').textContent, 'Rien à prévisualiser.')
    assert.equal(deps.request.calls.length, 0)
  })
})

describe('mountAll', () => {
  it('mounts on every marked textarea and leaves the others alone', () => {
    const dom = new JSDOM(`<textarea ${MARKER}="1">a</textarea><textarea name="other">b</textarea><textarea ${MARKER}="1">c</textarea>`)
    const createEditor = fakeEditorFactory()

    const mounted = mountAll(dom.window.document, { createEditor, request: fakeRequest(), config, timers: fakeTimers() })

    assert.equal(mounted.length, 2)
    assert.deepEqual(createEditor.created.map((e) => e.value), ['a', 'c'])
    assert.equal(dom.window.document.querySelector('textarea[name="other"]').hidden, false)
  })
})

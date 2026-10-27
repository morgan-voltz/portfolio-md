import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { detachLinks, escapeHtml, frameDocument } from '../src/preview-frame.js'

const labels = {
  untitled: '(sans titre)',
  summary: { type: 'Type', slug: 'Slug', date: 'Date', status: 'Statut', skills: 'Compétences', tags: 'Tags' },
}

describe('escapeHtml', () => {
  it('neutralises the five characters HTML cares about', () => {
    assert.equal(escapeHtml(`<a href="x">Tom & Jerry's</a>`), '&lt;a href=&quot;x&quot;&gt;Tom &amp; Jerry&#039;s&lt;/a&gt;')
  })
})

describe('detachLinks', () => {
  it('gives every link without rel an opener-less, referrer-less rel and leaves the rest alone', () => {
    const html = '<p><a href="http://a/">a</a> <A HREF="http://b/">b</A> <a rel="me" href="http://c/">c</a> <abbr title="x">y</abbr></p>'

    assert.equal(
      detachLinks(html),
      '<p><a rel="noopener noreferrer" href="http://a/">a</a> <a rel="noopener noreferrer" HREF="http://b/">b</A> <a rel="me" href="http://c/">c</a> <abbr title="x">y</abbr></p>',
    )
  })
})

describe('frameDocument', () => {
  it('is a page of its own with the stylesheets, the title and the HTML', () => {
    const page = frameDocument({
      title: 'Titre <b>',
      html: '<p class="x">Corps</p>',
      stylesheets: ['http://site/style.css?ver=7&x=1'],
      labels,
    })

    assert.ok(page.startsWith('<!doctype html><html lang="fr">'))
    assert.ok(page.includes('<meta charset="utf-8">'))
    assert.ok(page.includes('<base target="_blank">'))
    assert.ok(page.includes('<link rel="stylesheet" href="http://site/style.css?ver=7&amp;x=1">'))
    assert.ok(page.includes('<h1 class="portfolio-md-preview__title">Titre &lt;b&gt;</h1>'))
    assert.ok(page.includes('<div class="entry-content"><p class="x">Corps</p></div>'))
    assert.ok(!page.includes('<script'))
  })

  it('detaches the links of the body', () => {
    const page = frameDocument({ title: 'T', html: '<p><a href="http://site/">lien</a></p>', labels })

    assert.ok(page.includes('<a rel="noopener noreferrer" href="http://site/">lien</a>'))
  })

  it('lists the summary in a fixed order, skipping what is absent and marking what is empty', () => {
    const page = frameDocument({
      title: 'T',
      html: '',
      summary: { tags: 'php, rust', slug: 'momocad', type: 'project', date: '', status: 'En cours', skills: '4.0.2/E07', ignored: 'x' },
      labels,
    })

    const rows = [...page.matchAll(/<dt>(.*?)<\/dt><dd>(.*?)<\/dd>/g)].map((m) => [m[1], m[2]])
    assert.deepEqual(rows, [
      ['Type', 'project'],
      ['Slug', 'momocad'],
      ['Date', '—'],
      ['Statut', 'En cours'],
      ['Compétences', '4.0.2/E07'],
      ['Tags', 'php, rust'],
    ])
  })

  it('escapes the summary values and falls back to the key when a label is missing', () => {
    const page = frameDocument({ title: 'T', html: '', summary: { slug: '<s>' }, labels: {} })

    assert.ok(page.includes('<dt>slug</dt><dd>&lt;s&gt;</dd>'))
  })

  it('has no summary block when there is nothing to summarise, and names an untitled document', () => {
    const page = frameDocument({ title: '', html: '<p></p>', labels })

    assert.ok(!page.includes('<dl '))
    assert.ok(page.includes('<h1 class="portfolio-md-preview__title">(sans titre)</h1>'))
  })
})

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { createPreviewer, describeError, stateFromResponse } from '../src/preview.js'
import { deferred, fakeRequest, fakeTimers, flush, rendered } from './support.js'

describe('stateFromResponse', () => {
  it('turns an answer with HTML into a rendering', () => {
    const state = stateFromResponse(rendered('Titre', '<p>x</p>', { problems: ['p'] }))

    assert.deepEqual(state, {
      kind: 'rendered',
      title: 'Titre',
      html: '<p>x</p>',
      summary: { type: 'article', slug: 'slug', date: '', skills: '', tags: '' },
      problems: ['p'],
    })
  })

  it('turns an answer without HTML into a refusal', () => {
    assert.deepEqual(stateFromResponse({ importable: false, html: null, title: null, summary: {}, problems: ['bad'] }), { kind: 'refused', problems: ['bad'] })
  })

  it('tolerates an answer missing fields', () => {
    assert.deepEqual(stateFromResponse({ html: '<p></p>' }), { kind: 'rendered', title: '', html: '<p></p>', summary: {}, problems: [] })
    assert.deepEqual(stateFromResponse(null), { kind: 'refused', problems: [] })
  })
})

describe('describeError', () => {
  it('reads the message of an apiFetch error or an Error', () => {
    assert.equal(describeError({ code: 'rest_forbidden', message: 'Sorry.' }), 'Sorry.')
    assert.equal(describeError(new Error('boom')), 'boom')
  })

  it('falls back to the string form', () => {
    assert.equal(describeError('offline'), 'offline')
    assert.equal(describeError({ code: 'x', message: '' }), '[object Object]')
  })
})

describe('createPreviewer', () => {
  function previewer(options = {}) {
    const timers = fakeTimers()
    const request = fakeRequest()
    const states = []
    const instance = createPreviewer({ request, onState: (state) => states.push(state), ...timers, ...options })

    return { timers, request, states, ...instance }
  }

  it('shows an empty state without asking the server', async () => {
    const p = previewer()

    await p.run('  \n')

    assert.deepEqual(p.states, [{ kind: 'empty' }])
    assert.equal(p.request.calls.length, 0)
  })

  it('asks the server and shows what it answers', async () => {
    const p = previewer()

    const run = p.run('# doc')
    assert.deepEqual(p.states, [{ kind: 'loading' }])
    assert.equal(p.request.calls[0].markdown, '# doc')
    p.request.calls[0].answer.resolve(rendered('Doc'))
    await run

    assert.equal(p.states.length, 2)
    assert.equal(p.states[1].kind, 'rendered')
    assert.equal(p.states[1].title, 'Doc')
  })

  it('waits for the typing to pause and asks once for the last text', async () => {
    const p = previewer({ delay: 600 })

    p.schedule('a')
    p.schedule('ab')
    p.timers.advance(599)
    assert.equal(p.request.calls.length, 0)
    p.schedule('abc')
    p.timers.advance(599)
    assert.equal(p.request.calls.length, 0)
    p.timers.advance(1)

    assert.equal(p.request.calls.length, 1)
    assert.equal(p.request.calls[0].markdown, 'abc')
    assert.equal(p.timers.pendingCount(), 0)
  })

  it('drops an answer that arrives after a newer request was sent', async () => {
    const p = previewer()

    const first = p.run('one')
    const second = p.run('two')
    p.request.calls[0].answer.resolve(rendered('One'))
    await first
    assert.deepEqual(p.states.map((s) => s.kind), ['loading', 'loading'])

    p.request.calls[1].answer.resolve(rendered('Two'))
    await second
    assert.equal(p.states.at(-1).kind, 'rendered')
    assert.equal(p.states.at(-1).title, 'Two')
  })

  it('reports a failed request as unavailable, with its message', async () => {
    const p = previewer()

    const run = p.run('doc')
    p.request.calls[0].answer.reject({ code: 'fetch_error', message: 'You are probably offline.' })
    await run

    assert.deepEqual(p.states.at(-1), { kind: 'unavailable', message: 'You are probably offline.' })
  })

  it('ignores a failure that a newer request made moot', async () => {
    const p = previewer()

    const first = p.run('one')
    const second = p.run('two')
    p.request.calls[0].answer.reject(new Error('late'))
    await first
    p.request.calls[1].answer.resolve(rendered('Two'))
    await second

    assert.deepEqual(p.states.map((s) => s.kind), ['loading', 'loading', 'rendered'])
  })

  it('cancels a scheduled request and forgets an answer in flight', async () => {
    const p = previewer({ delay: 100 })

    p.schedule('scheduled')
    p.cancel()
    p.timers.advance(100)
    assert.equal(p.request.calls.length, 0)

    const run = p.run('in flight')
    p.cancel()
    p.request.calls[0].answer.resolve(rendered('Late'))
    await run
    await flush()

    assert.deepEqual(p.states.map((s) => s.kind), ['loading'])
  })

  it('lets the deferred double be awaited by itself', async () => {
    const d = deferred()
    d.resolve(1)
    assert.equal(await d.promise, 1)
  })
})

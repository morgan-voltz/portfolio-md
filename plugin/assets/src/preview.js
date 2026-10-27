/**
 * The preview loop, without any DOM: when to ask the server, which answer
 * still matters, and what to show for it.
 *
 * Every state handed to `onState` is one of:
 *   { kind: 'empty' }                         nothing to render
 *   { kind: 'loading' }                       a request is on its way
 *   { kind: 'rendered', title, html, summary, problems }
 *   { kind: 'refused', problems }             the document could not be read
 *   { kind: 'unavailable', message }          the request itself failed
 */

/** What the server answered, as a state: no HTML means the document was refused. */
export function stateFromResponse(response) {
  const problems = Array.isArray(response?.problems) ? response.problems : []
  if (typeof response?.html !== 'string') {
    return { kind: 'refused', problems }
  }
  const summary = response.summary && typeof response.summary === 'object' ? response.summary : {}

  return { kind: 'rendered', title: typeof response.title === 'string' ? response.title : '', html: response.html, summary, problems }
}

/** The message of a failed request: an apiFetch error object, an Error, or anything else. */
export function describeError(error) {
  if (error && typeof error === 'object' && typeof error.message === 'string' && error.message !== '') {
    return error.message
  }

  return String(error)
}

/**
 * @param {object} options
 * @param {(markdown: string) => Promise<object>} options.request  asks the server; resolves with its JSON answer
 * @param {(state: object) => void} options.onState               receives every state change
 * @param {number} [options.delay]                                 quiet time after the last change before a request, in ms
 * @param {Function} options.setTimeout
 * @param {Function} options.clearTimeout
 */
export function createPreviewer({ request, onState, delay = 600, setTimeout, clearTimeout }) {
  let timer = null
  let sequence = 0

  /** Asks now. An answer that arrives after a newer request was sent is dropped. */
  async function run(markdown) {
    const id = ++sequence
    if (markdown.trim() === '') {
      onState({ kind: 'empty' })

      return
    }
    onState({ kind: 'loading' })

    let response
    try {
      response = await request(markdown)
    } catch (error) {
      if (id === sequence) {
        onState({ kind: 'unavailable', message: describeError(error) })
      }

      return
    }
    if (id === sequence) {
      onState(stateFromResponse(response))
    }
  }

  /** Asks once the text has stopped changing for `delay` ms. */
  function schedule(markdown) {
    clearTimeout(timer)
    timer = setTimeout(() => {
      timer = null
      run(markdown)
    }, delay)
  }

  /** Forgets a scheduled request and ignores any answer still in flight. */
  function cancel() {
    clearTimeout(timer)
    timer = null
    sequence++
  }

  return { run, schedule, cancel }
}

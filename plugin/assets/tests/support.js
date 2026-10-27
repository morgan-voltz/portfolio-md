/** Test doubles shared by the suites: timers under control, requests resolved by hand. */

export function fakeTimers() {
  let now = 0
  let nextId = 0
  const pending = new Map()

  return {
    setTimeout(callback, delay) {
      const id = ++nextId
      pending.set(id, { at: now + delay, callback })

      return id
    },
    clearTimeout(id) {
      pending.delete(id)
    },
    /** Moves the clock forward and fires what is due, in order. */
    advance(ms) {
      now += ms
      for (const [id, timer] of [...pending.entries()].sort((a, b) => a[1].at - b[1].at)) {
        if (timer.at <= now) {
          pending.delete(id)
          timer.callback()
        }
      }
    },
    pendingCount: () => pending.size,
  }
}

/** A promise settled from the outside. */
export function deferred() {
  let resolve
  let reject
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })

  return { promise, resolve, reject }
}

/** A request double that records what it was asked and answers through deferreds, one per call. */
export function fakeRequest() {
  const calls = []
  const request = (markdown) => {
    const answer = deferred()
    calls.push({ markdown, answer })

    return answer.promise
  }
  request.calls = calls

  return request
}

/** Lets every settled promise run its continuations. */
export const flush = () => new Promise((resolve) => setImmediate(resolve))

export const rendered = (title, html = `<p>${title}</p>`, extra = {}) => ({
  importable: true,
  problems: [],
  title,
  summary: { type: 'article', slug: 'slug', date: '', skills: '', tags: '' },
  html,
  ...extra,
})

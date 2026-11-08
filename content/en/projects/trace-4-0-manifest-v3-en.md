---
title: "Trace 4.0, Manifest V3 migration"
slug: "trace-4-0-manifest-v3-en"
type: "project"
lang: "en"
translation_key: "trace-4-0-manifest-v3"
date: 2026-10-26
subtitle: "The school's tracking extension, broken by the end of Manifest V2, brought back to life on my own initiative"
seo_description: "Solo migration, in January 2026, of the UHA 4.0 learning-activity Chrome extension to Manifest V3, after Chrome switched off Manifest V2."
featured: false
status: "stable"
role: "Solo migration to Manifest V3, personal initiative outside the course"
period_start: 2026-01-01
period_end: 2026-01-20
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [javascript, chrome-extension, manifest-v3, service-worker, chrome-storage-api, rest-api, offscreencanvas, jquery]
skills: ["4.0.1/FR-08", "4.0.1/FR-15"]
---

Trace 4.0 is the official Chrome extension of UHA 4.0 that measures the time logged-in students are actually active on the training resources. It was built on Manifest V2, the format Chrome ended up switching off. In early 2026, on my own initiative and outside any graded coursework, I migrated the extension to Manifest V3 single-handedly, delivered in a single iteration (v1.2.0) with a changelog that documents every file changed.

## The problem

For each logged-in student, the extension tracks the time spent on the resources listed by the training platform: UHA credentials, a terms-of-use (CGU) checkbox, a visible toggle button, and an icon that changes colour with the state (green active, red inactive, grey logged out). The measured sessions are sent back to an internal API of the school, and the student finds their programmes, their courses and their history from the popup.

The problem was not functional but one of infrastructure: Manifest V2, the format it was built on, has been progressively switched off by Google in up-to-date browsers. Without intervention, the tracking tool refused to load, and nobody on the course could use it any more.

I took the initiative to migrate the extension in my own time. The difficulty is not renaming a few keys in the manifest: Manifest V3 changes the execution model. The background script is no longer a persistent page whose state stays readable at any moment, but an ephemeral service worker that Chrome kills and restarts as it sees fit. Everything that assumed an in-memory state available at all times had to be rethought.

## The technical decisions

**The service worker no longer has a reliable state, so all storage becomes asynchronous.** `localStorage`, being synchronous, is not accessible to a service worker. I replaced it everywhere with the asynchronous `chrome.storage.local`, behind two small wrappers, `getStorageData`/`setStorageData`, built on `Promise`s. The change cascades: the three files that read the state (`background.js`, `manager.js`, `gandalf.js`) are converted entirely to `async`/`await`, without changing the observable behaviour.

**Periodic tasks do not live in the service worker.** The classic trap of an MV3 migration is to put a `setInterval` in the background script: Chrome shuts down an idle service worker after about thirty seconds, which would interrupt it. `background.js` contains none. The polling for URL changes (`setInterval(checkUrlChange, 100)`) and the batched sending every two minutes (`sendPeriod = 2 * 60 * 1000`) live entirely in the content script `activityWatcher.js`, an ordinary page context that this shutdown does not affect. There is no need for `chrome.alarms`: the right execution context is enough.

**Changing the icon from a service worker means redrawing the image by hand.** `setIcon` can no longer take a direct file path. The `updateIcon` function in `background.js` fetches the PNG with `fetch`, converts it to an `ImageBitmap`, draws it onto a 128-pixel `OffscreenCanvas`, extracts the `ImageData` from it, and that result is what `chrome.action.setIcon` accepts. A constraint that looks minor on paper becomes the trickiest detail of the migration.

**The API configuration comes from the manifest, not from a copied constant.** Rather than hard-coding the address of the API, the code reads it back dynamically with `chrome.runtime.getManifest().host_permissions[0]`. The single source remains the manifest. The calculation is, however, copied identically into the three files that need it rather than factored into a shared module, a debt I describe below.

**Two communication channels coexist rather than one.** Continuous tracking goes through a named `Port`, `"Tracing"`, opened once by `activityWatcher.js` and kept for the life of the page, so that the service worker receives the `ACTIVITY`/`URL_CHANGE` events at no extra cost. One-off events (after a login, a logout, a change of the tracking button, or a click on "Mes formations") go through a second channel, `chrome.runtime.sendMessage({type: 'REFRESH_TRACKING'})`, called from three files (`gandalf.js`, `manager.js` and `P_index.js`) and received by a dedicated `chrome.runtime.onMessage` in `background.js`. I did not merge the two because they do not have the same lifespan: the first lasts the browsing session on a page, the second is a one-way message triggered by a user action.

## What I learned from it

That the Chrome extension platform has a precise security and execution model, and that knowing it in detail (the split between `permissions` and `host_permissions`, the object format of the CSP, the service worker lifecycle) changes the way you read any extension, not just this one. That taking over existing code without changing its behaviour is a different exercise from writing from scratch: you first have to understand what is there before deciding what must change. And that constraints that seem trivial on paper, such as changing an icon, are sometimes the ones that demand the most technical workarounds.

What I would do differently: the storage wrappers and the reading of the URL from the manifest are duplicated identically in three files, for lack of a common storage module. On a project of this size nothing broke, but it is exactly the kind of duplication I would factor out from the start today.

## In figures

- ≈1,500 lines in total: 1,030 lines of application JavaScript across 8 files, 104 lines of HTML, 361 lines of home-grown CSS
- vendored third-party libraries (jQuery slim, Bootstrap 4, Popper, Moment.js, Font Awesome kit): ≈14,931 lines, of which 14,876 for Moment.js alone
- 1 migration release delivered: v1.2.0, on 20 January 2026
- 1 changelog of 52 lines that documents every file changed, 1 README of 115 lines
- target compatibility: Chrome 88 and above
- 0 automated tests, 0 CI

## Links

- Repository: Morgan's personal space on the internal GitLab of UHA 4.0, not public.
- Demo: none, the extension requires valid UHA 4.0 credentials.
- Documentation: installation README and file-by-file technical changelog, kept in the repository.

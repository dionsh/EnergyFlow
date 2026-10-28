// Captures the landing page's product screens from the demo company, in both languages
// and both themes, as WebP into frontend/src/features/landing/shots (the "See it in action" tabs).
//
//   php backend/bin/seed-demo.php            # a fresh demo story
//   (start the API on :8000 and the web app, e.g. `npm --prefix frontend run preview` on :4173)
//   node tools/landing-shots/capture.mjs [--base=http://localhost:4173] [--browser="C:/…/chrome.exe"]
//
// Node 22 or newer (built-in WebSocket); drives a local Chrome or Edge over the DevTools
// protocol, so there is nothing to install. It signs in as the read-only demo guest.
// Local API only: switching the language saves it on the shared demo guest account.

import { spawn } from 'node:child_process'
import { existsSync } from 'node:fs'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const args = Object.fromEntries(process.argv.slice(2).map((arg) => arg.replace(/^--/, '').split('=')))
const BASE = (args.base ?? 'http://localhost:4173').replace(/\/$/, '')
const OUT = resolve(dirname(fileURLToPath(import.meta.url)), '../../frontend/src/features/landing/shots')
const BROWSERS = [
  args.browser,
  process.env.CHROME_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome',
].filter(Boolean)

// Keep in sync with SCREENS in Showcase.jsx.
const SCREENS = [
  { key: 'overview', path: '/' },
  { key: 'waste', path: '/waste' },
  { key: 'carbon', path: '/carbon' },
]
const LANGUAGES = ['sq', 'en']
const THEMES = ['light', 'dark']
const VIEWPORT = { width: 1280, height: 800, deviceScaleFactor: 1.75, mobile: false }

const sleep = (ms) => new Promise((done) => setTimeout(done, ms))

async function launch() {
  const executable = BROWSERS.find((path) => existsSync(path))
  if (!executable) throw new Error('No Chrome or Edge found: pass --browser=<path>')
  const profile = await mkdtemp(join(tmpdir(), 'ef-shots-'))
  const child = spawn(executable, [
    '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'pipe'] })
  const endpoint = await new Promise((done, fail) => {
    let log = ''
    child.stderr.on('data', (chunk) => {
      log += chunk
      const match = log.match(/DevTools listening on (ws:\/\/\S+)/)
      if (match) done(match[1])
    })
    child.on('exit', (code) => fail(new Error(`The browser exited (${code}): ${log}`)))
    setTimeout(() => fail(new Error('The browser did not start within 20 s')), 20_000)
  })
  return { endpoint, close: async () => { child.kill(); await sleep(500); await rm(profile, { recursive: true, force: true }).catch(() => {}) } }
}

/** A minimal DevTools-protocol client: commands with replies, plus one-off event waits. */
async function connect(endpoint) {
  const socket = new WebSocket(endpoint)
  await new Promise((done, fail) => { socket.onopen = done; socket.onerror = fail })
  let nextId = 0
  const pending = new Map()
  const waiters = new Set()
  socket.onmessage = ({ data }) => {
    const message = JSON.parse(data)
    if (message.id !== undefined) {
      const { done, fail } = pending.get(message.id)
      pending.delete(message.id)
      if (message.error) fail(new Error(message.error.message))
      else done(message.result)
      return
    }
    for (const waiter of waiters) {
      if (waiter.method === message.method) {
        waiters.delete(waiter)
        waiter.done(message.params)
      }
    }
  }
  return {
    send: (method, params = {}, sessionId) => new Promise((done, fail) => {
      pending.set(++nextId, { done, fail })
      socket.send(JSON.stringify({ id: nextId, method, params, sessionId }))
    }),
    once: (method) => new Promise((done) => waiters.add({ method, done })),
    close: () => socket.close(),
  }
}

async function main() {
  const browser = await launch()
  const cdp = await connect(browser.endpoint)
  try {
    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' })
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true })
    const page = (method, params) => cdp.send(method, params, sessionId)
    const evaluate = async (expression) => {
      const { result, exceptionDetails } = await page('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })
      if (exceptionDetails) throw new Error(exceptionDetails.exception?.description ?? exceptionDetails.text)
      return result.value
    }
    const goto = async (path) => {
      const loaded = cdp.once('Page.loadEventFired')
      await page('Page.navigate', { url: BASE + path })
      await loaded
    }
    // Loaded = no skeletons or spinners for a moment, fonts in, charts drawn.
    const settle = async () => {
      for (let quiet = 0, tries = 0; quiet < 3 && tries < 120; tries++) {
        quiet = (await evaluate("document.querySelectorAll('.animate-pulse, .animate-spin').length")) === 0 ? quiet + 1 : 0
        await sleep(250)
      }
      await evaluate('document.fonts.ready.then(() => true)')
      await sleep(1500)
    }

    await page('Page.enable')
    await page('Runtime.enable')
    await page('Emulation.setDeviceMetricsOverride', VIEWPORT)
    await page('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] })
    // No energy-flow intro: it only greets first visits to the landing page.
    await page('Page.addScriptToEvaluateOnNewDocument', { source: "try { sessionStorage.setItem('ef.intro', '1') } catch {}" })

    await goto('/')
    const status = await evaluate("fetch('/api/v1/auth/demo', { method: 'POST', credentials: 'include', headers: { 'X-EF-Client': 'web', Accept: 'application/json' } }).then((r) => r.status)")
    if (status !== 200) throw new Error(`Demo sign-in failed (${status}): is the API running and the demo seeded?`)

    await mkdir(OUT, { recursive: true })
    for (const language of LANGUAGES) {
      for (const theme of THEMES) {
        await evaluate(`localStorage.setItem('ef.theme', '${theme}'); localStorage.setItem('ef.locale', '${language}')`)
        for (const screen of SCREENS) {
          await goto(screen.path)
          await settle()
          // The app follows the account's language once /auth/me answers; switch it like a visitor would.
          if ((await evaluate('document.documentElement.lang')) !== language) {
            await evaluate(`document.querySelector('button[lang="${language}"]').click()`)
            await settle()
          }
          const { data } = await page('Page.captureScreenshot', { format: 'webp', quality: 82 })
          const file = `${screen.key}-${language}-${theme}.webp`
          await writeFile(join(OUT, file), Buffer.from(data, 'base64'))
          console.log(`saved ${file}`)
        }
      }
    }
    // Leave the demo guest in its own language.
    await evaluate(`document.querySelector('button[lang="${LANGUAGES[0]}"]').click()`)
    await settle()
  } finally {
    cdp.close()
    await browser.close()
  }
}

main().catch((error) => {
  console.error(error.message)
  process.exit(1)
})

#!/usr/bin/env node
// EnergyFlow hardware bridge: a metering smart plug ⇄ the EnergyFlow device API.
//
// It speaks exactly the protocol an EF-N3 node speaks (hardware/README.md §5):
//   every 10 s  POST /api/v1/ingest/readings      signed batch of readings
//   if the response says pending_commands > 0:
//               GET  /api/v1/device/commands      → open the relay (plug off)
//               POST /api/v1/device/commands/{id}/ack   executed | failed
// EnergyFlow then verifies the stop independently, from the readings that follow.
//
// Zero dependencies (Node ≥ 20: global fetch + node:crypto). Configuration via
// environment variables or a .env file next to this script — see .env.example.
import { createHmac } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createShellyGen2 } from './drivers/shelly-gen2.mjs'
import { createMockPlug } from './drivers/mock.mjs'

const here = dirname(fileURLToPath(import.meta.url))
loadEnvFile(join(here, '.env'))

const config = {
  api: (process.env.EF_API_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, ''),
  serial: required('EF_DEVICE_SERIAL'),
  secret: required('EF_DEVICE_SECRET'),
  channel: Number(process.env.EF_CHANNEL ?? 1),
  driver: process.env.EF_PLUG_DRIVER ?? 'shelly-gen2',
  plugHost: process.env.EF_PLUG_HOST,
  intervalMs: Number(process.env.EF_INTERVAL_S ?? 10) * 1000,
  bufferMax: Number(process.env.EF_BUFFER_MAX ?? 8640), // 24 h at 10 s
}
const FIRMWARE = 'bridge-0.1.0'

const plug = createPlug()
const buffer = [] // store-and-forward: readings that could not be delivered yet
let seq = 0
const startedAt = Date.now()

log(`EnergyFlow bridge ${FIRMWARE} · device ${config.serial} ch${config.channel} · plug driver "${plug.name}" · API ${config.api}`)
await tick()
setInterval(tick, config.intervalMs)

async function tick() {
  try {
    const reading = await plug.read()
    buffer.push({
      ch: config.channel,
      ts: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
      v: round(reading.voltage, 2),
      i: round(reading.current, 3),
      p_kw: round(reading.powerW / 1000, 4),
      pf: reading.pf === undefined ? undefined : round(reading.pf, 3),
      f: reading.frequency === undefined ? undefined : round(reading.frequency, 3),
      e_kwh: reading.energyWh === undefined ? undefined : round(reading.energyWh / 1000, 4),
      t_c: reading.temperature === undefined ? undefined : round(reading.temperature, 1),
    })
    if (buffer.length > config.bufferMax) buffer.splice(0, buffer.length - config.bufferMax)
  } catch (error) {
    log(`plug read failed: ${error.message}`)
  }
  if (buffer.length === 0) return

  // Oldest first, at most 360 readings (1 h) per request.
  const batch = buffer.slice(0, 360)
  const payload = {
    device: config.serial,
    fw: plug.mock ? `${FIRMWARE}+mock` : FIRMWARE,
    seq: seq++,
    readings: batch,
    status: { uptime_s: Math.round((Date.now() - startedAt) / 1000), buffered: buffer.length - batch.length, net: 'bridge' },
  }
  try {
    const result = await signed('POST', '/api/v1/ingest/readings', payload)
    buffer.splice(0, batch.length)
    const p = batch[batch.length - 1]
    log(`sent ${batch.length} reading(s) · ${p.p_kw} kW · accepted ${result.data.accepted}, duplicates ${result.data.duplicates}, rejected ${result.data.rejected}`)
    if (result.data.pending_commands > 0) await runCommands()
  } catch (error) {
    log(`uplink failed (${buffer.length} buffered): ${error.message}`)
  }
}

async function runCommands() {
  const { data: commands } = await signed('GET', '/api/v1/device/commands')
  for (const command of commands) {
    if (command.ch !== config.channel || command.command !== 'turn_off') {
      await signed('POST', `/api/v1/device/commands/${command.id}/ack`, { status: 'failed', detail: `unsupported ${command.command} on ch${command.ch}` })
      continue
    }
    try {
      await plug.setOn(false)
      await signed('POST', `/api/v1/device/commands/${command.id}/ack`, { status: 'executed' })
      log(`command #${command.id}: relay opened (plug off). EnergyFlow now verifies it from the next readings.`)
    } catch (error) {
      await signed('POST', `/api/v1/device/commands/${command.id}/ack`, { status: 'failed', detail: error.message.slice(0, 150) })
      log(`command #${command.id} failed: ${error.message}`)
    }
  }
}

/** Device auth: Authorization: Device <serial>, X-EF-Timestamp, X-EF-Signature = HMAC-SHA256(secret, ts + "." + body). */
async function signed(method, path, body) {
  const raw = body === undefined ? '' : JSON.stringify(body) // undefined fields are dropped
  const timestamp = Math.floor(Date.now() / 1000)
  const signature = createHmac('sha256', config.secret).update(`${timestamp}.${raw}`).digest('hex')
  const response = await fetch(config.api + path, {
    method,
    headers: {
      Authorization: `Device ${config.serial}`,
      'X-EF-Timestamp': String(timestamp),
      'X-EF-Signature': signature,
      ...(raw ? { 'Content-Type': 'application/json' } : {}),
    },
    body: raw || undefined,
    signal: AbortSignal.timeout(8000),
  })
  const json = await response.json().catch(() => null)
  if (!response.ok) throw new Error(`${method} ${path} → HTTP ${response.status} ${json?.error?.code ?? ''}`)
  return json
}

function createPlug() {
  if (config.driver === 'shelly-gen2') return createShellyGen2(requiredValue('EF_PLUG_HOST', config.plugHost))
  if (config.driver === 'mock') {
    if (!process.argv.includes('--dev-mock')) {
      console.error('The mock plug is for testing the bridge without hardware. Start with --dev-mock to confirm.')
      process.exit(1)
    }
    return createMockPlug()
  }
  console.error(`Unknown EF_PLUG_DRIVER "${config.driver}". Available: shelly-gen2, mock.`)
  process.exit(1)
}

function loadEnvFile(path) {
  if (!existsSync(path)) return
  for (const line of readFileSync(path, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
    if (match && process.env[match[1]] === undefined) process.env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2')
  }
}

function required(name) {
  return requiredValue(name, process.env[name])
}

function requiredValue(name, value) {
  if (!value) {
    console.error(`Missing ${name}. Copy .env.example to .env and fill it in.`)
    process.exit(1)
  }
  return value
}

function round(value, digits) {
  return value === undefined || value === null || Number.isNaN(value) ? undefined : Number(Number(value).toFixed(digits))
}

function log(message) {
  console.log(`[${new Date().toISOString().slice(11, 19)}] ${message}`)
}

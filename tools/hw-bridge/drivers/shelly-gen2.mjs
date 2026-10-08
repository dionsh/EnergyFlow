// Shelly Gen2+ metering plugs and relays (e.g. Shelly Plus Plug S), local HTTP RPC API:
//   GET http://<host>/rpc/Switch.GetStatus?id=0  → { output, apower (W), voltage (V), current (A), freq?, pf?, aenergy: { total (Wh) }, temperature: { tC } }
//   GET http://<host>/rpc/Switch.Set?id=0&on=false
// Reference: Shelly Gen2 API docs, "Switch" component. Verify the field names against
// your plug's firmware before Demo Day (some models omit pf/freq; we derive PF if missing).
// If the plug has a password set, Gen2 requires HTTP digest auth: disable it for the demo LAN
// or extend this driver.

export function createShellyGen2(host, switchId = 0) {
  const base = `http://${host}/rpc`

  async function rpc(method, params) {
    const query = new URLSearchParams({ id: String(switchId), ...params })
    const response = await fetch(`${base}/${method}?${query}`, { signal: AbortSignal.timeout(4000) })
    if (!response.ok) throw new Error(`${method}: HTTP ${response.status}`)
    return response.json()
  }

  return {
    name: 'shelly-gen2',
    mock: false,
    async read() {
      const s = await rpc('Switch.GetStatus')
      const powerW = Math.max(0, Number(s.apower ?? 0))
      const voltage = Number(s.voltage ?? 0)
      const current = Number(s.current ?? 0)
      // Power factor = real power / apparent power, when the plug doesn't report it.
      const apparent = voltage * current
      const pf = s.pf !== undefined ? Math.abs(Number(s.pf)) : apparent > 1 ? Math.min(1, powerW / apparent) : undefined
      return {
        on: Boolean(s.output),
        powerW,
        voltage,
        current,
        pf,
        frequency: s.freq === undefined ? undefined : Number(s.freq),
        energyWh: s.aenergy?.total === undefined ? undefined : Number(s.aenergy.total),
        temperature: s.temperature?.tC === undefined ? undefined : Number(s.temperature.tC),
      }
    },
    async setOn(on) {
      const result = await rpc('Switch.Set', { on: on ? 'true' : 'false' })
      if (result?.code !== undefined && result.code !== 0) throw new Error(`Switch.Set refused: ${result.message ?? result.code}`)
    },
  }
}

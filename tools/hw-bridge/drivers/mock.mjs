// DEVELOPMENT ONLY: an in-process stand-in for a 60 W lamp on a metering plug, to test the
// bridge ⇄ API path (signing, buffering, commands, acks) without hardware. It is NOT a
// simulation of the factory and must never be presented as real hardware: the bridge
// reports its firmware as "bridge-0.1.0+mock", which the Devices page shows.

export function createMockPlug() {
  let on = true
  let energyWh = 1250
  let last = Date.now()
  return {
    name: 'mock (development only)',
    mock: true,
    async read() {
      const now = Date.now()
      const powerW = on ? 58 + Math.random() * 4 : 0
      energyWh += (powerW * (now - last)) / 3_600_000
      last = now
      const voltage = 229 + Math.random() * 3
      return { on, powerW, voltage, current: powerW / voltage, pf: on ? 0.99 : undefined, frequency: 50, energyWh }
    },
    async setOn(value) {
      on = value
    },
  }
}

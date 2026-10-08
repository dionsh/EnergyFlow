# EnergyFlow hardware bridge

Connects a **certified metering smart plug** to EnergyFlow, so a real load (the workshop lamp on stage) is measured live and can be **really switched off** by the Turn Off button. This is prototype stage **P0** in [`hardware/README.md`](../../hardware/README.md#6-prototype-plan-what-we-can-actually-build-before-demo-day): no open mains wiring, built in a day.

The bridge speaks **exactly the protocol of an EF-N3 node** (`hardware/README.md` §5). EnergyFlow cannot tell it apart from our own hardware, and the EF-N3 firmware will replace it without any server change.

```
 smart plug ──LAN (local HTTP)──► bridge.mjs ──HTTPS, HMAC-signed──► EnergyFlow API
     ▲                                │  every 10 s: POST /api/v1/ingest/readings
     └──── Switch.Set on=false ───────┤  pending_commands > 0 → GET /api/v1/device/commands
                                      └► POST /api/v1/device/commands/{id}/ack  (executed | failed)
```

**Verification does not trust the bridge.** A command only becomes *verified* when the readings that follow show the power below the machine's off threshold. If the relay opens but power doesn't drop within 60 s, or the device never answers within 2 minutes, the command fails and an alert is raised.

## Architecture: where simulation stops and hardware starts

| Layer | Simulated machines (demo factory) | Real hardware (this bridge, EF-N3) |
|---|---|---|
| Readings | Deterministic simulator on the server → `IngestService::store()` | Device → signed `POST /ingest/readings` → `IngestService::storeDevicePayload()` → `store()` |
| Command delivery | `SimulatedDriver`: picks the command up on its next 10 s "uplink", opens the virtual relay | `PullDriver`: the command waits in the queue; the device pulls it and acks |
| Verification, policies, alerts, UI | **Identical**: `CommandService::verify()` reads the meter data | **Identical** |

The only branch is `Services/Devices/DeviceGateway::driverFor()`. Machines measured by a real device are excluded from the simulator, so their data always comes from the device. The Devices page labels every device *Simulated* or *Hardware*.

## Setup (about 15 minutes)

**Hardware:** a **Shelly Gen2+ metering plug** (for example a Shelly Plus Plug S), on the same Wi-Fi as the laptop. Set it up in the Shelly app and note its IP address. For the demo, disable the plug's HTTP password (Gen2 uses digest auth), or extend the driver.

1. **Register the device in EnergyFlow** (prints the device secret once):
   ```powershell
   php backend/bin/provision-device.php --company=demo --serial=EF-101 --model=EF-Bridge --machine=LIVE-01 --name="Workshop lamp (live hardware)" --type=lighting --relay
   ```
   To keep it across demo resets, also set `DEMO_HARDWARE_SERIAL=EF-101` in the API's environment. The secret is derived from the serial, so it stays the same.
2. **Configure the bridge:** copy `.env.example` to `.env`, then fill in `EF_DEVICE_SECRET`, `EF_PLUG_HOST` and `EF_API_URL` (`http://127.0.0.1:8000` locally, or the Render API URL in production).
3. **Run it** (Node 20 or newer, no `npm install` needed):
   ```powershell
   node tools/hw-bridge/bridge.mjs
   ```
   Within 10 s, the device turns *Online* in EnergyFlow and the lamp appears on the Live screen.

**Without hardware** (development only), test the full signed path with the in-process mock lamp:

```powershell
$env:EF_PLUG_DRIVER="mock"; node tools/hw-bridge/bridge.mjs --dev-mock
```

The mock reports its firmware as `bridge-0.1.0+mock`, and the Devices page shows that. Never present it as hardware.

## Behaviour

- **Store-and-forward:** if the API is unreachable, readings are buffered (24 h by default) and sent oldest-first when the connection returns, up to 360 per request. Duplicates are ignored server-side.
- **Clock:** the signature carries the laptop's time and the server allows ±5 minutes, so keep the laptop clock synced.
- **Safety:** the bridge only ever switches the plug **off**. Like EF-N3's remote STOP, EnergyFlow has no "turn on" command. Restarting is a human action.

## Adding another plug type

Add a file in `drivers/` that exports an object with `read()` (returning `{ on, powerW, voltage, current, pf?, frequency?, energyWh?, temperature? }`) and `setOn(boolean)`, then register it in `createPlug()` in `bridge.mjs`.

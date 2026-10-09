import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { formatCo2, formatEur, formatKwh, formatPercent } from '../../lib/format'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { ErrorState, Skeleton } from '../../components/ui/States'
import { useEnergyFlow } from '../data'

// Layout of the diagram (SVG user units; it scales to the card).
const W = 820
const H = 300
const NODE = 12
const GAP = 8
const X_GRID = 96
const X_MACHINES = 380
const X_OUT = 620
const SERIES = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)', 'var(--series-5)']
const MIN_SHARE = 0.04 // machines below 4 % of the month go into "Others"

/** A band between two vertical spans, as a filled cubic Bézier shape. */
function band(x0, y0, x1, y1, w) {
  const mid = (x0 + x1) / 2
  return `M${x0},${y0} C${mid},${y0} ${mid},${y1} ${x1},${y1} L${x1},${y1 + w} C${mid},${y1 + w} ${mid},${y0 + w} ${x0},${y0 + w} Z`
}

/** Label baselines near their nodes, pushed apart so they never overlap. */
function spread(targets, minGap) {
  const out = []
  for (const y of targets) out.push(out.length ? Math.max(y, out[out.length - 1] + minGap) : y)
  return out
}

/** Positions of nodes stacked in a column, heights ∝ kWh. */
function stack(items, scale, top = 0) {
  let y = top
  return items.map((item) => {
    const h = Math.max(2, item.kwh * scale)
    const node = { ...item, y, h }
    y += h + GAP
    return node
  })
}

/**
 * Where this month's energy went (docs/06 §3.1): grid → machines → productive or
 * waste. Built from the same numbers as the rest of the app: metered kWh per
 * machine, the waste the detectors quantified, and the unmetered rest.
 */
export function EnergyFlowCard() {
  const { t } = useTranslation()
  const query = useEnergyFlow()
  if (query.isPending) return <Skeleton className="h-80 rounded-lg" />
  if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />
  const flow = query.data.data
  if (!flow.site_kwh) return null

  // Middle column: the big machines, "Others", and what isn't metered per machine.
  const big = flow.machines.filter((m) => m.kwh / flow.site_kwh >= MIN_SHARE).slice(0, 5)
  const small = flow.machines.filter((m) => !big.includes(m))
  const middle = [
    ...big.map((m, i) => ({ key: m.code, label: m.code, title: m.name, kwh: m.kwh, waste: m.waste_kwh, color: SERIES[i], to: `/machines/${m.id}` })),
    ...(small.length ? [{ key: 'others', label: t('flow.others', { count: small.length }), title: small.map((m) => m.code).join(', '), kwh: small.reduce((s, m) => s + m.kwh, 0), waste: small.reduce((s, m) => s + m.waste_kwh, 0), color: 'var(--series-other)' }] : []),
    ...(flow.unmetered_kwh > 0 ? [{ key: 'unmetered', label: t('flow.unmetered'), title: t('flow.unmeteredHint'), kwh: flow.unmetered_kwh, waste: 0, color: 'var(--border-strong)', unmetered: true }] : []),
  ]
  const total = middle.reduce((s, m) => s + m.kwh, 0)
  const scale = (H - GAP * (middle.length - 1)) / total
  const mid = stack(middle, scale)
  const outcomeItems = [
    { key: 'productive', label: t('flow.productive'), kwh: flow.productive_kwh, color: 'var(--brand)' },
    { key: 'waste', label: t('flow.waste'), kwh: flow.waste_kwh, color: 'var(--warning)' },
    ...(flow.unmetered_kwh > 0 ? [{ key: 'unmetered', label: t('flow.notPerMachine'), kwh: flow.unmetered_kwh, color: 'var(--border-strong)' }] : []),
  ].filter((o) => o.kwh > 0)
  // Centred vertically: the outcome column has fewer gaps than the machine column.
  const outcomes = stack(outcomeItems, scale, (H - (outcomeItems.reduce((s, o) => s + o.kwh, 0) * scale + GAP * (outcomeItems.length - 1))) / 2)
  const out = Object.fromEntries(outcomes.map((o) => [o.key, { ...o, cursor: o.y }]))

  // Links: grid → each middle node, then each middle node → productive / waste / unmetered.
  let gridCursor = (H - total * scale) / 2
  const links = []
  for (const node of mid) {
    const w = node.kwh * scale
    links.push({ key: `g-${node.key}`, d: band(X_GRID + NODE, gridCursor, X_MACHINES, node.y, w), color: node.color, opacity: 0.32, title: `${node.title}: ${formatKwh(node.kwh)}` })
    gridCursor += w
    let cursor = node.y
    const parts = node.unmetered ? [['unmetered', node.kwh]] : [['productive', node.kwh - node.waste], ['waste', node.waste]]
    for (const [target, kwh] of parts) {
      if (kwh <= 0 || !out[target]) continue
      const pw = kwh * scale
      links.push({
        key: `${node.key}-${target}`, d: band(X_MACHINES + NODE, cursor, X_OUT, out[target].cursor, pw),
        color: target === 'waste' ? 'var(--warning)' : node.color, opacity: target === 'waste' ? 0.6 : 0.2,
        title: `${node.title} → ${out[target].label}: ${formatKwh(kwh)}`,
      })
      cursor += pw
      out[target].cursor += pw
    }
  }
  const gridTop = (H - total * scale) / 2
  const midLabels = spread(mid.map((n) => n.y + n.h / 2 + 4), 15)
  const outLabels = spread(outcomes.map((o) => o.y + Math.min(o.h / 2, 14) + 4), 34)
  const height = Math.max(H + 8, midLabels.at(-1) + 8, outLabels.at(-1) + 22)
  const share = (kwh) => formatPercent(kwh / flow.site_kwh)
  const halo = { paintOrder: 'stroke', stroke: 'var(--surface)', strokeWidth: 4, strokeLinejoin: 'round' }

  return (
    <Card>
      <CardHeader
        title={t('flow.title')}
        description={`${formatKwh(flow.site_kwh)} · ${formatEur(flow.site_eur)} · ${formatCo2(flow.site_co2_kg)}`}
        actions={<Link to="/waste" className="text-[13px] font-medium text-brand hover:underline">{t('flow.wasteLink')}</Link>}
      />
      <CardBody>
        <div className="overflow-x-auto">
          <svg viewBox={`0 0 ${W} ${height}`} className="h-auto w-full min-w-[620px]" role="img" aria-label={t('flow.aria', { kwh: formatKwh(flow.site_kwh), waste: formatKwh(flow.waste_kwh) })}>
            {links.map((l) => (
              <path key={l.key} d={l.d} fill={l.color} fillOpacity={l.opacity}>
                <title>{l.title}</title>
              </path>
            ))}
            {/* Grid */}
            <rect x={X_GRID} y={gridTop} width={NODE} height={total * scale} rx={2} fill="var(--text-2)" />
            <text x={X_GRID - 8} y={H / 2 - 6} textAnchor="end" className="fill-ink text-[12px] font-semibold">{t('flow.grid')}</text>
            <text x={X_GRID - 8} y={H / 2 + 10} textAnchor="end" className="fill-ink-2 text-[11px]">{formatKwh(flow.site_kwh)}</text>
            {/* Machines */}
            {mid.map((node, i) => (
              <g key={node.key}>
                <rect x={X_MACHINES} y={node.y} width={NODE} height={node.h} rx={2} fill={node.color}>
                  <title>{`${node.title}: ${formatKwh(node.kwh)} (${share(node.kwh)})`}</title>
                </rect>
                <text x={X_MACHINES + NODE + 6} y={midLabels[i]} className="fill-ink text-[11.5px] font-medium" style={halo}>
                  {node.label} <tspan className="fill-ink-2 font-normal">{formatKwh(node.kwh)}</tspan>
                </text>
              </g>
            ))}
            {/* Outcomes */}
            {outcomes.map((o, i) => (
              <g key={o.key}>
                <rect x={X_OUT} y={o.y} width={NODE} height={o.h} rx={2} fill={o.color} />
                <text x={X_OUT + NODE + 8} y={outLabels[i]} className="fill-ink text-[12px] font-semibold">{o.label}</text>
                <text x={X_OUT + NODE + 8} y={outLabels[i] + 15} className="fill-ink-2 text-[11px]">
                  {formatKwh(o.kwh)} · {share(o.kwh)}{o.key === 'waste' ? ` · ${formatEur(flow.waste_eur)}` : ''}
                </text>
              </g>
            ))}
          </svg>
        </div>
        <p className="mt-2 text-[12px] text-ink-3">{t('flow.note')}</p>
      </CardBody>
    </Card>
  )
}

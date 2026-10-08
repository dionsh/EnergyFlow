import { formatNumber } from '../../lib/format'

// Recommendations come as title_key + params (never sentences), like alerts.
export function oppText(t, rec, number = formatNumber) {
  const p = rec.params ?? {}
  const values = {
    code: p.code ?? rec.machine?.code,
    machine: p.machine ?? rec.machine?.name,
    episodes: p.episodes,
    grace: p.grace_min,
    leak: p.leak_pct,
    pct: p.pct === undefined ? undefined : number(p.pct, 1),
    share: p.share_pct,
  }
  return {
    title: t(`opportunities.title.${rec.title_key}`, { ...values, defaultValue: rec.title_key }),
    body: t(`opportunities.body.${rec.title_key}`, { ...values, defaultValue: '' }),
  }
}

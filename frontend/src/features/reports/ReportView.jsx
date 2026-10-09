import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Download, FileCheck2, Pencil, Save } from 'lucide-react'
import i18n from '../../i18n'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formattersFor } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { useAuth } from '../../providers/AuthProvider'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, CardBody } from '../../components/ui/Card'
import { Logo } from '../../components/ui/Logo'
import { Modal } from '../../components/ui/Overlay'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useReport } from '../data'
import { oppText } from '../opportunities/oppText'

const SECTIONS = ['summary', 'changes', 'outlook']

function Section({ number, title, children, className }) {
  return (
    <section className={cn('avoid-break mt-8 border-t border-line pt-5', className)}>
      <h2 className="mb-3 flex items-baseline gap-2 text-[15px] font-semibold text-ink">
        <span className="tabular text-ink-3">{number}</span>
        {title}
      </h2>
      {children}
    </section>
  )
}

function Table({ head, rows, align = [] }) {
  return (
    <table className="w-full border-collapse text-[12.5px]">
      <thead>
        <tr className="border-b border-line-strong text-left text-[11.5px] text-ink-3">
          {head.map((h, i) => <th key={h} className={cn('py-1.5 pr-3 font-medium last:pr-0', align[i] === 'right' && 'text-right')}>{h}</th>)}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, r) => (
          <tr key={r} className="border-b border-line last:border-0">
            {row.map((cell, i) => <td key={i} className={cn('py-1.5 pr-3 align-top text-ink last:pr-0', align[i] === 'right' && 'whitespace-nowrap text-right tabular')}>{cell}</td>)}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function Figure({ label, value, note }) {
  return (
    <div className="rounded-md border border-line px-3 py-2.5">
      <p className="text-[11.5px] text-ink-2">{label}</p>
      <p className="mt-0.5 text-[17px] font-semibold leading-tight tracking-[-0.01em] text-ink">{value}</p>
      {note && <p className="mt-0.5 text-[11.5px] text-ink-3">{note}</p>}
    </div>
  )
}

/** The report as a document, in its own language whatever the interface language is. */
function ReportDocument({ report, editing, draft, setDraft }) {
  const T = i18n.getFixedT(report.language)
  const f = formattersFor(report.language)
  const s = report.snapshot
  const n = report.narrative
  const day = (date) => f.formatDate(`${date}T12:00:00Z`)
  const pct = (v) => (v === null || v === undefined ? '—' : f.formatPercent(v))
  const change = (a, b) => (b ? `${a >= b ? '+' : '−'}${f.formatPercent(Math.abs(a / b - 1))}` : '—')
  const top = s.machines[0]
  const ivStatus = (iv) => {
    if (iv.verified) return T('impactPage.status.verified')
    if (iv.collecting) return T('impactPage.status.collecting', { days: iv.reporting_days, min: s.impact.min_reporting_days })
    return T('impactPage.status.inconclusive')
  }
  const narrativeNote = { ai: 'narrativeAi', template: 'narrativeTemplate', edited: 'narrativeEdited' }[report.narrative_source]

  const text = (key) => editing ? (
    <textarea
      value={draft[key] ?? ''}
      onChange={(e) => setDraft((d) => ({ ...d, [key]: e.target.value }))}
      rows={5}
      className="w-full rounded-sm border border-line-strong bg-surface px-3 py-2 text-[13px] leading-relaxed text-ink"
    />
  ) : <p className="text-[13px] leading-relaxed text-ink">{n[key]}</p>

  return (
    <article lang={report.language} className="report-sheet mx-auto w-full max-w-[210mm] rounded-md border border-line bg-surface px-6 py-8 sm:px-12 sm:py-12">
      <header className="flex flex-wrap items-start justify-between gap-4 border-b-2 border-ink pb-5">
        <div>
          <Logo />
          <h1 className="mt-5 text-[22px] font-semibold leading-tight tracking-[-0.01em] text-ink">{T(`report.title.${report.type.split('_')[0]}`)}</h1>
          <p className="mt-1 text-[15px] text-ink">{s.company.name}{s.company.legal_form && !s.company.name.includes(s.company.legal_form) ? ` ${s.company.legal_form}` : ''}{s.company.city ? ` · ${s.company.city}` : ''}</p>
        </div>
        <div className="text-right text-[12.5px] text-ink-2">
          <p className="font-medium text-ink">{T('report.period')}</p>
          <p>{day(report.period_start)} – {day(report.period_end)} {s.period.partial && T('report.periodPartial')}</p>
          <p className="mt-2">{T('report.generated', { date: f.formatDate(s.generated_at, 'dateTime') })}</p>
          <Badge tone={report.status === 'final' ? 'good' : 'neutral'} className="mt-2">{T(`report.${report.status}`)}</Badge>
        </div>
      </header>
      {s.company.demo && <p className="mt-3 rounded-sm bg-info-subtle px-3 py-1.5 text-[12px] font-medium text-info-text">{T('report.demo')}</p>}

      <Section number="1" title={T('report.sections.summary')} className="mt-6 border-t-0 pt-0">
        {text('summary')}
        {narrativeNote && <p className="mt-2 text-[11.5px] text-ink-3">{T(`report.${narrativeNote}`)}</p>}
      </Section>

      <Section number="2" title={T('report.sections.keyFigures')}>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <Figure label={T('report.kf.energy')} value={f.formatKwh(s.energy.kwh)} />
          <Figure label={T('report.kf.cost')} value={f.formatEur(s.energy.eur, { compact: false })} />
          <Figure label={T('report.kf.co2')} value={f.formatCo2(s.carbon.scope2_location_kg)} />
          <Figure label={T('report.kf.waste')} value={f.formatKwh(s.waste.kwh)} note={`${f.formatEur(s.waste.eur)} · ${T('report.kf.wasteShare', { share: pct(s.waste.share) })}`} />
          <Figure label={T('report.kf.saved')} value={f.formatEur(s.impact.verified.eur)} note={`${f.formatKwh(s.impact.verified.kwh)} · ${f.formatCo2(s.impact.verified.co2_kg)}`} />
          {s.energy.bill && <Figure label={T('report.kf.bill')} value={f.formatEur(s.energy.bill.subtotal, { compact: false })} note={`${T('report.kf.peak')} ${f.formatKw(s.energy.peak_kw)}`} />}
          {top && <Figure label={T('report.kf.topConsumer')} value={top.code} note={`${f.formatKwh(top.kwh)} · ${pct(top.share)}`} />}
          {s.waste.largest && <Figure label={T('report.kf.largestWaste')} value={s.waste.largest.code} note={`${f.formatKwh(s.waste.largest.kwh)} · ${f.formatEur(s.waste.largest.eur)}`} />}
        </div>
        <p className="mt-2 text-[12px] text-ink-2">
          {T('report.kf.opportunities')}: {T('report.kf.opportunitiesValue', { count: s.recommendations.length, eur: f.formatEur(s.recommendations.reduce((sum, r) => sum + r.eur_month, 0)) })}
        </p>
      </Section>

      <Section number="3" title={T('report.sections.energy')}>
        <Table
          head={[T('report.table.machine'), T('report.table.energy'), T('report.table.share'), T('report.table.cost'), T('report.table.co2')]}
          align={['left', 'right', 'right', 'right', 'right']}
          rows={s.machines.map((m) => [
            <span key="m">{m.name} <span className="font-mono text-[11px] text-ink-3">{m.code}</span></span>,
            f.formatNumber(m.kwh, 0),
            <span key="s" className="inline-flex items-center justify-end gap-2">
              <span className="relative hidden h-1.5 w-16 overflow-hidden rounded-full bg-surface-2 sm:inline-block print:inline-block" aria-hidden="true">
                <span className="absolute inset-y-0 left-0 rounded-full bg-[var(--series-1)]" style={{ width: `${m.share * 100}%` }} />
              </span>
              {pct(m.share)}
            </span>,
            f.formatEur(m.eur),
            f.formatCo2(m.co2_kg),
          ])}
        />
      </Section>

      <Section number="4" title={T('report.sections.waste')}>
        <Table
          head={[T('report.table.type'), T('report.table.events'), T('report.table.energy'), T('report.table.cost'), T('report.table.co2')]}
          align={['left', 'right', 'right', 'right', 'right']}
          rows={s.waste.by_type.map((w) => [T(`wasteTypes.${w.type}`), w.events, f.formatNumber(w.kwh, 0), f.formatEur(w.eur), f.formatCo2(w.co2_kg)])}
        />
        {s.waste.by_machine.length > 0 && (
          <p className="mt-2 text-[12px] text-ink-2">
            {s.waste.by_machine.map((w) => `${w.machine.code}: ${f.formatKwh(w.kwh)} (${f.formatEur(w.eur)})`).join(' · ')}
          </p>
        )}
      </Section>

      <Section number="5" title={T('report.sections.actions')}>
        <p className="mb-3 text-[12.5px] text-ink-2">
          {T('report.turnOffs', { manual: s.actions.turn_offs_manual, automatic: s.actions.turn_offs_automatic, policies: s.actions.active_policies })}
        </p>
        {s.impact.interventions.length === 0 ? (
          <p className="text-[12.5px] text-ink-3">{T('report.noImpact')}</p>
        ) : (
          <Table
            head={[T('report.table.intervention'), T('report.table.saved'), '€', T('report.table.co2'), T('report.table.status')]}
            align={['left', 'right', 'right', 'right', 'left']}
            rows={s.impact.interventions.map((iv) => [
              `${iv.machine} · ${T(`impactPage.actions.${iv.label}`, { grace: iv.grace_min ?? '—', defaultValue: iv.label })}`,
              `${f.formatNumber(iv.savings_kwh, 0)} ± ${f.formatNumber(iv.ci90_kwh, 0)} kWh`,
              f.formatEur(iv.savings_eur),
              f.formatCo2(iv.savings_co2_kg),
              ivStatus(iv),
            ])}
          />
        )}
      </Section>

      <Section number="6" title={T('report.sections.carbon')}>
        <Table
          head={[T('report.table.measure'), '']}
          align={['left', 'right']}
          rows={[
            [T('report.scope1'), s.carbon.scope1.status === 'missing' ? T('report.notReported') : f.formatCo2(s.carbon.scope1.kg)],
            [T('report.scope2'), f.formatCo2(s.carbon.scope2_location_kg)],
            [T('report.scope2Market'), T('report.notAvailable')],
            [T('report.intensity'), s.carbon.intensity_kg_per_k_eur === null ? '—' : `${f.formatNumber(s.carbon.intensity_kg_per_k_eur, 1)} ${T('carbonPage.tiles.intensityUnit')}`],
          ]}
        />
        <p className="mt-2 text-[11.5px] text-ink-3">{T('report.factorLine', { value: f.formatNumber(s.factor.value, 3), source: s.factor.source, year: s.factor.year, methodology: s.factor.methodology })}</p>
      </Section>

      <Section number="7" title={T('report.sections.comparison')}>
        {text('changes')}
        <div className="mt-3">
          <Table
            head={[T('report.table.measure'), T('report.table.current'), T('report.table.previous'), T('report.table.change')]}
            align={['left', 'right', 'right', 'right']}
            rows={[
              [T('report.kf.energy'), f.formatKwh(s.energy.kwh), f.formatKwh(s.previous.kwh), change(s.energy.kwh, s.previous.kwh)],
              [T('report.kf.cost'), f.formatEur(s.energy.eur), f.formatEur(s.previous.eur), change(s.energy.eur, s.previous.eur)],
              [T('report.kf.co2'), f.formatCo2(s.carbon.scope2_location_kg), f.formatCo2(s.previous.co2_kg), change(s.carbon.scope2_location_kg, s.previous.co2_kg)],
            ]}
          />
        </div>
      </Section>

      <Section number="8" title={T('report.sections.recommendations')}>
        {text('outlook')}
        {s.recommendations.length > 0 && (
          <div className="mt-3">
            <Table
              head={[T('report.table.action'), T('report.table.perMonth'), 'kWh', T('report.table.co2'), T('report.table.impact')]}
              align={['left', 'right', 'right', 'right', 'left']}
              rows={s.recommendations.map((r) => [
                oppText(T, r, f.formatNumber).title,
                f.formatEur(r.eur_month),
                f.formatNumber(r.kwh_month, 0),
                f.formatCo2(r.co2_kg_month),
                T(`opportunities.tag.${r.impact_tag}`),
              ])}
            />
          </div>
        )}
      </Section>

      <Section number="9" title={T('report.sections.vsme')} className="page-break">
        {s.vsme_b3_scope && (
          <p className="mb-3 text-[12.5px] text-ink-2">
            {T('report.vsmeScope', {
              year: s.vsme_b3_scope.year,
              from: f.formatDate(s.data_quality.monitoring_since && s.data_quality.monitoring_since > s.vsme_b3_scope.from ? s.data_quality.monitoring_since : s.vsme_b3_scope.from),
              to: f.formatDate(s.vsme_b3_scope.to),
            })}
          </p>
        )}
        <Table
          head={[T('carbonPage.vsmeTable.datapoint'), T('carbonPage.vsmeTable.value'), T('carbonPage.vsmeTable.status')]}
          align={['left', 'right', 'left']}
          rows={s.vsme_b3.map((row) => [
            T(`carbonPage.vsme.${row.key}`),
            row.value === null ? '—' : `${f.formatNumber(row.value, 2)} ${row.unit.replace('CO2e', 'CO₂e')}`,
            T(`carbonPage.statusChip.${row.status}`),
          ])}
        />
        <p className="mt-2 text-[11.5px] text-ink-3">{T('carbonPage.vsmeDisclaimer')}</p>
      </Section>

      <Section number="10" title={T('report.sections.methodology')}>
        <ul className="flex flex-col gap-1.5 text-[12px] leading-snug text-ink-2">
          {s.data_quality.coverage !== null && <li>{T('report.coverage', { share: pct(s.data_quality.coverage) })}</li>}
          {s.data_quality.monitoring_since && <li>{T('report.monitoringSince', { date: f.formatDate(s.data_quality.monitoring_since) })}</li>}
          <li>{T('report.devices', { total: s.data_quality.devices, simulated: s.data_quality.simulated_devices })}</li>
          <li>{T('report.factorLine', { value: f.formatNumber(s.factor.value, 3), source: s.factor.source, year: s.factor.year, methodology: s.factor.methodology })} {s.factor.url}</li>
          {s.tariff && <li>{T('report.tariffLine', { name: s.tariff.name, note: s.tariff.source_note })}</li>}
          <li>{T('report.mvLine')}</li>
          {s.company.demo && <li className="font-medium text-ink">{T('report.simulatedLine')}</li>}
        </ul>
      </Section>

      <footer className="mt-10 flex justify-between border-t border-line pt-3 text-[11px] text-ink-3">
        <span>{T('report.footer')}</span>
        <span>{report.finalized_at ? T('report.finalizedBy', { name: report.finalized_by, date: f.formatDate(report.finalized_at) }) : T('report.draft')}</span>
      </footer>
    </article>
  )
}

export function ReportView() {
  const { t } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const query = useReport(id)
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState({})
  const [confirming, setConfirming] = useState(false)
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['reports'] })
  const save = useMutation({
    mutationFn: () => api.patch(`/reports/${id}/narrative`, draft),
    onSuccess: () => {
      setEditing(false)
      refresh()
    },
  })
  const finalize = useMutation({
    mutationFn: () => api.post(`/reports/${id}/finalize`),
    onSuccess: () => {
      setConfirming(false)
      refresh()
    },
  })

  if (query.isPending) return <Skeleton className="mx-auto h-[80vh] max-w-[210mm]" />
  if (query.isError) {
    return query.error.status === 404 ? <Card><EmptyState title={t('notFound.title')} /></Card> : <ErrorState error={query.error} onRetry={query.refetch} />
  }
  const report = query.data.data
  const draftable = report.status === 'draft' && hasRole(user, 'manager')

  // The browser's print engine makes the PDF: vector text, the same fonts, A4 pages.
  // The document title becomes the suggested file name.
  const download = () => {
    const previous = document.title
    const slug = report.snapshot.company.name.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '')
    document.title = `EnergyFlow_${slug}_${report.snapshot.period.month.replace(/[^\p{L}\p{N}-]+/gu, '-')}`
    const restore = () => {
      document.title = previous
      window.removeEventListener('afterprint', restore)
    }
    window.addEventListener('afterprint', restore)
    window.print()
  }

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <Link to="/reports" className="inline-flex items-center gap-1.5 text-[13px] text-ink-2 hover:text-ink">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {t('report.back')}
        </Link>
        <div className="flex flex-wrap items-center gap-2">
          {draftable && !editing && (
            <Button variant="secondary" icon={Pencil} onClick={() => { setDraft(Object.fromEntries(SECTIONS.map((k) => [k, report.narrative[k] ?? '']))); setEditing(true) }}>
              {t('report.edit')}
            </Button>
          )}
          {editing && <Button variant="secondary" icon={Save} loading={save.isPending} onClick={() => save.mutate()}>{t('report.save')}</Button>}
          {report.status === 'draft' && hasRole(user, 'admin') && !editing && (
            <Button variant="secondary" icon={FileCheck2} onClick={() => setConfirming(true)}>{t('report.finalize')}</Button>
          )}
          <Button icon={Download} onClick={download} title={t('report.downloadHint')} disabled={editing}>{t('report.download')}</Button>
        </div>
      </div>
      <CardBody className="px-0 py-0">
        <ReportDocument report={report} editing={editing} draft={draft} setDraft={setDraft} />
      </CardBody>
      <Modal
        open={confirming}
        onClose={() => setConfirming(false)}
        title={t('report.finalize')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirming(false)}>{t('common.cancel')}</Button>
            <Button icon={FileCheck2} loading={finalize.isPending} onClick={() => finalize.mutate()}>{t('report.finalize')}</Button>
          </>
        }
      >
        {t('report.finalizeConfirm')}
      </Modal>
    </>
  )
}

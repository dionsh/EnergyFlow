import { Download, Printer } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatCo2, formatEur, formatNumber } from '../../lib/format'

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char])
const csvCell = (value) => {
  const safe = String(value ?? '').replace(/^[=+@]/, "'").replace(/^-[^0-9]/, "'-$&")
  return `"${safe.replace(/"/g, '""')}"`
}

function reportMonth(iso, language) {
  return new Intl.DateTimeFormat(language, { month: 'long', year: 'numeric', timeZone: 'Europe/Belgrade' }).format(new Date(iso))
}

export function ReportExport({ overview, machines = [], company, disabled = false }) {
  const { t, i18n } = useTranslation()
  const monthName = overview?.now ? reportMonth(overview.now, i18n.language) : t('report.waitingForData')
  const rows = [...machines].sort((a, b) => b.month.kwh - a.month.kwh)
  const totalCost = overview?.month?.bill_so_far?.subtotal ?? null
  const filename = `${(company?.name ?? 'energyflow').replace(/[^\p{L}\p{N}-]+/gu, '-').toLowerCase()}-${monthName.replace(/[^\p{L}\p{N}-]+/gu, '-').toLowerCase()}`

  function exportCsv() {
    const data = [
      [t('report.month'), monthName],
      [t('report.company'), company?.name ?? ''],
      [t('report.totalEnergy'), overview?.month?.kwh ?? '', 'kWh'],
      [t('report.totalCost'), totalCost ?? '', 'EUR'],
      [t('report.totalCo2'), overview?.month?.co2_kg ?? '', 'kg CO2e'],
      [],
      [t('report.machine'), t('report.energy'), t('report.cost'), t('report.co2')],
      ...rows.map((machine) => [machine.name, machine.month.kwh, machine.month.eur, machine.month.co2_kg]),
    ]
    const csv = '\uFEFF' + data.map((row) => row.map(csvCell).join(',')).join('\r\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `${filename}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  function printPdf() {
    const printWindow = window.open('', '_blank')
    if (!printWindow) return
    const machineRows = rows.map((machine) => `<tr><td>${esc(machine.name)}</td><td>${esc(formatNumber(machine.month.kwh, 1))} kWh</td><td>${esc(formatEur(machine.month.eur, { compact: false }))}</td><td>${esc(formatCo2(machine.month.co2_kg))}</td></tr>`).join('')
    printWindow.document.write(`<!doctype html><html lang="${esc(i18n.language)}"><head><meta charset="utf-8"><title>${esc(t('report.title'))} · ${esc(monthName)}</title><style>
      body{font:14px/1.5 Arial,sans-serif;color:#182321;margin:40px}h1{font-size:24px;margin:0 0 4px}h2{font-size:16px;margin:28px 0 8px}.muted{color:#58645f}.summary{display:flex;gap:28px;margin:24px 0}.summary div{border:1px solid #dce3df;border-radius:8px;padding:14px;min-width:130px}.label{font-size:12px;color:#58645f}.value{font-size:18px;font-weight:700;margin-top:4px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #dce3df}th{font-size:12px;color:#58645f}@media print{body{margin:18mm}}
      </style></head><body><h1>${esc(t('report.title'))}</h1><div class="muted">${esc(company?.name ?? '')} · ${esc(monthName)}</div><div class="summary"><div><div class="label">${esc(t('report.totalEnergy'))}</div><div class="value">${esc(formatNumber(overview.month.kwh, 1))} kWh</div></div><div><div class="label">${esc(t('report.totalCost'))}</div><div class="value">${esc(totalCost === null ? '—' : formatEur(totalCost, { compact: false }))}</div></div><div><div class="label">${esc(t('report.totalCo2'))}</div><div class="value">${esc(formatCo2(overview.month.co2_kg))}</div></div></div><h2>${esc(t('report.byMachine'))}</h2><table><thead><tr><th>${esc(t('report.machine'))}</th><th>${esc(t('report.energy'))}</th><th>${esc(t('report.cost'))}</th><th>${esc(t('report.co2'))}</th></tr></thead><tbody>${machineRows || `<tr><td colspan="4">${esc(t('report.noMachines'))}</td></tr>`}</tbody></table><p class="muted">${esc(t('report.costNote'))}</p><script>window.onload=()=>window.print()</script></body></html>`)
    printWindow.document.close()
  }

  return (
    <div className="flex items-center gap-2">
      <button type="button" onClick={exportCsv} disabled={disabled} title={disabled ? t('report.waitingForData') : undefined} className="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm font-medium text-ink hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50">
        <Download className="size-4" aria-hidden="true" />{t('report.csv')}
      </button>
      <button type="button" onClick={printPdf} disabled={disabled} title={disabled ? t('report.waitingForData') : undefined} className="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm font-medium text-ink hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50">
        <Printer className="size-4" aria-hidden="true" />{t('report.pdf')}
      </button>
    </div>
  )
}

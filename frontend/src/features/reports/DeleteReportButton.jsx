import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button } from '../../components/ui/Button'
import { Modal } from '../../components/ui/Overlay'
import { Callout } from '../../components/ui/States'

export function DeleteReportButton({ report }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { errorMessage } = useApiErrors()
  const [open, setOpen] = useState(false)
  const remove = useMutation({
    mutationFn: () => api.delete(`/reports/${report.id}`),
    onSuccess: () => {
      setOpen(false)
      client.removeQueries({ queryKey: ['report', String(report.id)] })
      client.invalidateQueries({ queryKey: ['reports'] })
    },
  })
  function close() { if (!remove.isPending) setOpen(false) }
  return <>
    <Button variant="secondary" size="sm" icon={Trash2} onClick={() => { remove.reset(); setOpen(true) }} aria-label={t('reportsPage.deleteReport.title')}>{t('common.delete')}</Button>
    <Modal open={open} onClose={close} title={t('reportsPage.deleteReport.title')} footer={<>
      <Button variant="secondary" disabled={remove.isPending} onClick={close}>{t('common.cancel')}</Button>
      <Button icon={Trash2} loading={remove.isPending} onClick={() => remove.mutate()}>{t('common.delete')}</Button>
    </>}>
      <p>{t('reportsPage.deleteReport.confirm')}</p>
      <p className="mt-2 text-sm text-ink-2">{report.period_start} – {report.period_end}</p>
      {remove.isError && <Callout tone="critical" className="mt-3">{errorMessage(remove.error)}</Callout>}
    </Modal>
  </>
}

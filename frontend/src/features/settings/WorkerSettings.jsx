import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '../../components/ui/Button'
import { Field } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Overlay'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { useAuth } from '../../providers/AuthProvider'

export function WorkerSettings() {
  const { user } = useAuth()
  if (user?.role !== 'owner') return null
  return <WorkerForm />
}

function WorkerForm() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const { data = [], isError } = useQuery({ queryKey: ['company-workers'], queryFn: async () => (await api.get('/company/workers')).data })
  async function submit(event) {
    event.preventDefault()
    const form = event.currentTarget
    setBusy(true)
    setError('')
    try {
      await api.post('/company/workers', Object.fromEntries(new FormData(form)))
      form.reset()
      await client.invalidateQueries({ queryKey: ['company-workers'] })
    } catch (err) {
      setError(err.fields?.email === 'email_taken' ? t('approval.taken') : err.message)
    } finally { setBusy(false) }
  }
  return <section className="mt-8 rounded-xl border border-line p-6">
    <h2 className="text-lg font-semibold">{t('approval.workers')}</h2>
    <p className="mb-4 text-sm text-ink-2">{t('approval.hint')}</p>
    <form onSubmit={submit} className="grid gap-3">
      <Field label={t('approval.name')} name="full_name" required minLength={2} maxLength={120} />
      <Field label={t('approval.email')} name="email" type="email" required maxLength={190} />
      <Field label={t('approval.password')} name="password" type="password" autoComplete="new-password" required minLength={10} maxLength={200} />
      {error && <p role="alert">{error}</p>}
      <Button loading={busy} type="submit">{t('approval.add')}</Button>
    </form>
    {isError && <p role="alert">{t('approval.loadError')}</p>}
    <ul className="mt-4">{data.map(worker => <li key={worker.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-line py-2"><span>{worker.full_name} · {worker.email} · {t(worker.role === 'owner' ? 'approval.company' : 'approval.worker')}</span>{['viewer', 'manager'].includes(worker.role) && <EditWorker worker={worker} label={t('approval.edit')} />}</li>)}</ul>
  </section>
}

function EditWorker({ worker, label }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [open, setOpen] = useState(false)
  const [name, setName] = useState(worker.full_name)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function save(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await api.patch(`/company/workers/${worker.id}`, { full_name: name })
      await client.invalidateQueries({ queryKey: ['company-workers'] })
      setOpen(false)
    } catch (caught) { setError(caught.message) }
    finally { setBusy(false) }
  }
  return <><Button variant="secondary" size="sm" onClick={() => { setName(worker.full_name); setError(''); setOpen(true) }}>{label}</Button>
    <Modal open={open} onClose={() => { if (!busy) setOpen(false) }} title={label}>
      <form onSubmit={save} className="grid gap-4"><Field label={t('approval.name')} value={name} onChange={event => setName(event.target.value)} required minLength={2} maxLength={120} />
        {error && <p role="alert">{error}</p>}
        <div className="flex gap-2"><Button loading={busy} type="submit">{t('approval.save')}</Button><Button variant="secondary" disabled={busy} onClick={() => setOpen(false)}>{t('common.cancel')}</Button></div>
      </form>
    </Modal></>
}

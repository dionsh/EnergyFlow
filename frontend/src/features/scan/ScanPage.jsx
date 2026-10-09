import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CircleAlert, LoaderCircle, Lock, RefreshCw, RotateCcw, Save, ShieldCheck } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { hasRole } from '../../lib/roles'
import { useApiErrors } from '../../hooks/useApiErrors'
import { useAuth } from '../../providers/AuthProvider'
import { useToast } from '../../providers/ToastProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, CardBody } from '../../components/ui/Card'
import { Select } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'
import { useMachines } from '../data'
import { CameraCapture } from './CameraCapture'
import { ScanHistory } from './ScanHistory'
import { ScanFieldsForm, ScanResult } from './ScanParts'
import { SCAN_KINDS, emptyFields, kindFor, toApi, toForm } from './scanKinds'

/** Where a saved scan shows up. */
const AFTER_SAVE = { bill: null, meter: null, nameplate: (id) => `/machines/${id}`, fuel: () => '/carbon' }
const INVALIDATE = { bill: [['bills']], meter: [['meter-readings']], nameplate: [['machines'], ['machine']], fuel: [['fuel-records'], ['carbon'], ['esg']] }

function KindPicker({ onPick }) {
  const { t } = useTranslation()
  return (
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      {SCAN_KINDS.map(({ kind, icon: Icon }) => (
        <button
          key={kind}
          type="button"
          onClick={() => onPick(kind)}
          className="group flex items-start gap-3.5 rounded-lg border border-line bg-surface p-4 text-left transition-colors hover:border-brand hover:bg-surface-2"
        >
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-brand">
            <Icon className="size-5" aria-hidden="true" />
          </span>
          <span className="min-w-0">
            <span className="block text-[14px] font-semibold text-ink">{t(`scan.kinds.${kind}.title`)}</span>
            <span className="mt-0.5 block text-[13px] leading-snug text-ink-2">{t(`scan.kinds.${kind}.body`)}</span>
          </span>
        </button>
      ))}
    </div>
  )
}

/** One scan: capture → read → review and check → save. */
function Scanner({ kind, initialMachine }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { errorMessage } = useApiErrors()
  const kindDef = kindFor(kind)
  const machines = useMachines()
  const [step, setStep] = useState('capture')
  const [photo, setPhoto] = useState(null)
  const [form, setForm] = useState(() => emptyFields(kind))
  const [source, setSource] = useState('manual')
  const [recognised, setRecognised] = useState(true)
  const [machineId, setMachineId] = useState(initialMachine ?? '')
  const [result, setResult] = useState(null)
  const [stale, setStale] = useState(false)
  const [saved, setSaved] = useState(null)
  const canSave = hasRole(user, 'manager') && kindDef.save !== null

  const payload = (fields) => ({ kind, fields, machine_id: machineId ? Number(machineId) : null, source })
  const check = useMutation({
    mutationFn: (fields) => api.post('/scan/check', payload(fields)),
    onSuccess: ({ data }) => {
      setResult(data)
      setStale(false)
    },
  })
  const read = useMutation({
    mutationFn: (image) => api.post('/scan/read', { kind, image }),
    onSuccess: ({ data }) => {
      setForm(toForm(kind, data.fields))
      setRecognised(data.recognised)
      setSource('scan')
      setStep('review')
      if (data.recognised) check.mutate(data.fields)
    },
    onError: () => setStep('review'),
  })
  const save = useMutation({
    mutationFn: () => api.post('/scan/save', payload(toApi(kind, form))),
    onSuccess: ({ data }) => {
      setResult(data.check)
      setSaved(data.saved)
      INVALIDATE[kind]?.forEach((queryKey) => queryClient.invalidateQueries({ queryKey }))
      toast({ tone: 'good', title: t(`scan.saved.${kind}`) })
    },
  })

  const onPhoto = (dataUrl) => {
    setPhoto(dataUrl)
    setStep('reading')
    read.mutate(dataUrl)
  }
  const onManual = () => {
    setSource('manual')
    setStep('review')
  }
  const change = (key, value) => {
    setForm((current) => ({ ...current, [key]: value }))
    setStale(true)
    setSaved(null)
  }
  const restart = () => {
    setStep('capture')
    setPhoto(null)
    setForm(emptyFields(kind))
    setResult(null)
    setSaved(null)
    setRecognised(true)
    read.reset()
    check.reset()
    save.reset()
  }

  if (step === 'capture') {
    return (
      <div className="mx-auto w-full max-w-2xl">
        <CameraCapture hint={t(`scan.kinds.${kind}.hint`)} onPhoto={onPhoto} onManual={onManual} />
        <p className="mt-3 flex items-start gap-1.5 text-[12px] text-ink-3">
          <Lock className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {t('scan.privacy')}
        </p>
      </div>
    )
  }
  if (step === 'reading') {
    return (
      <div className="mx-auto w-full max-w-2xl">
        <div className="relative overflow-hidden rounded-lg border border-line">
          <img src={photo} alt="" className="max-h-[60vh] w-full object-contain opacity-60" />
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-2" role="status">
            <LoaderCircle className="size-7 animate-spin text-brand" aria-hidden="true" />
            <p className="rounded-sm bg-surface/90 px-3 py-1 text-[13px] font-medium text-ink">{t('scan.reading')}</p>
          </div>
        </div>
      </div>
    )
  }

  const readError = read.isError ? read.error : null
  return (
    <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,420px)]">
      <Card>
        <CardBody className="flex flex-col gap-5">
          <div className="flex items-start gap-3">
            {photo && <img src={photo} alt={t('scan.photoAlt')} className="h-20 w-16 shrink-0 rounded-sm border border-line object-cover" />}
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[15px] font-semibold text-ink">{t('scan.review.title')}</h3>
                <Button size="sm" variant="secondary" icon={RotateCcw} onClick={restart}>{t('scan.retake')}</Button>
              </div>
              <p className="mt-1 text-[13px] text-ink-2">{t(source === 'scan' ? 'scan.review.fromPhoto' : 'scan.review.manual')}</p>
            </div>
          </div>
          {readError && (
            <Callout tone="critical" icon={CircleAlert}>
              {readError.status === 429 ? t('scan.busy') : errorMessage(readError)} {t('scan.typeInstead')}
            </Callout>
          )}
          {!recognised && <Callout tone="warning" icon={CircleAlert}>{t('scan.notRecognised', { kind: t(`scan.kinds.${kind}.title`).toLowerCase() })}</Callout>}
          {kindDef.needsMachine && (
            <div>
              <label htmlFor="scan-machine" className="mb-1.5 block text-[13px] font-medium text-ink">{t('scan.machine')}</label>
              <Select id="scan-machine" value={machineId} onChange={(e) => { setMachineId(e.target.value); setStale(true) }}>
                <option value="">{t('scan.chooseMachine')}</option>
                {(machines.data?.data ?? []).map((m) => <option key={m.id} value={m.id}>{m.code} · {m.name}</option>)}
              </Select>
            </div>
          )}
          <ScanFieldsForm kindDef={kindDef} form={form} onChange={change} disabled={save.isPending} />
          <div className="flex flex-wrap gap-2 border-t border-line pt-4">
            <Button icon={ShieldCheck} loading={check.isPending} onClick={() => check.mutate(toApi(kind, form))}>
              {result ? t('scan.checkAgain') : t('scan.checkButton')}
            </Button>
          </div>
        </CardBody>
      </Card>

      <div className="flex flex-col gap-4 xl:sticky xl:top-20">
        <Card>
          <CardBody>
            {check.isPending && !result ? (
              <p className="flex items-center gap-2 text-[13px] text-ink-2"><LoaderCircle className="size-4 animate-spin" aria-hidden="true" />{t('scan.checking')}</p>
            ) : result ? (
              <>
                {stale && <p className="mb-3 flex items-center gap-1.5 text-[12.5px] font-medium text-warning-text"><RefreshCw className="size-3.5" aria-hidden="true" />{t('scan.stale')}</p>}
                <ScanResult result={result} stale={stale} />
              </>
            ) : (
              <p className="text-[13px] text-ink-3">{t('scan.notCheckedYet')}</p>
            )}
            {check.isError && <p className="mt-3 text-[13px] text-critical-text">{errorMessage(check.error)}</p>}
          </CardBody>
        </Card>
        {result && kindDef.save !== null && (
          canSave ? (
            <div className="flex flex-col gap-2">
              <Button icon={Save} loading={save.isPending} disabled={stale || (kindDef.needsMachine && !machineId)} onClick={() => save.mutate()}>
                {t(`scan.actions.${kindDef.save}`)}
              </Button>
              {save.isError && <p className="text-[13px] text-critical-text">{errorMessage(save.error)}</p>}
              {saved && AFTER_SAVE[kind] && (
                <Link to={AFTER_SAVE[kind](machineId)} className="text-center text-[13px] font-medium text-brand hover:underline">{t(`scan.afterSave.${kind}`)}</Link>
              )}
            </div>
          ) : (
            <p className="flex items-start gap-1.5 text-[12.5px] text-ink-3"><Lock className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{t('scan.viewerNote')}</p>
          )
        )}
        <Button variant="ghost" icon={RotateCcw} onClick={restart} className={cn(!result && 'hidden xl:inline-flex')}>{t('scan.another')}</Button>
      </div>
    </div>
  )
}

export function ScanPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const kind = kindFor(params.get('kind'))?.kind ?? null
  const pick = (next) => setParams((current) => {
    const out = new URLSearchParams(current)
    if (next) out.set('kind', next)
    else {
      out.delete('kind')
      out.delete('machine')
    }
    return out
  })

  return (
    <>
      <PageHeader title={t('nav.scan')} subtitle={t('scan.subtitle')} />
      {kind ? (
        <>
          <button type="button" onClick={() => pick(null)} className="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-2 hover:text-ink">
            <ArrowLeft className="size-4" aria-hidden="true" />
            {t('scan.kinds.' + kind + '.title')} · {t('scan.change')}
          </button>
          <Scanner key={kind} kind={kind} initialMachine={params.get('machine')} />
        </>
      ) : (
        <KindPicker onPick={pick} />
      )}
      <ScanHistory />
    </>
  )
}

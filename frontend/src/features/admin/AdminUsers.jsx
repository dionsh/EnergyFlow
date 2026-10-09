import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Ban, CircleAlert, CircleCheck, KeyRound, Pencil, Search, ShieldCheck, Trash2, Users, X } from 'lucide-react'
import { api } from '../../lib/api'
import { formatAgo, formatDate } from '../../lib/format'
import { LANGUAGES } from '../../i18n/languages'
import { useApiErrors } from '../../hooks/useApiErrors'
import { useDebounced } from '../../hooks/useDebounced'
import { useNow } from '../../hooks/useNow'
import { useAuth } from '../../providers/AuthProvider'
import { useToast } from '../../providers/ToastProvider'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Overlay'
import { Callout, EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useAdminUsers } from '../data'

const ROLES = ['owner', 'admin', 'manager', 'viewer']

function useInvalidateAdmin() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['admin'] })
}

function EditUserModal({ user, onClose }) {
  const { t } = useTranslation()
  const toast = useToast()
  const invalidate = useInvalidateAdmin()
  const { errorMessage, fieldErrors } = useApiErrors()
  const [form, setForm] = useState({
    full_name: user.full_name,
    email: user.email,
    role: user.role,
    locale: user.locale ?? 'sq',
    disabled: user.disabled,
  })
  const save = useMutation({
    mutationFn: async () => {
      // Only what changed, so an unrelated rule (e.g. last owner) never blocks a rename.
      const changes = Object.fromEntries(Object.entries(form).filter(([key, value]) => value !== (user[key] ?? (key === 'locale' ? 'sq' : undefined))))
      return (await api.patch(`/admin/users/${user.id}`, changes)).data
    },
    onSuccess: (data) => {
      invalidate()
      toast({ title: t('admin.edit.saved', { name: data.full_name }) })
      onClose()
    },
  })
  const reset = useMutation({
    mutationFn: () => api.post(`/admin/users/${user.id}/password-reset`),
    onSuccess: () => {
      invalidate()
      toast({ title: t('admin.edit.resetSent', { email: user.email }) })
    },
  })
  const fields = fieldErrors(save.error)
  const update = (event) => {
    save.reset()
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: name === 'disabled' ? value === 'true' : value }))
  }
  const failure = [save.error, reset.error].find((error) => error && error.code !== 'validation_failed')

  return (
    <Modal
      open
      wide
      onClose={onClose}
      title={t('admin.edit.title', { name: user.full_name })}
      footer={
        <>
          <Button variant="secondary" icon={KeyRound} loading={reset.isPending} disabled={user.disabled} onClick={() => reset.mutate()} className="mr-auto">
            {t('admin.edit.sendReset')}
          </Button>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" form="admin-edit-user" loading={save.isPending}>
            {save.isPending ? t('common.saving') : t('common.save')}
          </Button>
        </>
      }
    >
      <form
        id="admin-edit-user"
        noValidate
        className="flex flex-col gap-4 pt-2"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('admin.edit.fullName')} name="full_name" value={form.full_name} onChange={update} error={fields.full_name} />
          <Field label={t('admin.edit.email')} name="email" type="email" value={form.email} onChange={update} error={fields.email} hint={form.email !== user.email ? t('admin.edit.emailHint') : undefined} />
        </div>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('admin.edit.role', { company: user.company.name })} error={fields.role}>
            {({ id, invalid }) => (
              <Select id={id} invalid={invalid} name="role" value={form.role} onChange={update}>
                {ROLES.map((role) => <option key={role} value={role}>{t(`roles.${role}`)}</option>)}
              </Select>
            )}
          </Field>
          <Field label={t('admin.edit.language')} error={fields.locale}>
            {({ id, invalid }) => (
              <Select id={id} invalid={invalid} name="locale" value={form.locale} onChange={update}>
                {LANGUAGES.map((language) => <option key={language.code} value={language.code}>{language.label}</option>)}
              </Select>
            )}
          </Field>
          <Field label={t('admin.edit.status')} error={fields.disabled}>
            {({ id, invalid }) => (
              <Select id={id} invalid={invalid} name="disabled" value={String(form.disabled)} onChange={update}>
                <option value="false">{t('admin.edit.enabled')}</option>
                <option value="true">{t('admin.edit.disabledLabel')}</option>
              </Select>
            )}
          </Field>
        </div>
        {failure && <Callout tone="critical" icon={CircleAlert}>{errorMessage(failure)}</Callout>}
      </form>
    </Modal>
  )
}

function DeleteUserModal({ user, onClose }) {
  const { t } = useTranslation()
  const toast = useToast()
  const invalidate = useInvalidateAdmin()
  const { errorMessage } = useApiErrors()
  const [typed, setTyped] = useState('')
  const withCompany = user.company.users === 1
  const ready = !withCompany || typed.trim() === user.company.name
  const remove = useMutation({
    mutationFn: async () => (await api.delete(`/admin/users/${user.id}${withCompany ? '?with_company=1' : ''}`)).data,
    onSuccess: (data) => {
      invalidate()
      toast({ title: data.company_deleted ? t('admin.delete.doneWithCompany', { email: user.email, company: user.company.name }) : t('admin.delete.done', { email: user.email }) })
      onClose()
    },
  })
  const values = { email: user.email, company: user.company.name }

  return (
    <Modal
      open
      onClose={onClose}
      title={t('admin.delete.title', { name: user.full_name })}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="confirmDanger" icon={Trash2} loading={remove.isPending} disabled={!ready} onClick={() => remove.mutate()}>
            {withCompany ? t('admin.delete.confirmWithCompany') : t('admin.delete.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{withCompany ? t('admin.delete.companyBody', values) : t('admin.delete.body', values)}</p>
        {withCompany && (
          <Field label={t('admin.delete.confirmCompany')} hint={user.company.name}>
            {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} value={typed} onChange={(event) => setTyped(event.target.value)} autoComplete="off" />}
          </Field>
        )}
        {remove.isError && <Callout tone="critical" icon={CircleAlert}>{errorMessage(remove.error)}</Callout>}
      </div>
    </Modal>
  )
}

export function AdminUsers({ companyId, onClearCompany }) {
  const { t } = useTranslation()
  const { user: me } = useAuth()
  const now = useNow(30_000)
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const q = useDebounced(search.trim(), 300)
  const users = useAdminUsers({ q, role, status, company_id: companyId ?? undefined, page })

  const filter = (setter) => (event) => {
    setter(event.target.value)
    setPage(1)
  }
  const rows = users.data?.data ?? []
  const meta = users.data?.meta ?? { total: 0, page: 1, page_size: 50 }
  const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.page_size + 1
  const to = Math.min(meta.total, meta.page * meta.page_size)
  const companyName = companyId && rows[0]?.company.id === Number(companyId) ? rows[0].company.name : null

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="relative w-full sm:w-72">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-3" aria-hidden="true" />
          <Input
            type="search"
            aria-label={t('admin.users.search')}
            placeholder={t('admin.users.search')}
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            className="pl-9"
          />
        </div>
        <div className="w-40">
          <Select aria-label={t('admin.users.role')} value={role} onChange={filter(setRole)}>
            <option value="">{t('admin.users.allRoles')}</option>
            {ROLES.map((r) => <option key={r} value={r}>{t(`roles.${r}`)}</option>)}
          </Select>
        </div>
        <div className="w-44">
          <Select aria-label={t('admin.users.status')} value={status} onChange={filter(setStatus)}>
            <option value="">{t('admin.users.allStatuses')}</option>
            <option value="active">{t('admin.users.active')}</option>
            <option value="disabled">{t('admin.users.disabled')}</option>
          </Select>
        </div>
        {companyId && (
          <Button variant="secondary" size="sm" icon={X} onClick={onClearCompany}>
            {t('admin.users.filteredBy', { company: companyName ?? `#${companyId}` })}
          </Button>
        )}
      </div>

      {users.isPending ? (
        <Skeleton className="h-96" />
      ) : users.isError ? (
        <ErrorState error={users.error} onRetry={users.refetch} />
      ) : (
        <Card className="overflow-hidden">
          {rows.length === 0 ? (
            <EmptyState icon={Users} title={t('admin.users.empty')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[980px] border-collapse text-[13px]">
                <thead>
                  <tr className="border-b border-line text-left text-xs text-ink-3">
                    <th className="px-4 py-2.5 font-medium">{t('admin.users.user')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.users.company')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.users.role')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.users.lastSeen')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.users.joined')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.users.status')}</th>
                    <th className="px-4 py-2.5 text-right font-medium">{t('admin.users.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((u) => {
                    const self = u.id === me.id
                    const lastSeen = u.last_seen_at ?? u.last_login_at
                    const locked = u.company.demo ? t('admin.users.demoLocked') : null
                    return (
                      <tr key={u.id} className="border-b border-line last:border-0 hover:bg-surface-2">
                        <td className="px-4 py-2.5">
                          <span className="flex items-center gap-2">
                            <span className="truncate font-medium text-ink">{u.full_name}</span>
                            {self && <Badge tone="brand">{t('admin.users.you')}</Badge>}
                            {u.is_platform_admin && <Badge tone="info" icon={ShieldCheck}>{t('admin.users.platformAdmin')}</Badge>}
                          </span>
                          <span className="block truncate text-xs text-ink-3">{u.email}</span>
                        </td>
                        <td className="px-3 py-2.5">
                          <span className="flex items-center gap-2">
                            <span className="truncate text-ink">{u.company.name}</span>
                            {u.company.demo && <Badge>{t('admin.users.demo')}</Badge>}
                          </span>
                        </td>
                        <td className="px-3 py-2.5 text-ink-2">{t(`roles.${u.role}`)}</td>
                        <td className="whitespace-nowrap px-3 py-2.5 text-ink-2">
                          {lastSeen ? formatAgo((now - Date.parse(lastSeen)) / 1000) : t('admin.users.never')}
                          {u.active_sessions > 0 && <span className="block text-xs text-ink-3">{t('admin.users.sessions', { count: u.active_sessions })}</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2.5 text-ink-2">{formatDate(u.created_at)}</td>
                        <td className="px-3 py-2.5">
                          {u.disabled ? (
                            <Badge icon={Ban}>{t('admin.users.disabled')}</Badge>
                          ) : (
                            <Badge tone="good" icon={CircleCheck}>{t('admin.users.active')}</Badge>
                          )}
                        </td>
                        <td className="px-4 py-2.5">
                          <div className="flex justify-end gap-2">
                            <Button variant="brand" size="sm" icon={Pencil} disabled={Boolean(locked)} title={locked ?? undefined} onClick={() => setEditing(u)}>
                              {t('admin.users.edit')}
                            </Button>
                            <Button
                              variant="danger"
                              size="sm"
                              icon={Trash2}
                              disabled={Boolean(locked) || self}
                              title={locked ?? (self ? t('admin.users.selfLocked') : undefined)}
                              onClick={() => setDeleting(u)}
                            >
                              {t('admin.users.delete')}
                            </Button>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          {meta.total > meta.page_size && (
            <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-[13px] text-ink-2">
              <span className="tabular">{t('admin.users.count', { from, to, total: meta.total })}</span>
              <span className="flex gap-2">
                <Button variant="secondary" size="sm" disabled={meta.page <= 1} onClick={() => setPage((p) => p - 1)}>{t('admin.users.previous')}</Button>
                <Button variant="secondary" size="sm" disabled={to >= meta.total} onClick={() => setPage((p) => p + 1)}>{t('admin.users.next')}</Button>
              </span>
            </div>
          )}
        </Card>
      )}

      {editing && <EditUserModal key={editing.id} user={editing} onClose={() => setEditing(null)} />}
      {deleting && <DeleteUserModal key={deleting.id} user={deleting} onClose={() => setDeleting(null)} />}
    </>
  )
}

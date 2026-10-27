import { Building2, Mail, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, CardBody } from '../../components/ui/Card'
import { useAuth } from '../../providers/AuthProvider'
import { roleTranslationKey } from '../../lib/roles'
import { AccountSettings } from '../settings/AccountSettings'
import { CompanySettings } from '../settings/CompanySettings'

function initials(name) {
  return name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()
}

export function ProfilePage() {
  const { t } = useTranslation()
  const { user, company } = useAuth()

  return (
    <>
      <PageHeader title={t('profilePage.title')} subtitle={t('profilePage.subtitle')} />
      <div className="flex flex-col gap-6">
        <Card className="overflow-hidden">
          <div className="h-1 bg-linear-to-r from-brand via-brand/60 to-transparent" />
          <CardBody className="flex flex-col gap-5 sm:flex-row sm:items-center">
            <div className="flex shrink-0 items-center gap-3">
              <div className="flex size-20 items-center justify-center overflow-hidden rounded-md border border-line bg-surface">
                {company?.logo_data_url ? (
                  <img src={company.logo_data_url} alt={t('settings.company.logoAlt', { name: company.name })} className="size-full object-contain p-2" />
                ) : (
                  <Building2 className="size-8 text-ink-3" aria-hidden="true" />
                )}
              </div>
              <div className="flex size-12 items-center justify-center rounded-full border border-line bg-brand-subtle text-sm font-semibold text-brand">
                {user.full_name ? initials(user.full_name) : '?'}
              </div>
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-xl font-semibold text-ink">{user.full_name}</p>
              <p className="mt-1 truncate text-sm text-ink-2">{company?.name}</p>
              <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-[13px] text-ink-2">
                <span className="inline-flex items-center gap-1.5"><Mail className="size-4 text-ink-3" aria-hidden="true" />{user.email}</span>
                <span className="inline-flex items-center gap-1.5"><ShieldCheck className="size-4 text-ink-3" aria-hidden="true" />{t(roleTranslationKey(user.role))}</span>
                {company?.city && <span className="inline-flex items-center gap-1.5"><Building2 className="size-4 text-ink-3" aria-hidden="true" />{company.city}</span>}
              </div>
            </div>
          </CardBody>
        </Card>

        <CompanySettings />
        <AccountSettings />
      </div>
    </>
  )
}

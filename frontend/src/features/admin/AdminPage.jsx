import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ShieldAlert } from 'lucide-react'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card } from '../../components/ui/Card'
import { Tabs } from '../../components/ui/Tabs'
import { EmptyState } from '../../components/ui/States'
import { AdminOverview } from './AdminOverview'
import { AdminUsers } from './AdminUsers'
import { AdminCompanies } from './AdminCompanies'

const TABS = ['overview', 'users', 'companies']

/**
 * EnergyFlow's own staff: every company and user on the platform. The API enforces
 * access (RequirePlatformAdmin); this check only spares everyone else an empty page.
 */
export function AdminPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'overview'
  const openUsers = (companyId) => setParams({ tab: 'users', company: String(companyId) })

  if (!user?.is_platform_admin) {
    return (
      <>
        <PageHeader title={t('admin.title')} />
        <Card>
          <EmptyState icon={ShieldAlert} title={t('admin.forbiddenTitle')} body={t('admin.forbiddenBody')} />
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('admin.title')} subtitle={t('admin.subtitle')} />
      <Tabs items={TABS.map((key) => ({ key, label: t(`admin.tabs.${key}`) }))} value={tab} onChange={(key) => setParams({ tab: key })} />
      {tab === 'overview' && <AdminOverview onOpenUsers={openUsers} />}
      {tab === 'users' && <AdminUsers key={params.get('company') ?? 'all'} companyId={params.get('company')} onClearCompany={() => setParams({ tab: 'users' })} />}
      {tab === 'companies' && <AdminCompanies onOpenUsers={openUsers} />}
    </>
  )
}

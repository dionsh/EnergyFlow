import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/cn'
import { PageHeader } from '../../components/layout/PageHeader'
import { CompanySettings } from './CompanySettings'
import { AccountSettings } from './AccountSettings'

const TABS = ['company', 'account']

export function SettingsPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('company')

  return (
    <>
      <PageHeader title={t('settings.title')} subtitle={t('settings.subtitle')} />
      <div role="tablist" className="mb-6 flex gap-1 border-b border-line">
        {TABS.map((key) => (
          <button
            key={key}
            role="tab"
            type="button"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={cn(
              '-mb-px h-10 border-b-2 px-3 text-sm font-medium transition-colors',
              tab === key ? 'border-brand text-ink' : 'border-transparent text-ink-2 hover:text-ink',
            )}
          >
            {t(`settings.tabs.${key}`)}
          </button>
        ))}
      </div>
      {tab === 'company' ? <CompanySettings /> : <AccountSettings />}
    </>
  )
}

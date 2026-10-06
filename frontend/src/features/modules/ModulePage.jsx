import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRight } from 'lucide-react'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/States'

/**
 * Shell for modules whose data pipeline isn't connected yet. It shows an honest
 * empty state (what the page will show and what unlocks it) — never sample numbers.
 */
export function ModulePage({ module, icon }) {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t(`nav.${module}`)} subtitle={t(`modules.${module}.description`)} />
      <Card>
        <EmptyState
          icon={icon}
          title={t(`modules.${module}.emptyTitle`)}
          body={t(`modules.${module}.emptyBody`)}
          actions={
            <Link
              to="/"
              className="inline-flex h-9 items-center gap-2 rounded-sm border border-line-strong bg-surface px-3.5 text-sm font-medium text-ink hover:bg-surface-2"
            >
              {t('overview.setup.title')}
              <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
          }
        />
      </Card>
    </>
  )
}

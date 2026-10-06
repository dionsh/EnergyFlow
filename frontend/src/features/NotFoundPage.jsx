import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/States'

export function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <Card>
      <EmptyState
        title={t('notFound.title')}
        body={t('notFound.body')}
        actions={
          <Link to="/" className="inline-flex h-9 items-center rounded-sm bg-brand px-3.5 text-sm font-medium text-on-brand hover:bg-brand-hover">
            {t('notFound.back')}
          </Link>
        }
      />
    </Card>
  )
}

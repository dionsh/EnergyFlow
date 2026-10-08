import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatEur } from '../../lib/format'
import { Badge } from '../../components/ui/Badge'
import { Card, CardHeader } from '../../components/ui/Card'
import { Skeleton } from '../../components/ui/States'
import { useRecommendations } from '../data'
import { oppText } from './oppText'

/** Overview: the three opportunities worth the most, in € per month. */
export function TopOpportunitiesCard() {
  const { t } = useTranslation()
  const recs = useRecommendations('open')
  const list = recs.data?.data ?? []
  return (
    <Card>
      <CardHeader
        title={t('overviewOpportunities.title')}
        actions={<Link to="/opportunities" className="text-[13px] font-medium text-brand hover:underline">{t('common.viewAll')}</Link>}
      />
      {recs.isPending ? (
        <div className="p-5"><Skeleton className="h-24" /></div>
      ) : list.length === 0 ? (
        <p className="px-5 py-4 text-[13px] text-ink-2">{t('overviewOpportunities.empty')}</p>
      ) : (
        <ul className="divide-y divide-line">
          {list.slice(0, 3).map((rec) => (
            <li key={rec.id}>
              <Link to={`/opportunities?opportunity=${rec.id}`} className="flex items-start gap-3 px-5 py-3 hover:bg-surface-2">
                <span className="min-w-0 flex-1 text-[13px]">
                  <span className="block font-medium text-ink">{oppText(t, rec).title}</span>
                  <span className="mt-1 inline-flex"><Badge tone={rec.impact_tag === 'eur_co2' ? 'brand' : 'neutral'}>{t(`opportunities.tag.${rec.impact_tag}`)}</Badge></span>
                </span>
                <span className="shrink-0 text-right">
                  <span className="block text-sm font-semibold tabular text-ink">{formatEur(rec.per_month.eur)}</span>
                  <span className="block text-xs text-ink-3">{t('opportunities.perMonth')}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { Button } from '../../components/ui/Button'
import { Card, CardBody } from '../../components/ui/Card'
import { ErrorState, Skeleton } from '../../components/ui/States'

export function CompanyRequests() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const requests = useQuery({ queryKey: ['company-requests'], queryFn: () => api.get('/admin/requests'), refetchInterval: 30000 })
  const approve = useMutation({ mutationFn: (id) => api.post(`/admin/requests/${id}/approve`), onSuccess: () => {
    client.invalidateQueries({ queryKey: ['company-requests'] })
    client.invalidateQueries({ queryKey: ['admin'] })
  } })
  if (requests.isPending) return <Skeleton className="mb-4 h-24" />
  if (requests.isError) return <ErrorState error={requests.error} onRetry={requests.refetch} />
  return <Card className="mb-6"><CardBody>
    <h2 className="mb-3 font-semibold">{t('approval.requests')} ({requests.data.data.length})</h2>
    {requests.data.data.length === 0 && <p className="text-sm text-ink-2">{t('approval.empty')}</p>}
    {requests.data.data.map(row => <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3">
      <div><p className="font-medium">{row.company_name}</p><p className="text-sm text-ink-2">{row.full_name} · {row.email}</p></div>
      <Button loading={approve.isPending} onClick={() => approve.mutate(row.id)}>{t('approval.approve')}</Button>
    </div>)}
    {approve.isError && <ErrorState error={approve.error} />}
  </CardBody></Card>
}

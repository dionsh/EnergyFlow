import { CircleAlert } from 'lucide-react'
import { cn } from '../../lib/cn'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Callout } from '../../components/ui/States'

// Building blocks shared by the landing page's sections.

export function Container({ className, children }) {
  return <div className={cn('mx-auto w-full max-w-[1200px] px-4 sm:px-6 lg:px-8', className)}>{children}</div>
}

export function SectionHeading({ id, eyebrow, title, body, className }) {
  return (
    <div className={cn('max-w-2xl', className)}>
      <p className="text-[13px] font-semibold uppercase tracking-[0.06em] text-brand">{eyebrow}</p>
      <h2 id={id} className="mt-2 text-[24px] font-semibold leading-tight tracking-[-0.015em] text-ink sm:text-[30px]">{title}</h2>
      {body && <p className="mt-3 text-[15px] leading-relaxed text-ink-2 sm:text-base">{body}</p>}
    </div>
  )
}

export function DemoError({ error }) {
  const { errorMessage } = useApiErrors()
  if (!error) return null
  return (
    <Callout tone="critical" icon={CircleAlert} className="mt-4">
      {errorMessage(error)}
    </Callout>
  )
}

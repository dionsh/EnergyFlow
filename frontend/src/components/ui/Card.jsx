import { cn } from '../../lib/cn'

export function Card({ className, children, ...props }) {
  return (
    <section className={cn('rounded-md border border-line bg-surface', className)} {...props}>
      {children}
    </section>
  )
}

export function CardHeader({ title, description, actions, className }) {
  return (
    <header className={cn('flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4', className)}>
      <div className="min-w-0">
        <h2 className="text-base font-semibold text-ink">{title}</h2>
        {description && <p className="mt-0.5 text-[13px] text-ink-2">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </header>
  )
}

export function CardBody({ className, children }) {
  return <div className={cn('px-5 py-4', className)}>{children}</div>
}

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AuthLayout } from './AuthLayout'
import { buttonClasses } from '../../components/ui/buttonStyles'

export function InvitationPage() {
  const { t } = useTranslation()
  return (
    <AuthLayout
      title={t('auth.invitation.title')}
      subtitle={t('auth.invitation.subtitle')}
    >
      <Link to="/login" className={buttonClasses({ size: 'lg', className: 'w-full' })}>
        {t('auth.register.login')}
      </Link>
    </AuthLayout>
  )
}

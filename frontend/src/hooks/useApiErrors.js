import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * Turns API error codes into translated messages.
 *   errorMessage(error)      → "The email or password is incorrect."
 *   fieldError('min_length:10') → "Must be at least 10 characters."
 */
export function useApiErrors() {
  const { t } = useTranslation()

  const errorMessage = useCallback(
    (error) => (error ? t(`errors.${error.code}`, { defaultValue: t('errors.generic') }) : null),
    [t],
  )

  const fieldError = useCallback(
    (code) => {
      if (!code) return null
      const [key, arg] = String(code).split(':')
      return t(`validation.${key}`, { count: arg === undefined ? undefined : Number(arg), defaultValue: t('errors.generic') })
    },
    [t],
  )

  /** Translated message per field from an ApiError (422). */
  const fieldErrors = useCallback(
    (error) => Object.fromEntries(Object.entries(error?.fields ?? {}).map(([field, code]) => [field, fieldError(code)])),
    [fieldError],
  )

  return { errorMessage, fieldError, fieldErrors }
}

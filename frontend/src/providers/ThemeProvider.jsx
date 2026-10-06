import { createContext, use, useEffect, useMemo, useState } from 'react'

const ThemeContext = createContext(null)
const STORAGE_KEY = 'ef.theme'
const THEMES = ['light', 'dark', 'system']

function readPreference() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    return THEMES.includes(saved) ? saved : 'system'
  } catch {
    return 'system'
  }
}

const systemTheme = () => (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')

/** Light is the product default; dark is a selected palette, not an inversion. */
export function ThemeProvider({ children }) {
  const [preference, setPreference] = useState(readPreference)
  const [system, setSystem] = useState(systemTheme)

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const onChange = () => setSystem(media.matches ? 'dark' : 'light')
    media.addEventListener('change', onChange)
    return () => media.removeEventListener('change', onChange)
  }, [])

  const resolved = preference === 'system' ? system : preference

  useEffect(() => {
    document.documentElement.dataset.theme = resolved
  }, [resolved])

  const value = useMemo(
    () => ({
      preference,
      resolved,
      themes: THEMES,
      setPreference(next) {
        setPreference(next)
        try {
          localStorage.setItem(STORAGE_KEY, next)
        } catch {
          // Per-viewer convenience only; the theme still applies for this session.
        }
      },
    }),
    [preference, resolved],
  )

  return <ThemeContext value={value}>{children}</ThemeContext>
}

export function useTheme() {
  const context = use(ThemeContext)
  if (!context) throw new Error('useTheme must be used inside <ThemeProvider>')
  return context
}

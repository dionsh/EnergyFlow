// Languages EnergyFlow ships in. To add German or French later:
//   1. add { code: 'de', label: 'Deutsch', short: 'DE', intl: 'de-DE' } here,
//   2. add src/i18n/locales/de.json (copy en.json and translate),
//   3. add 'de' to Locales::SUPPORTED in backend/src/Utils/Locales.php.
export const LANGUAGES = [
  { code: 'sq', label: 'Shqip', short: 'SQ', intl: 'sq-XK' },
  { code: 'en', label: 'English', short: 'EN', intl: 'en-GB' },
]

export const DEFAULT_LANGUAGE = 'sq'

export const isSupported = (code) => LANGUAGES.some((language) => language.code === code)

export const intlLocale = (code) => LANGUAGES.find((language) => language.code === code)?.intl ?? 'en-GB'

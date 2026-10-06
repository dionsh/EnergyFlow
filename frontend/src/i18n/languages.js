// Languages EnergyFlow ships in. To add German or French later:
//   1. add an entry here (number format included),
//   2. add src/i18n/locales/<code>.json (copy en.json and translate, incl. "calendar"),
//   3. add the code to Locales::SUPPORTED in backend/src/Utils/Locales.php.
//
// Number formats are defined here instead of relying on the browser's Intl data,
// which is incomplete for Albanian in some browsers (it falls back to "2,902").
export const LANGUAGES = [
  { code: 'sq', label: 'Shqip', short: 'SQ', number: { group: ' ', decimal: ',', currency: 'suffix', percentSpace: false } },
  { code: 'en', label: 'English', short: 'EN', number: { group: ',', decimal: '.', currency: 'prefix', percentSpace: false } },
]

export const DEFAULT_LANGUAGE = 'sq'

export const isSupported = (code) => LANGUAGES.some((language) => language.code === code)

export const languageFor = (code) => LANGUAGES.find((language) => language.code === code) ?? LANGUAGES[1]

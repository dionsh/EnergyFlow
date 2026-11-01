import { loadFont as loadSans } from '@remotion/google-fonts/IBMPlexSans'
import { loadFont as loadMono } from '@remotion/google-fonts/IBMPlexMono'

// The app's typefaces (frontend/index.html).
export const sans = loadSans('normal', { weights: ['400', '500', '600', '700'], subsets: ['latin', 'latin-ext'] }).fontFamily
export const mono = loadMono('normal', { weights: ['400', '500'], subsets: ['latin'] }).fontFamily

// The app's dark palette (frontend/src/styles/index.css) on the web intro's deeper background.
export const colors = {
  bg: '#07100d',
  surface: '#151c19',
  surface2: '#1b2420',
  border: '#26302b',
  borderStrong: '#34403a',
  text: '#ecf4ee',
  text2: '#a9b5af',
  text3: '#7c8882',
  brand: '#3fb984',
  glow: '#61d69a',
  brandSubtle: '#12291f',
  onBrand: '#06120d',
  warning: '#fab219',
  warningSubtle: '#3a2c0e',
  good: '#0ca30c',
  goodSubtle: '#10301a',
  critical: '#d03b3b',
}

export const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }

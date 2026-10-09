import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Camera, CameraOff, ImageUp, Keyboard } from 'lucide-react'
import { Button } from '../../components/ui/Button'

const MAX_EDGE = 1400 // sharp enough for printed text; keeps the image near 2,000 model tokens
const MAX_DATA_URL = 900_000 // the API accepts request bodies up to 1 MB

/** Draws a source onto a canvas no larger than MAX_EDGE and returns a JPEG data URL under MAX_DATA_URL. */
function compress(source, width, height) {
  let scale = Math.min(1, MAX_EDGE / Math.max(width, height))
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(width * scale)
    canvas.height = Math.round(height * scale)
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height)
    for (const quality of [0.85, 0.75, 0.65]) {
      const url = canvas.toDataURL('image/jpeg', quality)
      if (url.length <= MAX_DATA_URL) return url
    }
    scale *= 0.8
  }
  return null
}

const cameraSupported = () => typeof navigator !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia)

/**
 * The phone's back camera with a framing guide; on a desktop without a camera,
 * or when access is denied, the photo can be uploaded instead (on phones the
 * file picker also offers the camera). Values can always be typed by hand.
 */
export function CameraCapture({ hint, onPhoto, onManual }) {
  const { t } = useTranslation()
  const video = useRef(null)
  const file = useRef(null)
  const [state, setState] = useState(() => (cameraSupported() ? 'starting' : 'unsupported'))
  const [error, setError] = useState(null)

  useEffect(() => {
    if (!cameraSupported()) return undefined
    let stream = null
    let cancelled = false
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false })
      .then((s) => {
        if (cancelled) {
          s.getTracks().forEach((track) => track.stop())
          return
        }
        stream = s
        if (video.current) video.current.srcObject = s
        setState('live')
      })
      .catch(() => {
        if (!cancelled) setState('denied')
      })
    return () => {
      cancelled = true
      stream?.getTracks().forEach((track) => track.stop())
    }
  }, [])

  const capture = () => {
    const v = video.current
    if (!v?.videoWidth) return
    const url = compress(v, v.videoWidth, v.videoHeight)
    if (url) onPhoto(url)
    else setError(t('scan.camera.tooLarge'))
  }

  const upload = async (event) => {
    const picked = event.target.files?.[0]
    event.target.value = ''
    if (!picked) return
    try {
      const bitmap = await createImageBitmap(picked, { imageOrientation: 'from-image' })
      const url = compress(bitmap, bitmap.width, bitmap.height)
      bitmap.close?.()
      if (url) onPhoto(url)
      else setError(t('scan.camera.tooLarge'))
    } catch {
      setError(t('scan.camera.unreadable'))
    }
  }

  const live = state === 'live'
  return (
    <div className="flex flex-col gap-3">
      <div className="relative aspect-[3/4] w-full overflow-hidden rounded-lg border border-line bg-[#0b0f0d] sm:aspect-[4/3]">
        <video ref={video} autoPlay playsInline muted className={live ? 'size-full object-cover' : 'hidden'} />
        {live ? (
          <>
            {/* Framing guide: corners of the area the document should fill. */}
            <div className="pointer-events-none absolute inset-6 sm:inset-10" aria-hidden="true">
              {['left-0 top-0 border-l-2 border-t-2', 'right-0 top-0 border-r-2 border-t-2', 'bottom-0 left-0 border-b-2 border-l-2', 'bottom-0 right-0 border-b-2 border-r-2'].map((c) => (
                <span key={c} className={`absolute size-8 rounded-[3px] border-white/85 ${c}`} />
              ))}
            </div>
            <p className="absolute inset-x-0 bottom-3 px-4 text-center text-[12.5px] font-medium text-white/90 [text-shadow:0_1px_2px_rgb(0_0_0/0.6)]">{hint}</p>
          </>
        ) : (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center text-white/80">
            {state === 'starting' ? <Camera className="size-7 animate-pulse" aria-hidden="true" /> : <CameraOff className="size-7" aria-hidden="true" />}
            <p className="text-[13px]">{t(`scan.camera.${state}`)}</p>
          </div>
        )}
      </div>
      {error && <p className="text-[13px] text-critical-text">{error}</p>}
      <div className="flex flex-wrap gap-2">
        {live && <Button icon={Camera} onClick={capture} className="flex-1 sm:flex-none">{t('scan.camera.capture')}</Button>}
        <Button variant={live ? 'secondary' : 'primary'} icon={ImageUp} onClick={() => file.current?.click()} className="flex-1 sm:flex-none">{t('scan.camera.upload')}</Button>
        <Button variant="ghost" icon={Keyboard} onClick={onManual}>{t('scan.camera.manual')}</Button>
        <input ref={file} type="file" accept="image/*" className="hidden" onChange={upload} />
      </div>
    </div>
  )
}

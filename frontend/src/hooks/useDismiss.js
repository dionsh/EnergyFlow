import { useEffect } from 'react'

/** Calls onDismiss on outside click or Escape while `active`. For menus and popovers. */
export function useDismiss(ref, onDismiss, active = true) {
  useEffect(() => {
    if (!active) return undefined
    const onPointer = (event) => {
      if (ref.current && !ref.current.contains(event.target)) onDismiss()
    }
    const onKey = (event) => {
      if (event.key === 'Escape') onDismiss()
    }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [ref, onDismiss, active])
}

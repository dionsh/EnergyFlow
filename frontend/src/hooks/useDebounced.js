import { useEffect, useState } from 'react'

/** The value, once it has stopped changing for `delayMs` (sliders → one request, not twenty). */
export function useDebounced(value, delayMs = 300) {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs)
    return () => clearTimeout(id)
  }, [value, delayMs])
  return debounced
}

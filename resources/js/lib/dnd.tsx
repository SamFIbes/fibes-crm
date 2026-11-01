import type { AutoScrollOptions } from '@dnd-kit/core'
import { useEffect, type ReactNode, type RefObject } from 'react'
import { createPortal } from 'react-dom'

/**
 * The drag preview must live directly under <body>. Inside the page it sat under an animated
 * ancestor (page transition), which becomes the containing block for `position: fixed`: the preview
 * then stretched the document past the right edge, the window scrolled sideways and the preview
 * drifted away from the pointer.
 */
export function DragPortal({ children }: { children: ReactNode }) {
  return createPortal(children, document.body)
}

/** While dragging: no sideways page scroll, no text selection, grabbing cursor everywhere. */
export function useDragLock(active: boolean) {
  useEffect(() => {
    if (!active) return
    const html = document.documentElement
    html.classList.add('is-dragging')
    return () => html.classList.remove('is-dragging')
  }, [active])
}

/**
 * dnd-kit's auto-scroll handles vertical (window) scrolling. Sideways it is turned off (x: 0) because it
 * doesn't handle RTL's negative scrollLeft and would also try to scroll the window; useEdgeScroll does it.
 */
export const autoScroll: AutoScrollOptions = {
  threshold: { x: 0, y: 0.1 },
  acceleration: 12,
  interval: 5,
  canScroll: (el) => el !== document.body,
}

/**
 * Smooth sideways auto-scroll of a scroll container while dragging near (or past) its visible
 * left/right edge. Works in LTR and RTL; speed eases in with depth into the edge zone.
 */
export function useEdgeScroll(ref: RefObject<HTMLElement | null>, active: boolean) {
  useEffect(() => {
    const el = ref.current
    if (!active || !el) return
    let x = -1
    let raf = 0
    const onMove = (e: PointerEvent | MouseEvent | TouchEvent) => {
      x = 'touches' in e ? (e.touches[0]?.clientX ?? -1) : e.clientX
    }
    const tick = () => {
      raf = requestAnimationFrame(tick)
      if (x < 0 || el.scrollWidth <= el.clientWidth + 1) return
      const r = el.getBoundingClientRect()
      const left = Math.max(r.left, 0)
      const right = Math.min(r.right, window.innerWidth)
      const zone = Math.max(40, Math.min(110, (right - left) / 6))
      let v = 0
      if (x > right - zone) v = (x - (right - zone)) / zone
      else if (x < left + zone) v = -(left + zone - x) / zone
      if (!v) return
      v = Math.max(-1, Math.min(1, v))
      el.scrollLeft += Math.sign(v) * (4 + v * v * 22)
    }
    window.addEventListener('pointermove', onMove, { passive: true })
    window.addEventListener('mousemove', onMove, { passive: true })
    window.addEventListener('touchmove', onMove, { passive: true })
    raf = requestAnimationFrame(tick)
    return () => {
      cancelAnimationFrame(raf)
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('mousemove', onMove)
      window.removeEventListener('touchmove', onMove)
    }
  }, [ref, active])
}

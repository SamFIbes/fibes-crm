import type { Transition, Variants } from 'framer-motion'

/** Shared motion language: quick, soft ease-out with a gentle spring for physical things. */
export const easeOut = [0.22, 1, 0.36, 1] as const
export const spring: Transition = { type: 'spring', stiffness: 420, damping: 34, mass: 0.8 }
export const drawerSpring: Transition = { type: 'spring', stiffness: 380, damping: 38, mass: 0.9 }

export const page: Variants = {
  initial: { y: 8 },
  enter: { y: 0, transition: { duration: 0.22, ease: easeOut } },
  exit: { y: -4, transition: { duration: 0.12, ease: easeOut } },
}

export const stagger = (step = 0.045, delay = 0.04): Variants => ({
  hidden: {},
  show: { transition: { staggerChildren: step, delayChildren: delay } },
})

export const rise: Variants = {
  hidden: { opacity: 0, y: 14 },
  show: { opacity: 1, y: 0, transition: { duration: 0.5, ease: easeOut } },
}

export const fade: Variants = {
  hidden: { opacity: 0 },
  show: { opacity: 1, transition: { duration: 0.35, ease: easeOut } },
}

export function prefersReducedMotion() {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

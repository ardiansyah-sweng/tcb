export const RECOMMENDATIONS_HEADING_ID = 'rekomendasi-berikutnya'

/** Moves keyboard focus (and the view) to the recommendations ("Pindah task"). */
export function focusRecommendations() {
  const heading = document.getElementById(RECOMMENDATIONS_HEADING_ID)
  heading?.focus()
  heading?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
}

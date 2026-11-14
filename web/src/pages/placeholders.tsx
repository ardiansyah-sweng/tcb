import type { ReactNode } from 'react'

/**
 * Screens filled in by later stories (Goal & Key Result R1-20).
 * R1-15 only provides the frame.
 */

function Page({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <section>
      <h1 className="text-2xl font-semibold text-slate-900 md:text-3xl">{title}</h1>
      <div className="mt-4 text-slate-600">{children ?? <p>Halaman ini sedang disiapkan.</p>}</div>
    </section>
  )
}

export function GoalsPage() {
  return <Page title="Goal & Key Result" />
}

export function NotFoundPage() {
  return (
    <Page title="Halaman tidak ditemukan">
      <p>Alamat ini tidak ada. Pilih halaman dari navigasi.</p>
    </Page>
  )
}

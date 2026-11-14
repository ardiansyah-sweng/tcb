import type { ReactNode } from 'react'

/** Pages without their own screen (R1-15 frame). */

function Page({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <section>
      <h1 className="text-2xl font-semibold text-slate-900 md:text-3xl">{title}</h1>
      <div className="mt-4 text-slate-600">{children ?? <p>Halaman ini sedang disiapkan.</p>}</div>
    </section>
  )
}

export function NotFoundPage() {
  return (
    <Page title="Halaman tidak ditemukan">
      <p>Alamat ini tidak ada. Pilih halaman dari navigasi.</p>
    </Page>
  )
}

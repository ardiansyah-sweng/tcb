import type { ReactNode } from 'react'
import { useParams } from 'react-router'

/**
 * Screens filled in by later stories (Papan status R1-18,
 * Detail task R1-19, Goal & Key Result R1-20). R1-15 only provides the frame.
 */

function Page({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <section>
      <h1 className="text-2xl font-semibold text-slate-900 md:text-3xl">{title}</h1>
      <div className="mt-4 text-slate-600">{children ?? <p>Halaman ini sedang disiapkan.</p>}</div>
    </section>
  )
}

export function BoardPage() {
  return <Page title="Papan status" />
}

export function GoalsPage() {
  return <Page title="Goal & Key Result" />
}

export function TaskDetailPage() {
  const { taskId } = useParams()
  return (
    <Page title="Detail task">
      {taskId ? (
        <p className="font-mono text-slate-800">{taskId}</p>
      ) : (
        <p>Pilih task dari Beranda atau Papan status untuk melihat detailnya.</p>
      )}
    </Page>
  )
}

export function NotFoundPage() {
  return (
    <Page title="Halaman tidak ditemukan">
      <p>Alamat ini tidak ada. Pilih halaman dari navigasi.</p>
    </Page>
  )
}

import { useQuery } from '@tanstack/react-query'
import { fetchHealth } from './api/health'

function HealthStatus() {
  const { data, isPending, isError } = useQuery({ queryKey: ['health'], queryFn: fetchHealth })

  if (isPending) return <p className="text-slate-500">Memeriksa koneksi…</p>
  if (isError) return <p className="text-red-700">API tidak bisa dihubungi</p>

  return data.database === 'ok' ? (
    <p className="text-emerald-700">Database tersambung</p>
  ) : (
    <p className="text-red-700">Database tidak tersambung</p>
  )
}

export default function App() {
  return (
    <main className="mx-auto max-w-xl px-4 py-10">
      <h1 className="text-2xl font-semibold text-slate-900">TCB · Task Control Block</h1>
      <section className="mt-6 rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="text-sm font-medium text-slate-600">Status sistem</h2>
        <div className="mt-2">
          <HealthStatus />
        </div>
      </section>
    </main>
  )
}

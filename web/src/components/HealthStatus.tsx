import { useQuery } from '@tanstack/react-query'
import { fetchHealth } from '../api/health'

export function HealthStatus() {
  const { data, isPending, isError } = useQuery({ queryKey: ['health'], queryFn: fetchHealth, retry: false })

  if (isPending) return <p className="text-slate-500">Memeriksa koneksi…</p>
  if (isError) return <p className="text-red-700">API tidak bisa dihubungi</p>

  return data.database === 'ok' ? (
    <p className="text-emerald-700">Database tersambung</p>
  ) : (
    <p className="text-red-700">Database tidak tersambung</p>
  )
}

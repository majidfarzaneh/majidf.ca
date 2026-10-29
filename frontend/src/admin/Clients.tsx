import { useDeferredValue, useState } from 'react'
import { useNavigate } from 'react-router'
import { Badge, Empty, ErrorState, Loading } from '../components/ui'
import { ago, money } from '../lib/format'
import ClientForm from './ClientForm'
import { useClients } from './queries'
import { A } from '../lib/sites'

export default function Clients() {
  const [search, setSearch] = useState('')
  const deferred = useDeferredValue(search)
  const { data, isLoading, error, refetch } = useClients(deferred)
  const [adding, setAdding] = useState(false)
  const navigate = useNavigate()

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up">
        <div style={{ position: 'relative', flex: '1 1 260px', maxWidth: 360 }}>
          <i className="fa-solid fa-magnifying-glass dim" style={{ position: 'absolute', left: 12, top: 12, fontSize: 12 }} />
          <input className="input input-sm" style={{ paddingLeft: 32 }} placeholder="Search name, email or company" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <button className="btn" onClick={() => setAdding(true)}><i className="fa-solid fa-user-plus" style={{ fontSize: 12 }} />New client</button>
      </div>

      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : (
        <div className="card card-flush fade-up" style={{ animationDelay: '.06s' }}>
          {data.length === 0 ? <Empty icon="fa-solid fa-users">{search ? 'No clients match.' : 'No clients yet. Add your first one.'}</Empty> : (
            <div className="table-wrap">
              <table className="table" style={{ minWidth: 720 }}>
                <thead><tr><th>client</th><th>email</th><th className="r">services</th><th className="r">open tickets</th><th className="r">outstanding</th><th>last sign-in</th><th /></tr></thead>
                <tbody>
                  {data.map((c) => (
                    <tr key={c.id} className="clickable" onClick={() => navigate(A(`/clients/${c.id}`))}>
                      <td>
                        <div className="row" style={{ gap: 10 }}>
                          <span className="avatar" style={{ width: 28, height: 28, fontSize: 11 }}>{c.initials}</span>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 500 }}>{c.company || c.name}</span>
                            {c.company && <span className="sub">{c.name}</span>}
                          </div>
                        </div>
                      </td>
                      <td className="muted">{c.email}</td>
                      <td className="r mono">{c.services_count ?? 0}</td>
                      <td className="r mono">{c.open_tickets_count || '—'}</td>
                      <td className="r mono" style={{ color: c.outstanding_cents ? 'var(--amber)' : undefined }}>{c.outstanding_cents ? money(c.outstanding_cents) : '—'}</td>
                      <td className="sub nowrap">{c.last_login_at ? ago(c.last_login_at) : 'Never'}</td>
                      <td className="r">{!c.is_active && <Badge label="Inactive" />}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {adding && <ClientForm onClose={() => setAdding(false)} onSaved={(u) => navigate(A(`/clients/${u.id}`))} />}
    </div>
  )
}

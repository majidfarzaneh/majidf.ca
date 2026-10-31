import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Empty, ErrorState, Loading } from '../components/ui'
import { ago, fmtTime } from '../lib/format'
import { A } from '../lib/sites'
import { clientLabel, useActivityLog, useClients, type AuditEntry } from './queries'

const ACTORS: [string, string][] = [['', 'Everyone'], ['client', 'Clients'], ['admin', 'You'], ['system', 'System']]

/** Everything clients, the admin and the system did, newest first. Filters live in the URL. */
export default function ActivityLog() {
  const [params, setParams] = useSearchParams()
  const filters = {
    client_id: Number(params.get('client')) || undefined,
    actor: params.get('actor') ?? undefined,
    category: params.get('category') ?? undefined,
    search: params.get('q') ?? undefined,
    page: Number(params.get('page')) || 1,
  }
  const { data, isLoading, isFetching, error, refetch } = useActivityLog(filters)
  const { data: clients } = useClients()
  const [search, setSearch] = useState(filters.search ?? '')

  const set = (key: string, value: string | number | undefined) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, String(value))
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }
  const submitSearch = (e: FormEvent) => { e.preventDefault(); set('q', search.trim()) }
  const page = data?.meta

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up" style={{ flexWrap: 'wrap', gap: 10 }}>
        <div className="segmented">
          {ACTORS.map(([v, l]) => <button key={v} className={(filters.actor ?? '') === v ? 'on' : ''} onClick={() => set('actor', v)}>{l}</button>)}
        </div>
        <select className="input input-sm" style={{ width: 'auto' }} value={filters.category ?? ''} onChange={(e) => set('category', e.target.value)}>
          <option value="">All activity</option>
          {Object.entries(data?.categories ?? {}).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input input-sm" style={{ width: 'auto', maxWidth: 220 }} value={filters.client_id ?? ''} onChange={(e) => set('client', e.target.value)}>
          <option value="">All clients</option>
          {clients?.map((c) => <option key={c.id} value={c.id}>{clientLabel(c)}</option>)}
        </select>
        <form onSubmit={submitSearch} style={{ flex: '1 1 180px', maxWidth: 280 }}>
          <input className="input input-sm" type="search" placeholder="Search text, name or IP" value={search}
            onChange={(e) => { setSearch(e.target.value); if (!e.target.value) set('q', undefined) }} />
        </form>
        {page && <span className="mono muted" style={{ fontSize: 12, marginLeft: 'auto' }}>{page.total} entries</span>}
      </div>

      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : (
        <div className="card card-flush fade-up" style={{ animationDelay: '.06s', opacity: isFetching ? 0.7 : 1, transition: 'opacity .15s' }}>
          {data.data.length === 0 ? <Empty icon="fa-solid fa-clock-rotate-left">No activity matches these filters.</Empty> : (
            <div className="table-wrap">
              <table className="table" style={{ minWidth: 760 }}>
                <thead><tr><th>when</th><th>who</th><th>what</th><th>client</th><th>ip</th></tr></thead>
                <tbody>{data.data.map((e) => <Row key={e.id} entry={e} />)}</tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {page && page.last_page > 1 && (
        <div className="row" style={{ justifyContent: 'center', gap: 12 }}>
          <button className="btn btn-ghost btn-sm" disabled={page.current_page <= 1} onClick={() => set('page', page.current_page - 1)}><i className="fa-solid fa-arrow-left" style={{ fontSize: 10 }} />Newer</button>
          <span className="mono dim" style={{ fontSize: 12 }}>page {page.current_page} of {page.last_page}</span>
          <button className="btn btn-ghost btn-sm" disabled={page.current_page >= page.last_page} onClick={() => set('page', page.current_page + 1)}>Older<i className="fa-solid fa-arrow-right" style={{ fontSize: 10 }} /></button>
        </div>
      )}
    </div>
  )
}

function Row({ entry: e }: { entry: AuditEntry }) {
  const who = e.actor.type === 'system'
    ? <span className="mono dim"><i className="fa-solid fa-gear" style={{ fontSize: 11, marginRight: 6 }} />System</span>
    : e.actor.type === 'admin'
      ? <span><i className="fa-solid fa-user-shield" style={{ fontSize: 11, marginRight: 6, color: 'var(--link)' }} />{e.actor.name ?? 'Admin'}</span>
      : <span><i className="fa-solid fa-user" style={{ fontSize: 11, marginRight: 6, color: 'var(--lime)' }} />{e.actor.name ?? 'Client'}</span>

  return (
    <tr>
      <td className="sub nowrap" title={fmtTime(e.at)}>{ago(e.at)}</td>
      <td className="nowrap" style={{ fontSize: 13 }}>{who}</td>
      <td style={{ fontSize: 13 }}>
        {e.path ? <Link to={A(e.path)} className="kbd-link">{e.text}</Link> : e.text}
        {e.meta?.user_agent && <div className="dim ellipsis" style={{ fontSize: 11, maxWidth: 420 }}>{e.meta.user_agent}</div>}
        {e.meta?.impersonated_by && <div className="dim" style={{ fontSize: 11 }}><i className="fa-solid fa-user-secret" style={{ marginRight: 5 }} />by {e.meta.impersonated_by}, signed in as the client</div>}
      </td>
      <td className="nowrap" style={{ fontSize: 13 }}>
        {e.client ? (e.client.id ? <Link to={A(`/clients/${e.client.id}`)}>{e.client.name}</Link> : <span className="dim">{e.client.name} (deleted)</span>) : <span className="dim">—</span>}
      </td>
      <td className="mono dim nowrap" style={{ fontSize: 11 }}>{e.ip ?? ''}</td>
    </tr>
  )
}

import { useState } from 'react'
import { ErrorState, Loading } from '../components/ui'
import type { Service } from '../lib/types'
import { clientLabel, useAdminServices, useClients, useMeta } from './queries'
import ServiceForm from './ServiceForm'
import ServicesTable from './ServicesTable'

export default function Services() {
  const [filter, setFilter] = useState({ user_id: 0, status: '', type: '' })
  const { data, isLoading, error, refetch } = useAdminServices({ user_id: filter.user_id || undefined, status: filter.status || undefined, type: filter.type || undefined })
  const { data: meta } = useMeta()
  const { data: clients } = useClients()
  const [editing, setEditing] = useState<Service | 'new' | null>(null)

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up">
        <div className="row-wrap">
          <select className="input input-sm" style={{ width: 'auto' }} value={filter.user_id} onChange={(e) => setFilter({ ...filter, user_id: Number(e.target.value) })}>
            <option value={0}>All clients</option>
            {clients?.map((c) => <option key={c.id} value={c.id}>{clientLabel(c)}</option>)}
          </select>
          <select className="input input-sm" style={{ width: 'auto' }} value={filter.type} onChange={(e) => setFilter({ ...filter, type: e.target.value })}>
            <option value="">All types</option>
            {meta?.service_types.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <select className="input input-sm" style={{ width: 'auto' }} value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value })}>
            <option value="">Any status</option>
            {meta?.service_statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </div>
        <button className="btn" onClick={() => setEditing('new')}><i className="fa-solid fa-plus" style={{ fontSize: 11 }} />Add service</button>
      </div>
      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : (
        <div className="card card-flush fade-up" style={{ animationDelay: '.06s' }}>
          <ServicesTable services={data} onEdit={setEditing} />
        </div>
      )}
      {editing && <ServiceForm service={editing === 'new' ? undefined : editing} userId={filter.user_id || undefined} onClose={() => setEditing(null)} />}
    </div>
  )
}

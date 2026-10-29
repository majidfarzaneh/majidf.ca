import { useDeferredValue, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { ErrorState, Loading } from '../components/ui'
import InvoicesTable from './InvoicesTable'
import { useAdminInvoices } from './queries'
import { A } from '../lib/sites'

const FILTERS: [string, string][] = [['', 'All'], ['draft', 'Drafts'], ['unpaid', 'Unpaid'], ['overdue', 'Overdue'], ['expired', 'Expired'], ['paid', 'Paid'], ['void', 'Void']]

export default function Invoices() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const [search, setSearch] = useState('')
  const deferred = useDeferredValue(search)
  const { data, isLoading, error, refetch } = useAdminInvoices({ status: status || undefined, search: deferred || undefined })
  const navigate = useNavigate()

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up">
        <div className="segmented">
          {FILTERS.map(([value, label]) => (
            <button key={value} className={status === value ? 'on' : ''} onClick={() => setParams(value ? { status: value } : {})}>{label}</button>
          ))}
        </div>
        <div className="row-wrap">
          <input className="input input-sm" style={{ width: 220 }} placeholder="Search number or description" value={search} onChange={(e) => setSearch(e.target.value)} />
          <button className="btn" onClick={() => navigate(A('/invoices/new'))}><i className="fa-solid fa-plus" style={{ fontSize: 11 }} />New invoice</button>
        </div>
      </div>
      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : (
        <div className="card card-flush fade-up" style={{ animationDelay: '.06s' }}><InvoicesTable invoices={data} /></div>
      )}
    </div>
  )
}

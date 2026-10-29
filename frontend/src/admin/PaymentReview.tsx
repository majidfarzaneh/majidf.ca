import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge, Field, Modal } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { ago, fileSize, fmtDate, money, todayIso } from '../lib/format'
import { A } from '../lib/sites'
import { cap } from '../lib/status'
import { useToast } from '../lib/toast'
import type { Payment } from '../lib/types'
import { adminKeys, clientLabel } from './queries'

/** Look at a client's uploaded receipt and approve (mark paid) or reject it. */
export default function PaymentReview({ payment: p, onClose }: { payment: Payment; onClose: () => void }) {
  const qc = useQueryClient()
  const toast = useToast()
  const [mode, setMode] = useState<'view' | 'reject'>('view')
  const [paidOn, setPaidOn] = useState(todayIso())
  const [sendReceipt, setSendReceipt] = useState(true)
  const [reason, setReason] = useState('')
  const receipt = `/api/admin/payments/${p.id}/receipt`
  const isImage = p.receipt_mime?.startsWith('image/') && !/hei[cf]/.test(p.receipt_mime)
  const isPdf = p.receipt_mime === 'application/pdf'
  const reviewable = p.status === 'review'

  const done = (msg: string) => {
    qc.invalidateQueries({ queryKey: adminKeys.all })
    toast.ok(msg)
    onClose()
  }
  const approve = useMutation({
    mutationFn: () => api.post(`/admin/payments/${p.id}/approve`, { paid_on: paidOn, send_receipt: sendReceipt }),
    onSuccess: () => done(`${p.invoices?.map((i) => i.number).join(', ')} marked paid`),
    onError: (e) => toast.error(errorMessage(e)),
  })
  const reject = useMutation({
    mutationFn: () => api.post(`/admin/payments/${p.id}/reject`, { reason }),
    onSuccess: () => done('Payment rejected · client notified'),
    onError: (e) => toast.error(errorMessage(e)),
  })
  const busy = approve.isPending || reject.isPending

  return (
    <Modal wide busy={busy} onClose={onClose}
      eyebrow={<>{clientLabel(p.user)} · {p.method_label} · sent {ago(p.submitted_at)}</>}
      title={<span className="mono" style={{ fontWeight: 700, fontSize: 22 }}>{money(p.amount_cents, true)} <span style={{ fontSize: 12, fontWeight: 400, color: 'var(--fg-3)' }}>{p.currency}</span></span>}
      footer={!reviewable ? <button className="btn btn-ghost" onClick={onClose}>Close</button> : mode === 'view' ? (
        <>
          <button className="btn btn-danger" disabled={busy} onClick={() => setMode('reject')}><i className="fa-solid fa-xmark" style={{ fontSize: 11 }} />Reject</button>
          <button className="btn" disabled={busy} onClick={() => approve.mutate()}>
            {approve.isPending ? <i className="fa-solid fa-circle-notch spin" /> : <i className="fa-solid fa-check" style={{ fontSize: 11 }} />}Approve · mark paid
          </button>
        </>
      ) : (
        <>
          <button className="btn btn-ghost" disabled={busy} onClick={() => setMode('view')}>Back</button>
          <button className="btn btn-danger" disabled={busy || !reason.trim()} onClick={() => reject.mutate()}>
            {reject.isPending && <i className="fa-solid fa-circle-notch spin" />}Reject and email client
          </button>
        </>
      )}
    >
      <div className="modal-body" style={{ maxHeight: '72vh', overflowY: 'auto' }}>
        <div className="receipt-preview">
          {!p.has_receipt ? <span className="dim">No receipt attached.</span>
            : isImage ? <a href={receipt} target="_blank" rel="noreferrer"><img src={receipt} alt={`Receipt ${p.receipt_name ?? ''}`} /></a>
            : isPdf ? <iframe src={receipt} title="Receipt PDF" />
            : <a className="btn btn-ghost" href={receipt} target="_blank" rel="noreferrer"><i className="fa-solid fa-file-arrow-down" />Download {p.receipt_name}</a>}
        </div>
        {p.has_receipt && (
          <a href={receipt} target="_blank" rel="noreferrer" className="btn-text" style={{ alignSelf: 'flex-start' }}>
            <i className="fa-solid fa-up-right-from-square" style={{ fontSize: 10 }} />{p.receipt_name}{p.receipt_size ? ` · ${fileSize(p.receipt_size)}` : ''}
          </a>
        )}
        <dl className="kv">
          <dt>Status</dt><dd><Badge label={cap(p.status)} /></dd>
          <dt>Invoices</dt><dd>{p.invoices?.map((i, k) => <span key={i.id}>{k > 0 && ', '}<Link to={A(`/invoices/${i.id}`)} onClick={onClose}>{i.number}</Link> <span className="dim">({money(i.amount_cents)})</span></span>)}</dd>
          <dt>Method</dt><dd>{p.method_label.charAt(0).toUpperCase() + p.method_label.slice(1)}</dd>
          <dt>Reference</dt><dd className="mono">{p.reference || '—'}</dd>
          <dt>Client note</dt><dd style={{ whiteSpace: 'pre-wrap' }}>{p.client_note || '—'}</dd>
          <dt>Submitted</dt><dd>{fmtDate(p.submitted_at)}</dd>
          {p.reviewed_at && <><dt>Reviewed</dt><dd>{fmtDate(p.reviewed_at)}{p.reviewed_by ? ` by ${p.reviewed_by}` : ''}</dd></>}
          {p.rejection_reason && <><dt>Reason</dt><dd style={{ color: 'var(--red)' }}>{p.rejection_reason}</dd></>}
        </dl>
        {reviewable && mode === 'view' && (
          <div className="row-wrap" style={{ gap: 16, paddingTop: 4 }}>
            <label className="checkbox">Received on <input type="date" className="input input-sm" style={{ width: 'auto' }} max={todayIso()} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} /></label>
            <label className="checkbox"><input type="checkbox" checked={sendReceipt} onChange={(e) => setSendReceipt(e.target.checked)} />Email a receipt to the client</label>
          </div>
        )}
        {reviewable && mode === 'reject' && (
          <Field label="reason · emailed to the client">
            <textarea className="input" rows={3} autoFocus maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. No e-Transfer with that reference has arrived yet. Could you check it went to majix.fa@gmail.com?" />
          </Field>
        )}
      </div>
    </Modal>
  )
}

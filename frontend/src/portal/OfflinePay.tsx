import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState, type DragEvent, type FormEvent } from 'react'
import { Field } from '../components/ui'
import { api, ApiError, errorMessage } from '../lib/api'
import { fileSize, money } from '../lib/format'
import { useToast } from '../lib/toast'
import type { Invoice, OfflineMethod, Payment } from '../lib/types'
import { portalKeys } from './queries'

const ICONS: Record<OfflineMethod['method'], string> = {
  etransfer: 'fa-solid fa-money-bill-transfer',
  bank: 'fa-solid fa-building-columns',
  cheque: 'fa-solid fa-money-check',
}
const REFERENCE_LABEL: Record<OfflineMethod['method'], string> = {
  etransfer: 'e-Transfer reference #',
  bank: 'transaction / confirmation #',
  cheque: 'cheque #',
}
const MAX_BYTES = 10 * 1024 * 1024

/** Pay by e-Transfer, cheque or bank transfer outside the portal, then upload the receipt for review. */
export default function OfflinePay({ invoices, methods, onBack, onDone }: {
  invoices: Invoice[]
  methods: OfflineMethod[]
  onBack?: () => void
  onDone: () => void
}) {
  const qc = useQueryClient()
  const toast = useToast()
  const [method, setMethod] = useState<OfflineMethod['method']>(methods[0].method)
  const [file, setFile] = useState<File | null>(null)
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [dragging, setDragging] = useState(false)
  const [fileError, setFileError] = useState<string | null>(null)
  const input = useRef<HTMLInputElement>(null)

  const current = methods.find((m) => m.method === method)!
  const total = invoices.reduce((s, i) => s + i.total_cents, 0)
  const memo = invoices.map((i) => i.number).join(', ')

  const submit = useMutation({
    mutationFn: () => {
      const form = new FormData()
      invoices.forEach((i) => form.append('invoice_ids[]', String(i.id)))
      form.append('method', method)
      form.append('receipt', file!)
      if (reference.trim()) form.append('reference', reference.trim())
      if (note.trim()) form.append('note', note.trim())
      return api.upload<{ data: Payment }>('/portal/payments/offline', form)
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: portalKeys.all })
      onDone()
    },
  })

  const pick = (f: File | undefined | null) => {
    setFileError(null)
    if (!f) return
    if (!/^image\/|^application\/pdf$/.test(f.type) && !/\.(heic|heif)$/i.test(f.name)) return setFileError('Use a photo (JPG, PNG, HEIC) or a PDF.')
    if (f.size > MAX_BYTES) return setFileError('The file must be smaller than 10 MB.')
    setFile(f)
  }
  const drop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    pick(e.dataTransfer.files[0])
  }
  const copy = async (text: string) => {
    await navigator.clipboard?.writeText(text).catch(() => undefined)
    toast.ok('Copied', 'fa-solid fa-copy')
  }
  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    if (!file) return setFileError('Attach a photo or PDF of your receipt.')
    submit.mutate()
  }
  const serverError = submit.error instanceof ApiError ? submit.error.field('receipt') ?? errorMessage(submit.error) : submit.error ? errorMessage(submit.error) : null

  return (
    <form onSubmit={onSubmit} className="modal-body fade-up" style={{ animationDuration: '.3s', gap: 16 }}>
      {methods.length > 1 && (
        <div className="segmented" role="radiogroup" aria-label="Payment method" style={{ alignSelf: 'stretch', display: 'grid', gridTemplateColumns: `repeat(${methods.length}, 1fr)` }}>
          {methods.map((m) => (
            <button key={m.method} type="button" role="radio" aria-checked={method === m.method} className={method === m.method ? 'on' : ''}
              style={{ fontFamily: 'var(--font-body)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8 }}
              onClick={() => setMethod(m.method)}>
              <i className={ICONS[m.method]} style={{ fontSize: 12 }} />{m.label.replace('Interac ', '')}
            </button>
          ))}
        </div>
      )}

      <div className="pay-instructions">
        <Row label="Amount" value={`${money(total, true)} CAD`} onCopy={() => copy((total / 100).toFixed(2))} strong />
        {current.lines.map(([label, value], i) => <Row key={i} label={label} value={value} onCopy={() => copy(value)} />)}
        <Row label={method === 'cheque' ? 'Memo' : method === 'etransfer' ? 'Message' : 'Reference'} value={memo} onCopy={() => copy(memo)} />
        <p className="dim" style={{ margin: 0, fontSize: 12, lineHeight: '18px' }}>{current.hint}</p>
      </div>

      <div className="field">
        <span className="field-label">receipt · photo or pdf</span>
        <button
          type="button"
          className={`dropzone${dragging ? ' over' : ''}${file ? ' has-file' : ''}`}
          onClick={() => input.current?.click()}
          onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
          onDragLeave={() => setDragging(false)}
          onDrop={drop}
        >
          {file ? (
            <>
              <i className={file.type === 'application/pdf' ? 'fa-solid fa-file-pdf' : 'fa-solid fa-file-image'} style={{ fontSize: 18, color: 'var(--lime)' }} />
              <span className="ellipsis" style={{ maxWidth: '100%' }}>{file.name}</span>
              <span className="dim" style={{ fontSize: 11 }}>{fileSize(file.size)} · click to change</span>
            </>
          ) : (
            <>
              <i className="fa-solid fa-cloud-arrow-up" style={{ fontSize: 18, color: 'var(--link)' }} />
              <span>Drop your receipt here or <span style={{ color: 'var(--link)' }}>browse</span></span>
              <span className="dim" style={{ fontSize: 11 }}>Screenshot of the e-Transfer, photo of the cheque, or bank confirmation · max 10 MB</span>
            </>
          )}
        </button>
        <input ref={input} type="file" hidden accept="image/*,application/pdf,.heic,.heif" onChange={(e) => pick(e.target.files?.[0])} />
        {(fileError || serverError) && <span className="field-error"><i className="fa-solid fa-circle-exclamation" />{fileError || serverError}</span>}
      </div>

      <div className="form-grid">
        <Field label={REFERENCE_LABEL[method]}><input className="input input-sm" value={reference} maxLength={120} onChange={(e) => setReference(e.target.value)} placeholder="Optional" /></Field>
        <Field label="note"><input className="input input-sm" value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} placeholder="Optional" /></Field>
      </div>

      <button type="submit" className="btn btn-lg" disabled={submit.isPending}>
        {submit.isPending ? <><i className="fa-solid fa-circle-notch spin" />Uploading</> : <><i className="fa-solid fa-paper-plane" style={{ fontSize: 12 }} />Submit for review</>}
      </button>
      {onBack && <button type="button" className="btn-text" style={{ alignSelf: 'center', color: 'var(--fg-3)' }} onClick={onBack} disabled={submit.isPending}>Choose another method</button>}
    </form>
  )
}

function Row({ label, value, onCopy, strong }: { label: string; value: string; onCopy: () => void; strong?: boolean }) {
  return (
    <div className="pay-row">
      {label && <span className="dim" style={{ fontSize: 12, width: 92, flex: 'none' }}>{label}</span>}
      <span className={strong ? 'mono' : ''} style={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere', fontWeight: strong ? 700 : undefined }}>{value}</span>
      <button type="button" className="icon-btn bare" style={{ width: 26, height: 26 }} onClick={onCopy} aria-label={`Copy ${label || value}`}><i className="fa-solid fa-copy" style={{ fontSize: 11 }} /></button>
    </div>
  )
}

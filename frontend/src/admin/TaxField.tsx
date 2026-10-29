import { Field } from '../components/ui'
import { taxText } from '../lib/format'
import type { EffectiveTax } from '../lib/types'

export type TaxValue = { tax_rate: string; tax_label: string }

/**
 * A client's or service's tax: inherit (empty rate), a custom rate and label, or no tax (rate 0).
 * `inherited` is what applies when nothing is set here.
 */
export default function TaxField({ value, onChange, inherited, inheritName, error }: {
  value: TaxValue
  onChange: (v: TaxValue) => void
  inherited: EffectiveTax | { rate: number; label: string } | undefined
  inheritName: string
  error?: string
}) {
  const mode = value.tax_rate === '' ? 'inherit' : Number(value.tax_rate) === 0 ? 'none' : 'custom'
  const pick = (m: string) => onChange(
    m === 'inherit' ? { tax_rate: '', tax_label: '' }
      : m === 'none' ? { tax_rate: '0', tax_label: '' }
        : { tax_rate: String(inherited?.rate || ''), tax_label: inherited?.label ?? '' },
  )

  return (
    <div className="form-grid">
      <Field label="tax" error={error} hint={mode === 'none' ? 'Invoices show no tax row.' : undefined}>
        <select className="input" value={mode} onChange={(e) => pick(e.target.value)}>
          <option value="inherit">{inheritName}{inherited ? ` · ${taxText(inherited)}` : ''}</option>
          <option value="custom">Custom rate</option>
          <option value="none">No tax (exempt)</option>
        </select>
      </Field>
      {mode === 'custom' && (
        <>
          <Field label="tax label"><input className="input" value={value.tax_label} onChange={(e) => onChange({ ...value, tax_label: e.target.value })} placeholder="HST" maxLength={32} /></Field>
          <Field label="tax rate %"><input className="input" inputMode="decimal" required value={value.tax_rate} onChange={(e) => onChange({ ...value, tax_rate: e.target.value })} /></Field>
        </>
      )}
    </div>
  )
}

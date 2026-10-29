@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 48px 52px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #18181b; line-height: 1.5; }
  .mono { font-family: DejaVu Sans Mono, monospace; }
  .muted { color: #71717a; }
  .eyebrow { font-family: DejaVu Sans Mono, monospace; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #71717a; }
  table { width: 100%; border-collapse: collapse; }
  .brand { font-family: DejaVu Sans Mono, monospace; font-size: 13px; }
  .brand .t { color: #0072ee; } .brand .s { color: #a1a1aa; }
  .title { font-size: 26px; font-weight: bold; letter-spacing: -0.5px; margin: 0; }
  .status { display: inline-block; padding: 3px 8px; border-radius: 3px; font-family: DejaVu Sans Mono, monospace; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; }
  .status-paid { background: #ecf6d9; color: #4d7c0f; }
  .status-unpaid { background: #fef3dc; color: #a16207; }
  .status-overdue { background: #fde2e2; color: #b91c1c; }
  .status-draft, .status-void { background: #f4f4f5; color: #52525b; }
  .items th { text-align: left; padding: 8px 0; border-bottom: 1px solid #18181b; }
  .items td { padding: 10px 0; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
  .r, .items th.r { text-align: right; }
  .totals td { padding: 6px 0; }
  .grand td { padding-top: 10px; border-top: 1px solid #18181b; font-size: 14px; font-weight: bold; }
  .bar { height: 3px; background: #0072ee; width: 48px; margin: 10px 0 24px; }
</style>
</head>
<body>
<table>
  <tr>
    <td style="vertical-align:top">
      <div class="brand"><span class="t">~</span><span class="s">/</span>majid<span class="s">.</span>farzaneh</div>
      <div style="margin-top:14px">
        <strong>{{ $business['name'] }}</strong><br>
        <span class="muted">{{ $business['address'] }}<br>{{ $business['email'] }} · {{ $business['phone'] }}
        @if ($business['tax_number'])<br>{{ $tax_label }} # {{ $business['tax_number'] }}@endif</span>
      </div>
    </td>
    <td class="r" style="vertical-align:top">
      <p class="title">Invoice</p>
      <div class="mono" style="font-size:13px">{{ $invoice->number }}</div>
      <div style="margin-top:8px"><span class="status status-{{ strtolower($status) }}">{{ $status }}</span></div>
    </td>
  </tr>
</table>
<div class="bar"></div>
<table>
  <tr>
    <td style="vertical-align:top;width:50%">
      <div class="eyebrow">billed to</div>
      <div style="margin-top:4px"><strong>{{ $invoice->user->company ?: $invoice->user->name }}</strong><br>
      <span class="muted">@if ($invoice->user->company){{ $invoice->user->name }}<br>@endif{{ $invoice->user->email }}@if ($invoice->user->address)<br>{!! nl2br(e($invoice->user->address)) !!}@endif</span></div>
    </td>
    <td class="r" style="vertical-align:top">
      <table>
        <tr><td class="r muted">Issued</td><td class="r mono" style="width:110px">{{ $invoice->issued_at?->format('M j, Y') ?? '—' }}</td></tr>
        <tr><td class="r muted">Due</td><td class="r mono">{{ $invoice->due_at?->format('M j, Y') ?? '—' }}</td></tr>
        @if ($invoice->paid_at)<tr><td class="r muted">Paid</td><td class="r mono">{{ $invoice->paid_at->format('M j, Y') }}</td></tr>@endif
      </table>
    </td>
  </tr>
</table>

<table class="items" style="margin-top:28px">
  <thead><tr>
    <th class="eyebrow" style="color:#18181b">Description</th>
    <th class="eyebrow r" style="color:#18181b;width:60px">Qty</th>
    <th class="eyebrow r" style="color:#18181b;width:90px">Rate</th>
    <th class="eyebrow r" style="color:#18181b;width:90px">Amount</th>
  </tr></thead>
  <tbody>
  @foreach ($invoice->items as $item)
    <tr>
      <td>{{ $item->description }}</td>
      <td class="r mono">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
      <td class="r mono">{{ Money::format($item->unit_price_cents, true) }}</td>
      <td class="r mono">{{ Money::format($item->amount_cents, true) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

<table style="margin-top:12px">
  <tr>
    <td style="width:55%;vertical-align:top;padding-right:24px">
      @if ($invoice->notes)
        <div class="eyebrow">notes</div>
        <div style="margin-top:4px" class="muted">{!! nl2br(e($invoice->notes)) !!}</div>
      @endif
    </td>
    <td style="vertical-align:top">
      <table class="totals">
        <tr><td class="muted">Subtotal</td><td class="r mono">{{ Money::format($invoice->subtotal_cents, true) }}</td></tr>
        @if ($invoice->tax_rate > 0)
        <tr><td class="muted">{{ $invoice->tax_label ?: 'Tax' }} ({{ rtrim(rtrim(number_format($invoice->tax_rate, 2), '0'), '.') }}%)</td><td class="r mono">{{ Money::format($invoice->tax_cents, true) }}</td></tr>
        @endif
        <tr class="grand"><td>Total {{ $invoice->currency }}</td><td class="r mono">{{ Money::format($invoice->total_cents, true) }}</td></tr>
      </table>
    </td>
  </tr>
</table>

<div style="position:fixed;bottom:0;left:0;right:0" class="muted">
  Pay online at {{ $portal_url }} · Questions? {{ $business['email'] }}
</div>
</body>
</html>

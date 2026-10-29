@extends('mail.layout', ['title' => 'Payment received'])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#b6d957">receipt</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">Payment received. Thank you!</h1>
<p style="margin:0 0 20px;color:#a1a1aa">Hi {{ $name }}, I've received your payment of <span style="color:#fafafa">{{ $amount }} {{ $payment->currency }}</span>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #27272a">
  @foreach ($payment->invoices as $invoice)
  <tr><td style="padding:12px 0;{{ $loop->first ? '' : 'border-top:1px solid #1f1f23;' }}"><span style="font-family:Menlo,Consolas,monospace;color:#71717a">{{ $invoice->number }}</span> · {{ $invoice->description }}</td><td align="right" style="padding:12px 0;{{ $loop->first ? '' : 'border-top:1px solid #1f1f23;' }}font-family:Menlo,Consolas,monospace">{{ \App\Support\Money::format($invoice->pivot->amount_cents) }}</td></tr>
  @endforeach
  <tr><td style="padding:12px 0;border-top:1px solid #27272a;color:#71717a">Paid {{ $payment->paid_at?->format('M j, Y') }} · {{ $method }}</td><td align="right" style="padding:12px 0;border-top:1px solid #27272a;font-family:Menlo,Consolas,monospace;font-weight:700">{{ $amount }}</td></tr>
</table>
@include('mail.button', ['url' => $url, 'label' => 'View invoices'])
@endsection

@extends('mail.layout', ['title' => $heading])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:{{ $urgent ? '#e86466' : '#71717a' }}">{{ $eyebrow }}</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">{{ $heading }}</h1>
<p style="margin:0 0 20px;color:#a1a1aa">Hi {{ $name }}, {{ $intro }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #27272a">
  <tr><td style="padding:12px 0;color:#71717a">Invoice</td><td align="right" style="padding:12px 0;font-family:Menlo,Consolas,monospace">{{ $invoice->number }}</td></tr>
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">For</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23">{{ $invoice->description }}</td></tr>
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Due</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23;{{ $urgent ? 'color:#e86466' : '' }}">{{ $invoice->due_at?->format('M j, Y') }}</td></tr>
  @if ($payUntil)
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Pay online by</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23">{{ $payUntil }}</td></tr>
  @endif
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Amount</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23;font-family:Menlo,Consolas,monospace;font-size:18px;font-weight:700">{{ $amount }} {{ $invoice->currency }}</td></tr>
</table>
@include('mail.button', ['url' => $url, 'label' => $button])
<p style="margin:16px 0 0;font-size:12px;color:#71717a">The PDF is attached for your records.</p>
@endsection

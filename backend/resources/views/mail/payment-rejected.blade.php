@extends('mail.layout', ['title' => 'Payment not confirmed'])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#e86466">payment not confirmed</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">I couldn't confirm your payment</h1>
<p style="margin:0 0 16px;color:#a1a1aa">Hi {{ $name }}, thanks for sending proof of your {{ $payment->methodLabel() }} of <span style="color:#fafafa">{{ $amount }}</span> for {{ $payment->invoices->pluck('number')->implode(', ') }}. I wasn't able to match it yet:</p>
<div style="background:#18181b;border:1px solid #27272a;border-radius:8px;padding:14px 16px;white-space:pre-line">{{ $payment->rejection_reason }}</div>
<p style="margin:16px 0 0;color:#a1a1aa">The invoice is open again. You can upload a new receipt or pay another way from the portal, or simply reply to this email.</p>
@include('mail.button', ['url' => $url, 'label' => 'Open invoices'])
@endsection

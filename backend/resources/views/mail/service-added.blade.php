@extends('mail.layout', ['title' => 'New service'])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">new service</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">{{ $service->name }}</h1>
<p style="margin:0 0 20px;color:#a1a1aa">Hi {{ $name }}, {{ $intro }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #27272a">
  <tr><td style="padding:12px 0;color:#71717a">Service</td><td align="right" style="padding:12px 0">{{ $service->type->label() }}</td></tr>
  @if ($service->detail)
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Details</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23">{{ $service->detail }}</td></tr>
  @endif
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Status</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23">{{ $service->status->label() }}</td></tr>
  @if ($service->started_at)
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Started</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23">{{ $service->started_at->format('M j, Y') }}</td></tr>
  @endif
  @if ($renews)
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Next renewal</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23">{{ $renews }}</td></tr>
  @endif
  @if ($price)
  <tr><td style="padding:12px 0;border-top:1px solid #1f1f23;color:#71717a">Price</td><td align="right" style="padding:12px 0;border-top:1px solid #1f1f23;font-family:Menlo,Consolas,monospace;font-size:18px;font-weight:700">{{ $price }}</td></tr>
  @endif
</table>
@if ($autoInvoice)
<p style="margin:16px 0 0;font-size:13px;color:#a1a1aa">An invoice is issued automatically on each renewal date, and you'll get a reminder {{ config('business.renewal_reminder_days') }} days before.</p>
@endif
@include('mail.button', ['url' => $url, 'label' => 'View services'])
<p style="margin:16px 0 0;font-size:12px;color:#71717a">Questions about it? Reply to this email or open a ticket in the portal.</p>
@endsection

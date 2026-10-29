@extends('mail.layout', ['title' => 'Your client portal is ready'])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">client portal</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">Welcome, {{ $name }}</h1>
<p style="margin:0;color:#a1a1aa">I've set up your client portal. You can track your services, pay invoices and open support tickets there.</p>
<p style="margin:12px 0 0;color:#a1a1aa">Sign-in is passwordless: enter <span style="color:#fafafa">{{ $email }}</span> and I'll email you a one-time code.</p>
@include('mail.button', ['url' => $url, 'label' => 'Open the portal'])
@endsection

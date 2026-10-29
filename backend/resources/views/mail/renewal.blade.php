@extends('mail.layout', ['title' => 'Upcoming renewal'])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">renewal</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">{{ $service->name }} renews {{ $service->renews_at->format('M j') }}</h1>
<p style="margin:0;color:#a1a1aa">Hi {{ $name }}, a heads-up that your {{ strtolower($service->type->label()) }} service <span style="color:#fafafa">{{ $service->name }}</span> renews on {{ $service->renews_at->format('F j, Y') }} at <span style="color:#fafafa">{{ $service->priceLabel() }}</span>.</p>
<p style="margin:12px 0 0;color:#a1a1aa">No action needed if you'd like to keep it. Reply to this email if you want to change or cancel anything.</p>
@include('mail.button', ['url' => $url, 'label' => 'View services'])
@endsection

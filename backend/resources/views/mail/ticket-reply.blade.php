@extends('mail.layout', ['title' => 'New reply on '.$ticket->reference()])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">support · {{ $ticket->reference() }}</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">{{ $ticket->subject }}</h1>
<p style="margin:0 0 12px;color:#a1a1aa">{{ $author }} replied:</p>
<div style="background:#18181b;border:1px solid #27272a;border-radius:8px;padding:14px 16px;white-space:pre-line">{{ $body }}</div>
@include('mail.button', ['url' => $url, 'label' => 'Reply in the portal'])
@endsection

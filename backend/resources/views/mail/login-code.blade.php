@extends('mail.layout', ['title' => 'Your sign-in code'])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">sign-in code</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:28px;font-weight:700">Hi {{ $name }}, here's your code</h1>
<p style="margin:0 0 20px;color:#a1a1aa">Enter this code in the client portal. It expires in {{ $ttl }} minutes.</p>
<div style="font-family:Menlo,Consolas,monospace;font-size:32px;letter-spacing:.35em;font-weight:700;color:#fafafa;background:#09090b;border:1px solid #27272a;border-radius:6px;padding:18px 0;text-align:center">{{ $code }}</div>
<p style="margin:20px 0 0;font-size:12px;color:#71717a">Didn't try to sign in? You can ignore this email. Nobody can get in without the code.</p>
@endsection

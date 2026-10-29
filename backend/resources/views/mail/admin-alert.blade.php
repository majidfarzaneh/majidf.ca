@extends('mail.layout', ['title' => $heading])
@section('content')
<p style="margin:0 0 6px;font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">admin · {{ $eyebrow }}</p>
<h1 style="margin:0 0 16px;font-size:20px;line-height:26px;font-weight:700">{{ $heading }}</h1>
@foreach ($lines as $label => $value)
<p style="margin:0 0 8px;color:#a1a1aa"><span style="color:#71717a">{{ $label }}:</span> <span style="color:#fafafa;white-space:pre-line">{{ $value }}</span></p>
@endforeach
@include('mail.button', ['url' => $url, 'label' => 'Open in admin'])
@endsection

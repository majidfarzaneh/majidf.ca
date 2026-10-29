<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title>{{ $title ?? config('business.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#09090b;color:#fafafa;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#09090b">
  <tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px">
      <tr><td style="padding:0 4px 20px;font-family:Menlo,Consolas,monospace;font-size:13px;color:#fafafa">
        <span style="color:#0072ee">~</span><span style="color:#71717a">/</span>majid<span style="color:#71717a">.</span>farzaneh
      </td></tr>
      <tr><td style="background:#111113;border:1px solid #27272a;border-radius:8px;padding:32px">
        @yield('content')
      </td></tr>
      <tr><td style="padding:20px 4px 0;font-size:12px;line-height:18px;color:#71717a">
        {{ config('business.name') }} · {{ config('business.email') }} · {{ config('business.phone') }}<br>
        <a href="{{ config('business.portal_url') }}" style="color:#5699f6;text-decoration:none">Open client portal</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>

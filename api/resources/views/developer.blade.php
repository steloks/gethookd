<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <title>GetHookd · API keys</title>
    @vite(['resources/css/app.css', 'resources/js/developer/main.tsx'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="developer-page" data-mcp-url="{{ config('app.mcp_url') }}"></div>
</body>
</html>

@php
    $baseUrl = rtrim(config('app.url'), '/');
    $prefix = trim(config('app.url_prefix', ''), '/');
    $faviconPath = $prefix === '' ? 'favicon.svg' : $prefix.'/favicon.svg';
@endphp
<link rel="icon" type="image/svg+xml" href="{{ $baseUrl.'/'.$faviconPath }}">
<link rel="shortcut icon" type="image/svg+xml" href="{{ $baseUrl.'/'.$faviconPath }}">

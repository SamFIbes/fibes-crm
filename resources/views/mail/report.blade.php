<!DOCTYPE html>
<html>
<body>
<p>{{ $headline }}</p>
<p>{{ $body }}</p>
@if ($link)
<p dir="ltr"><a href="{{ $link }}">{{ $link }}</a></p>
@endif
</body>
</html>

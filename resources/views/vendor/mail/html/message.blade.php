{{-- Coffer brand email (D79): canvas, white card, Bricolage-style heading, graphite button, Indian-locale footer. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Sent by Coffer · {{ now()->timezone('Asia/Kolkata')->locale('en_IN')->isoFormat('D MMM YYYY, HH:mm') }} IST<br>
Amounts in ₹ with Indian grouping (₹12,34,567) · Choose what you get in [Notification settings]({{ route('settings.notifications') }})<br>
© {{ now()->year }} Coffer · Made in India
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

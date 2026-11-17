<x-mail::message>
{{-- Greeting: the notification's title (D79) --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# Something needs your attention
@else
# Hello
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" :color="$level === 'error' ? 'error' : 'primary'">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
— The Coffer team
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
If the “{{ $actionText }}” button doesn’t work, paste this link into your browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>

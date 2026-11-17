<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            Coffer
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            Sent by Coffer · {{ now()->timezone('Asia/Kolkata')->locale('en_IN')->isoFormat('D MMM YYYY, HH:mm') }} IST
            Choose what you get in Notification settings: {{ route('settings.notifications') }}
            © {{ now()->year }} Coffer · Made in India
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>

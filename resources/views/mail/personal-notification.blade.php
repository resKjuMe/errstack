{{--
    Persönliche Meldung als E-Mail. Wie mail/notification.blade.php, aber mit
    Abmelde-Fußbereich: jede Mail an eine Person trägt den Weg hinaus mit sich.

    @var string $title
    @var string $body
    @var string $level
    @var string|null $url
    @var array<string, string> $context
    @var string|null $reference
    @var string $origin
    @var string $eventLabel
    @var bool $isCritical
    @var string $unsubscribeUrl
    @var string $settingsUrl
--}}
<x-mail::message>
# {{ $title }}

{{ $body }}

@if (count($context) > 0)
@foreach ($context as $label => $value)
**{{ $label }}:** {{ $value }}
@endforeach
@endif

@if ($url)
<x-mail::button :url="$url">
{{ __('emails.notification.open') }}
</x-mail::button>
@endif

@if ($reference)
{{ __('emails.notification.reference', ['reference' => $reference]) }}
@endif

{{ __('emails.regards') }}<br>
{{ config('app.name') }}

<x-slot:subcopy>
{{ __('emails.notification.personal_origin', ['origin' => $origin, 'level' => $level, 'event' => $eventLabel]) }}

@if ($isCritical)
{{ __('emails.notification.critical') }} [{{ __('emails.notification.settings_link') }}]({{ $settingsUrl }}).
@else
[{{ __('emails.notification.unsubscribe_link', ['event' => $eventLabel]) }}]({{ $unsubscribeUrl }}) · [{{ __('emails.notification.all_settings_link') }}]({{ $settingsUrl }})
@endif
</x-slot:subcopy>
</x-mail::message>

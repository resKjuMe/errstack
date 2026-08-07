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
In Errstack öffnen
</x-mail::button>
@endif

@if ($reference)
Kennung: {{ $reference }}
@endif

Viele Grüße<br>
{{ config('app.name') }}

<x-slot:subcopy>
Diese Meldung stammt aus {{ $origin }} ({{ $level }}) und erreicht dich, weil „{{ $eventLabel }}" in deinen Benachrichtigungen eingeschaltet ist.

@if ($isCritical)
Es handelt sich um einen kritischen Alarm. Auch abbestellt und in der Ruhezeit kommt er an — abschalten lässt er sich nur ausdrücklich in den [Benachrichtigungs-Einstellungen]({{ $settingsUrl }}).
@else
[„{{ $eventLabel }}" abbestellen]({{ $unsubscribeUrl }}) · [Alle Einstellungen]({{ $settingsUrl }})
@endif
</x-slot:subcopy>
</x-mail::message>

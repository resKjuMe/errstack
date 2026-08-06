{{--
    Meldung als E-Mail. Nutzt die mitgelieferten Markdown-Bausteine von
    Laravel, damit kein eigenes Mail-Layout gepflegt werden muss.

    @var string $title
    @var string $body
    @var string $level
    @var string|null $url
    @var array<string, string> $context
    @var string|null $reference
    @var string $organization
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

Diese Meldung stammt aus {{ $organization }} ({{ $level }}). Wer sie nicht mehr erhalten möchte, ändert den Benachrichtigungsweg in den Einstellungen der Organisation.

Viele Grüße<br>
{{ config('app.name') }}
</x-mail::message>

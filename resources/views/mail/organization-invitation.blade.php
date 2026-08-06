{{--
    Einladungs-Mail. Nutzt die mitgelieferten Markdown-Bausteine von Laravel,
    damit kein eigenes Mail-Layout gepflegt werden muss.

    @var string $organization
    @var string $role
    @var string|null $invitedBy
    @var string $url
    @var string $expiresAt
--}}
<x-mail::message>
# Einladung zu {{ $organization }}

@if ($invitedBy)
{{ $invitedBy }} lädt dich in die Organisation **{{ $organization }}** ein.
@else
Du bist in die Organisation **{{ $organization }}** eingeladen.
@endif

Deine Rolle dort: **{{ $role }}**.

<x-mail::button :url="$url">
Einladung annehmen
</x-mail::button>

Die Einladung gilt bis zum {{ $expiresAt }}. Wer sie nicht erwartet hat, kann diese Nachricht einfach löschen.

Viele Grüße<br>
{{ config('app.name') }}
</x-mail::message>

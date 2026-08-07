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
# {{ __('emails.invitation.heading', ['organization' => $organization]) }}

@if ($invitedBy)
{{ __('emails.invitation.invited_by', ['name' => $invitedBy, 'organization' => $organization]) }}
@else
{{ __('emails.invitation.invited', ['organization' => $organization]) }}
@endif

{{ __('emails.invitation.role', ['role' => $role]) }}

<x-mail::button :url="$url">
{{ __('emails.invitation.button') }}
</x-mail::button>

{{ __('emails.invitation.expires', ['date' => $expiresAt]) }}

{{ __('emails.regards') }}<br>
{{ config('app.name') }}
</x-mail::message>

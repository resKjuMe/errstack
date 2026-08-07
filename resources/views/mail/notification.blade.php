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
{{ __('emails.notification.open') }}
</x-mail::button>
@endif

@if ($reference)
{{ __('emails.notification.reference', ['reference' => $reference]) }}
@endif

{{ __('emails.notification.origin', ['organization' => $organization, 'level' => $level]) }}

{{ __('emails.regards') }}<br>
{{ config('app.name') }}
</x-mail::message>

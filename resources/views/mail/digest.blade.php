{{--
    Sammelnachricht: mehrere Meldungen desselben Projekts in einer Mail (A6).

    @var string $project
    @var int $count
    @var string $level
    @var array<int, array{title: string, body: string, url: string|null, context: array<string, string>}> $items
    @var string $eventLabel
    @var string $unsubscribeUrl
    @var string $settingsUrl
--}}
<x-mail::message>
# {{ __('digests.mail.heading', ['count' => $count, 'project' => $project]) }}

{{ __('digests.mail.intro', ['count' => $count, 'project' => $project]) }}

@foreach ($items as $item)
---

**{{ $item['title'] }}**

{{ $item['body'] }}

@foreach ($item['context'] as $label => $value)
- {{ $label }}: {{ $value }}
@endforeach

@if ($item['url'])
[{{ __('digests.mail.open_item') }}]({{ $item['url'] }})
@endif

@endforeach

{{ __('emails.regards') }}<br>
{{ config('app.name') }}

<x-slot:subcopy>
{{ __('digests.mail.origin', ['project' => $project, 'level' => $level, 'event' => $eventLabel]) }}

[{{ __('digests.mail.settings_link') }}]({{ $settingsUrl }}) · [{{ __('emails.notification.unsubscribe_link', ['event' => $eventLabel]) }}]({{ $unsubscribeUrl }})
</x-slot:subcopy>
</x-mail::message>

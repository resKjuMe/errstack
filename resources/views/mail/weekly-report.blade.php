{{--
    Wochenbericht eines Projekts (A6).

    @var string $project
    @var string $from
    @var string $until
    @var string $events
    @var string $newIssues
    @var string $resolvedIssues
    @var string $trend
    @var array<int, array{title: string, url: string, count: string}> $topIssues
    @var array<int, array{name: string, count: string}> $topAreas
    @var string $projectUrl
    @var string $eventLabel
    @var string $unsubscribeUrl
    @var string $settingsUrl
--}}
<x-mail::message>
# {{ __('reports.weekly.heading', ['project' => $project]) }}

{{ __('reports.weekly.period', ['from' => $from, 'until' => $until]) }}

**{{ __('reports.weekly.events') }}:** {{ $events }}
**{{ __('reports.weekly.new_issues') }}:** {{ $newIssues }}
**{{ __('reports.weekly.resolved_issues') }}:** {{ $resolvedIssues }}
**{{ __('reports.weekly.trend') }}:** {{ $trend }}

@if (count($topIssues) > 0)
## {{ __('reports.weekly.top_issues') }}

@foreach ($topIssues as $issue)
- [{{ $issue['title'] }}]({{ $issue['url'] }}) — {{ __('reports.weekly.times', ['count' => $issue['count']]) }}
@endforeach
@endif

@if (count($topAreas) > 0)
## {{ __('reports.weekly.top_areas') }}

@foreach ($topAreas as $area)
- {{ $area['name'] }} — {{ __('reports.weekly.times', ['count' => $area['count']]) }}
@endforeach
@endif

<x-mail::button :url="$projectUrl">
{{ __('reports.weekly.open_project') }}
</x-mail::button>

{{ __('emails.regards') }}<br>
{{ config('app.name') }}

<x-slot:subcopy>
[{{ __('emails.notification.unsubscribe_link', ['event' => $eventLabel]) }}]({{ $unsubscribeUrl }}) · [{{ __('emails.notification.all_settings_link') }}]({{ $settingsUrl }})
</x-slot:subcopy>
</x-mail::message>

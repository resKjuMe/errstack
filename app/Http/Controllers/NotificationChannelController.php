<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Notifications\ChannelRegistry;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\NotificationData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Benachrichtigungswege einer Organisation: einrichten, ändern, testen,
 * löschen. Der Versand selbst passiert nie hier, sondern in der Warteschlange —
 * diese Aktionen reihen nur ein.
 */
class NotificationChannelController extends Controller
{
    public function index(Request $request, Organization $organization, ChannelRegistry $registry): InertiaResponse
    {
        Gate::authorize('view', $organization);

        $viewer = $request->user();
        assert($viewer !== null);

        return Inertia::render('notifications/Index', NotificationData::index($organization, $viewer, $registry));
    }

    public function store(NotificationChannelRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageNotifications', $organization);

        $channel = $organization->notificationChannels()->create([
            'type' => $request->type(),
            'name' => $request->validated('name'),
            'config' => $request->config(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', "Kanal „{$channel->name}“ eingerichtet.");
    }

    public function update(NotificationChannelRequest $request, NotificationChannel $channel): RedirectResponse
    {
        Gate::authorize('update', $channel);

        $channel->update([
            'name' => $request->validated('name'),
            'config' => $request->config(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', "Kanal „{$channel->name}“ gespeichert.");
    }

    public function destroy(NotificationChannel $channel): RedirectResponse
    {
        Gate::authorize('delete', $channel);

        $name = $channel->name;
        $channel->delete();

        return back()->with('status', "Kanal „{$name}“ gelöscht.");
    }

    /**
     * Testnachricht: dieselbe Strecke wie eine echte Meldung, damit ein Test
     * auch wirklich etwas aussagt — inklusive Protokolleintrag.
     */
    public function test(NotificationChannel $channel, NotificationDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('send', $channel);

        $dispatcher->sendTo(
            $channel,
            NotificationMessage::test($channel->organization->name),
            isTest: true,
        );

        return back()->with('status', "Testnachricht an „{$channel->name}“ eingereiht. Das Ergebnis steht gleich im Protokoll.");
    }
}

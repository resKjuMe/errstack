<?php

namespace App\Notifications\Drivers;

use App\Mail\NotificationMail;
use App\Models\NotificationChannel;
use App\Notifications\ChannelField;
use App\Notifications\Contracts\ChannelDriver;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;
use Illuminate\Support\Facades\Mail;

/**
 * E-Mail an eine feste Empfängerliste. Der Text kommt aus einer
 * Blade-Vorlage (`resources/views/mail/notification.blade.php`) und nutzt die
 * Markdown-Bausteine von Laravel — wie die Einladungs-Mail.
 */
final class MailDriver implements ChannelDriver
{
    public static function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return 'E-Mail';
    }

    public function description(): string
    {
        return 'Schickt die Meldung an eine feste Liste von Adressen.';
    }

    public function fields(): array
    {
        return [
            new ChannelField(
                key: 'recipients',
                label: 'Empfänger',
                type: 'list',
                hint: 'Eine Adresse je Zeile.',
                placeholder: "team@example.com\nbereitschaft@example.com",
            ),
        ];
    }

    public function rules(): array
    {
        return [
            'recipients' => ['required', 'array', 'min:1', 'max:25'],
            'recipients.*' => ['required', 'email'],
        ];
    }

    public function summary(NotificationChannel $channel): string
    {
        $recipients = $this->recipients($channel);
        $count = count($recipients);

        // Bei wenigen Adressen sind die Adressen selbst die nützlichere
        // Auskunft; erst bei vielen wird die Zahl übersichtlicher.
        return $count <= 3
            ? implode(', ', $recipients)
            : "{$count} Empfänger";
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): DeliveryResult
    {
        $recipients = $this->recipients($channel);

        if ($recipients === []) {
            return DeliveryResult::failure('Für diesen Kanal ist keine Empfängeradresse hinterlegt.');
        }

        // Bewusst ohne `queue()`: dieser Aufruf läuft bereits im
        // Warteschlangen-Job. Ein zweites Einreihen würde den Fehlschlag von
        // der Zustellung abkoppeln und das Protokoll wäre blind.
        Mail::to($recipients)->send(new NotificationMail($message, $channel->organization->name));

        return DeliveryResult::success();
    }

    /**
     * @return list<string>
     */
    private function recipients(NotificationChannel $channel): array
    {
        /** @var list<string> $recipients */
        $recipients = array_values(array_filter(
            (array) $channel->setting('recipients', []),
            static fn (mixed $address): bool => is_string($address) && $address !== '',
        ));

        return $recipients;
    }
}

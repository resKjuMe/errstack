<?php

namespace App\Notifications\Contracts;

use App\Models\NotificationChannel;
use App\Notifications\ChannelField;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;

/**
 * Ein Benachrichtigungsweg. Genau diese Schnittstelle steht zwischen dem
 * Alert-Kern und der Außenwelt — ein neuer Kanal ist eine weitere
 * Umsetzung, eingetragen in `config/notifications.php`. Am Kern und an der
 * Oberfläche ist dafür nichts zu ändern: Formular, Prüfregeln und Beschriftung
 * beschreibt der Treiber selbst.
 */
interface ChannelDriver
{
    /**
     * Kennung des Kanals, wie sie in `notification_channels.type` steht
     * (z. B. `slack`). Statisch, weil die Kanal-Liste sie braucht, bevor ein
     * Kanal eingerichtet ist.
     */
    public static function key(): string;

    /** Anzeigename, z. B. „Microsoft Teams". */
    public function label(): string;

    /** Ein Satz für die Einrichtung: wohin geht das, und was braucht es dafür. */
    public function description(): string;

    /**
     * Felder der Einrichtung, in der Reihenfolge des Formulars.
     *
     * @return list<ChannelField>
     */
    public function fields(): array;

    /**
     * Prüfregeln je Feld — ohne den Präfix `config.`, den der FormRequest
     * ergänzt.
     *
     * @return array<string, list<string>|string>
     */
    public function rules(): array;

    /**
     * Kurzbeschreibung eines eingerichteten Kanals für Liste und Protokoll
     * („#alerts", „3 Empfänger"). Sie darf keine Zugangsdaten preisgeben.
     */
    public function summary(NotificationChannel $channel): string;

    /**
     * Stellt die Nachricht zu. Läuft ausschließlich im Warteschlangen-Job,
     * niemals im Web-Request.
     */
    public function send(NotificationChannel $channel, NotificationMessage $message): DeliveryResult;
}

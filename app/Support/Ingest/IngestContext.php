<?php

namespace App\Support\Ingest;

use App\Http\Middleware\ResolveIngestKey;
use App\Models\ProjectKey;
use App\Support\Api\ApiContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Zugriff auf den Client-Schlüssel der laufenden Aufnahme-Anfrage.
 *
 * Hinterlegt wird er von {@see ResolveIngestKey}; hier steht nur das Auslesen —
 * dasselbe Muster wie bei {@see ApiContext}. Der Umweg über die
 * Anfrage-Attribute statt über `$request->user()` ist hier zwingend: bei der
 * Aufnahme meldet sich keine Person an, sondern eine Anwendung, und es gibt kein
 * Konto, das man setzen könnte.
 */
final class IngestContext
{
    public const KEY = 'errstack.ingest_key';

    public const CLIENT = 'errstack.ingest_client';

    public static function key(Request $request): ProjectKey
    {
        $key = $request->attributes->get(self::KEY);

        if (! $key instanceof ProjectKey) {
            // Kann nur passieren, wenn eine Route ohne ResolveIngestKey läuft.
            throw new RuntimeException('Kein Client-Schlüssel an der Anfrage: fehlt die Middleware '.ResolveIngestKey::class.'?');
        }

        return $key;
    }

    /**
     * Name und Fassung des meldenden SDK, sofern es sie mitgeschickt hat.
     */
    public static function client(Request $request): ?string
    {
        $client = $request->attributes->get(self::CLIENT);

        return is_string($client) && $client !== '' ? $client : null;
    }
}

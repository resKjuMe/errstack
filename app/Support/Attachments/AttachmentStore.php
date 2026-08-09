<?php

namespace App\Support\Attachments;

use App\Models\EventAttachment;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Support\Operations\IngestRetry;
use App\Support\SourceMaps\ArtifactStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Die Ablage der Anhänge: hinlegen, lesen, löschen.
 *
 * Sie ist die einzige Stelle, die weiß, **wo** der Inhalt liegt. Alles andere
 * arbeitet mit {@see EventAttachment} und fragt hier nach der Datei — dadurch
 * lässt sich das Laufwerk austauschen, ohne die Anzeige, die Aufnahme oder das
 * Aufräumen anzufassen. Dieselbe Aufteilung wie bei den Bauartefakten
 * ({@see ArtifactStore}), und aus demselben Grund.
 *
 * Zwei Entscheidungen prägen sie:
 *
 * **Der Ablagepfad ist die Prüfsumme.** Ein Absturzdialog mit „erneut versuchen"
 * schickt denselben Screenshot zu jeder Meldung mit; ohne Inhaltsadressierung
 * wäre jeder Versuch eine weitere Kopie derselben Datei.
 *
 * **Gelöscht wird die Zeile, nicht unbedingt die Datei.** Solange eine andere
 * Zeile auf dieselbe Prüfsumme zeigt — derselbe Screenshot an einer zweiten
 * Meldung —, bleibt der Inhalt liegen. Andernfalls wäre das Löschen eines
 * Anhangs das stille Entwerten eines anderen.
 */
final class AttachmentStore
{
    /**
     * Legt den Anhang eines angenommenen Envelope-Elements ab.
     *
     * Der Inhalt kommt als Argument und wird hier nicht aus dem Beleg geholt: der
     * Aufrufer hat ihn schon in der Hand (er prüft, ob er überhaupt lesbar ist),
     * und ein zweites `bytes()` hieße, zwanzig Megabyte Base64 ein zweites Mal zu
     * entpacken.
     *
     * `null`, wenn dieser Beleg schon einen Anhang hat: die Warteschlange darf
     * einen Job erneut ausliefern, und ein zweiter Durchlauf soll dieselbe Datei
     * nicht ein zweites Mal an die Meldung hängen.
     */
    public function store(IngestPayload $payload, string $content): ?EventAttachment
    {
        $existing = EventAttachment::query()
            ->where('ingest_payload_id', $payload->id)
            ->first();

        if ($existing !== null) {
            return null;
        }

        $checksum = sha1($content);
        $path = $this->pathFor($payload->project_id, $checksum);

        // Nur schreiben, was noch nicht liegt. Der Inhalt unter einem
        // Prüfsummenpfad ist derselbe — ein zweites Schreiben wäre dieselbe Datei
        // über sich selbst.
        if (! $this->disk()->exists($path)) {
            $this->disk()->put($path, $content);
        }

        $contentType = EventAttachment::normalizeContentType($payload->contentType());

        /** @var EventAttachment $attachment */
        $attachment = EventAttachment::query()->create([
            'project_id' => $payload->project_id,
            'ingest_payload_id' => $payload->id,
            'event_reference' => $payload->event_id,
            'name' => EventAttachment::sanitizeName($payload->filename()),
            'content_type' => $contentType,
            'kind' => EventAttachment::kindFor($contentType),
            'size' => strlen($content),
            'checksum' => $checksum,
            'path' => $path,
            // Der Eingang der Rohdaten und nicht dieser Augenblick: an ihm hängt
            // die Aufbewahrungsfrist, und die soll nicht davon abhängen, wann ein
            // Arbeiter Zeit hatte.
            'received_at' => $payload->created_at,
        ]);

        // **Nach** dem Einfügen noch einmal nachsehen — und das ist keine
        // Vorsicht, sondern der Verschluss einer Lücke: das Aufräumen löscht eine
        // Datei genau dann, wenn nach dem Wegfall seiner Zeile keine weitere mehr
        // auf sie zeigt ({@see delete()}). Lief diese Prüfung, während unsere
        // Zeile noch nicht stand, ist die Datei weg, obwohl sie oben schon dalag.
        // Ab hier kann das nicht mehr passieren: jede weitere Prüfung sieht unsere
        // Zeile.
        if (! $this->disk()->exists($path)) {
            $this->disk()->put($path, $content);
        }

        return $attachment;
    }

    /**
     * Der Inhalt eines Anhangs als Datenstrom.
     *
     * Ein Strom und keine Zeichenkette: ein Speicherabbild von zwanzig Megabyte
     * ganz in den Speicher zu holen, nur um es weiterzuschicken, kostet bei
     * mehreren gleichzeitigen Downloads genau so viel Speicher, wie es nicht
     * müsste.
     *
     * `null`, wenn die Datei nicht mehr da ist. Das ist kein erdachter Fall: die
     * Ablage kann aufgeräumt worden sein, während die Zeile stehen blieb — und
     * eine Ausnahme beim Herunterladen wäre die falsche Antwort darauf.
     *
     * @return resource|null
     */
    public function stream(EventAttachment $attachment)
    {
        return $this->disk()->readStream($attachment->path);
    }

    /**
     * Der Anfang eines Anhangs — für die Textvorschau.
     *
     * Der Unterschied zum Herunterladen ist der Umfang: eine Logdatei von zwanzig
     * Megabyte ist im Browser keine Vorschau, sondern eine hängende Seite.
     */
    public function readPrefix(EventAttachment $attachment, int $bytes): ?string
    {
        $stream = $this->stream($attachment);

        if ($stream === null) {
            return null;
        }

        $prefix = stream_get_contents($stream, $bytes);
        fclose($stream);

        if ($prefix === false) {
            return null;
        }

        // Auf die letzte vollständige Zeichengrenze zurück: geschnitten wird nach
        // Bytes, ausgeliefert wird als UTF-8. Fällt die Grenze mitten in ein „ü",
        // endet die Vorschau sonst in einem Ersatzzeichen — und das sieht aus wie
        // eine kaputte Logdatei, nicht wie ein Anriss.
        return self::trimToValidUtf8($prefix);
    }

    /**
     * Löscht einen Anhang.
     *
     * Der Inhalt fällt nur mit, wenn keine andere Zeile mehr auf ihn zeigt. Die
     * Frage wird **nach** dem Wegfall der eigenen Zeile gestellt, und das ist der
     * Unterschied zwischen „meistens richtig" und „richtig": zwei gleichzeitige
     * Löschungen zweier Zeilen mit derselben Prüfsumme — das nächtliche Aufräumen
     * und ein Mensch in der Oberfläche — sähen davor jeweils die Zeile des anderen
     * und ließen die Datei beide liegen. Danach sieht mindestens einer von beiden
     * keine Zeile mehr.
     *
     * Der Beleg fällt ebenfalls weg. Ohne ihn wäre das Löschen keines: die
     * Rohdaten in `ingest_payloads` sind eine zweite Kopie derselben Bytes, und
     * ein erneut eingereihter Beleg ({@see IngestRetry})
     * würde den gelöschten Screenshot wieder anlegen — er findet die Zeile nicht
     * mehr, die ihn als Doppel erkennen würde.
     *
     * @return bool Ob die Datei tatsächlich weg ist. `false` heißt: die Zeile ist
     *              gelöscht, die Datei nicht — der Aufrufer soll das melden statt
     *              freien Platz zu behaupten.
     */
    public function delete(EventAttachment $attachment): bool
    {
        $path = $attachment->path;
        $payloadId = $attachment->ingest_payload_id;

        $attachment->delete();

        if ($payloadId !== null) {
            IngestPayload::query()->whereKey($payloadId)->delete();
        }

        $stillUsed = EventAttachment::query()
            ->where('path', $path)
            ->exists();

        if ($stillUsed) {
            return false;
        }

        // Der Rückgabewert wird ausdrücklich ausgewertet: alle Laufwerke sind mit
        // `throw => false` konfiguriert, ein gescheitertes Löschen käme also als
        // `false` und nicht als Ausnahme. Ohne diese Zeile bliebe eine Datei ohne
        // Verweis liegen, während das Aufräumen freien Platz meldet.
        $removed = $this->disk()->delete($path);

        if (! $removed) {
            Log::warning('Datei eines gelöschten Anhangs blieb liegen.', [
                'projekt' => $attachment->project_id,
                'anhang' => $attachment->getKey(),
                'pfad' => $path,
            ]);
        }

        return $removed;
    }

    /**
     * Wirft die Dateien eines Projekts weg.
     *
     * Nötig, weil der Fremdschlüssel kaskadiert: ein gelöschtes Projekt nimmt alle
     * Zeilen mit, und ohne diesen Weg bliebe jede Datei liegen — unerreichbar für
     * das Aufräumen, das über Projekte und dann über Zeilen geht. Genau das macht
     * das Projekt im Ablagepfad überhaupt nützlich ({@see pathFor()}).
     *
     * Aufgerufen wird sie beim Löschen des Projekts ({@see Project}).
     */
    public function forgetProject(int $projectId): void
    {
        $directory = trim((string) config('attachments.path'), '/').'/'.$projectId;

        if (! $this->disk()->deleteDirectory($directory)) {
            // Kein Abbruch: das Projekt ist weg, und ein Fehler hier darf das
            // Löschen nicht zurücknehmen. Er gehört aber ins Protokoll — sonst ist
            // es genau der Platzverbrauch, den niemand erklären kann.
            Log::warning('Anhang-Ablage eines gelöschten Projekts blieb liegen.', [
                'projekt' => $projectId,
                'ordner' => $directory,
            ]);
        }
    }

    /**
     * Schneidet ein angebrochenes Mehrbyte-Zeichen am Ende ab.
     *
     * `mb_convert_encoding` würde es durch ein Ersatzzeichen ersetzen — das wäre
     * dasselbe Bild, nur von uns erzeugt. Hier fällt es weg: ein Anriss ist
     * ohnehin unvollständig, und ein fehlendes Zeichen ist ehrlicher als ein
     * falsches.
     */
    private static function trimToValidUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Höchstens drei Byte zurück: länger ist keine UTF-8-Folge, und alles
        // darüber hinaus wäre kein angebrochenes Zeichen mehr, sondern eine Datei,
        // die kein UTF-8 ist.
        for ($cut = 1; $cut <= 3 && $cut < strlen($text); $cut++) {
            $candidate = substr($text, 0, -$cut);

            if (mb_check_encoding($candidate, 'UTF-8')) {
                return $candidate;
            }
        }

        return $text;
    }

    /**
     * Wie viele Anhänge diese Meldung schon trägt — die Zahl, an der das
     * Mengenlimit hängt.
     *
     * Gezählt wird über Projekt und Nummer und nicht über einen Ereignis-Bezug:
     * die Meldung selbst ist zu diesem Zeitpunkt oft noch nicht ausgewertet.
     */
    public function countFor(int $projectId, string $eventReference): int
    {
        return EventAttachment::query()
            ->where('project_id', $projectId)
            ->where('event_reference', $eventReference)
            ->count();
    }

    /**
     * Wohin der Inhalt gelegt wird.
     *
     * Das Projekt steht im Pfad, obwohl die Prüfsumme allein eindeutig wäre — aus
     * demselben Grund wie bei den Bauartefakten: ein gelöschtes Projekt soll sich
     * als Ordner wegwerfen lassen, ohne die Zeilen aller anderen zu befragen. Der
     * Nebeneffekt ist erwünscht: zwei Projekte teilen keine Dateien, und damit
     * verrät auch keine Prüfsumme, dass ein anderes Projekt dasselbe Bild hat.
     */
    private function pathFor(int $projectId, string $checksum): string
    {
        return trim((string) config('attachments.path'), '/')
            .'/'.$projectId
            // Zwei Zeichen als Unterordner: bei hunderttausend Dateien je Projekt
            // ist ein einzelnes Verzeichnis auf einer echten Platte nicht mehr
            // benutzbar, und beim Objektspeicher verteilt es die Schlüssel.
            .'/'.substr($checksum, 0, 2)
            .'/'.$checksum;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('attachments.disk'));
    }
}

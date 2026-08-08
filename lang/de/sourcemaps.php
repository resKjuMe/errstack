<?php

return [

    // Meldungen der Artefakt-Schnittstelle. Sie gehen an eine
    // Auslieferungs-Pipeline und nicht an einen Menschen vor einem Formular —
    // deshalb steht die Grenze als Zahl darin: der Bauschritt, der die Meldung
    // ausgibt, soll ohne Nachschlagen zu beheben sein.
    'errors' => [
        'too_many_files' => 'Diese Version hat die höchstzulässige Anzahl an Artefakten erreicht (:limit). Nicht mehr benötigte Dateien löschen oder eine neue Version anlegen.',
    ],

];

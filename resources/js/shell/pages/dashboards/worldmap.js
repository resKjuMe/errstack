// Die Weltkarte als Zeichnung: grobe Umrisse der Landmassen und ein Ort je Land.
//
// **Es ist eine schematische Karte und keine Landvermessung.** Die Umrisse sind
// von Hand vereinfachte Polygone, die Länder sitzen an einem Mittelpunkt. Für
// die Frage, die eine Kachel stellt — „wo sitzen die Betroffenen" —, ist das
// genug: gefragt ist die Verteilung über die Welt und nicht der Grenzverlauf.
//
// **Warum keine Bibliothek und keine Geodaten.** Eine echte Karte kostet ein
// Vielfaches der ganzen Anwendung an Ausliefergröße, und zwar auf jeder Seite,
// die sie einmal zeigt. Die übrigen Grafiken der Anwendung sind aus demselben
// Grund handgeschriebenes SVG.
//
// Projektion: äquidistant zylindrisch (Plate Carrée). Der Bildbereich ist
// deshalb 360 × 180 groß, und ein Punkt ist schlicht `x = Länge + 180`,
// `y = 90 − Breite`. Das ist die Projektion, in der Grönland zu groß aussieht —
// dafür ist sie in zwei Zeilen richtig gerechnet und braucht keine Bibliothek.

export const MAP_WIDTH = 360;

export const MAP_HEIGHT = 180;

export function project([lon, lat]) {
    return [lon + 180, 90 - lat];
}

function polygon(points) {
    return points.map(project).map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`);
}

// Die Landmassen, grob. Antarktis fehlt: dort sitzt niemand, der eine Anwendung
// benutzt, und der Streifen am unteren Rand nähme ein Fünftel der Karte ein.
export const LANDMASSES = [
    // Nordamerika samt Alaska und Mittelamerika.
    polygon([
        [-168, 66],
        [-160, 71],
        [-140, 70],
        [-125, 70],
        [-100, 73],
        [-80, 73],
        [-62, 66],
        [-56, 51],
        [-66, 45],
        [-70, 42],
        [-81, 25],
        [-90, 29],
        [-97, 26],
        [-92, 19],
        [-84, 10],
        [-78, 8],
        [-83, 15],
        [-95, 16],
        [-105, 20],
        [-114, 28],
        [-124, 40],
        [-125, 49],
        [-133, 55],
        [-150, 59],
        [-165, 60],
    ]),
    // Grönland.
    polygon([
        [-45, 60],
        [-53, 68],
        [-55, 76],
        [-40, 83],
        [-20, 80],
        [-20, 70],
        [-33, 66],
    ]),
    // Südamerika.
    polygon([
        [-81, 8],
        [-72, 12],
        [-60, 10],
        [-51, 5],
        [-35, -5],
        [-38, -13],
        [-48, -25],
        [-54, -34],
        [-62, -40],
        [-66, -55],
        [-73, -52],
        [-71, -40],
        [-73, -20],
        [-77, -6],
        [-80, 0],
    ]),
    // Europa mit Skandinavien.
    polygon([
        [-10, 36],
        [-9, 43],
        [-1, 48],
        [2, 51],
        [8, 54],
        [8, 58],
        [5, 62],
        [12, 68],
        [26, 71],
        [30, 66],
        [40, 66],
        [40, 60],
        [30, 52],
        [28, 45],
        [20, 40],
        [14, 38],
        [12, 45],
        [3, 43],
        [-2, 36],
    ]),
    // Afrika.
    polygon([
        [-17, 15],
        [-16, 22],
        [-10, 30],
        [0, 36],
        [10, 37],
        [20, 32],
        [33, 31],
        [37, 15],
        [43, 11],
        [51, 12],
        [42, -1],
        [40, -15],
        [35, -24],
        [25, -34],
        [18, -34],
        [12, -17],
        [9, -1],
        [0, 5],
        [-8, 4],
        [-13, 9],
    ]),
    // Asien.
    polygon([
        [30, 66],
        [60, 72],
        [90, 76],
        [130, 73],
        [160, 70],
        [170, 66],
        [160, 60],
        [143, 54],
        [135, 45],
        [127, 38],
        [122, 30],
        [110, 21],
        [105, 10],
        [100, 6],
        [95, 16],
        [88, 21],
        [80, 8],
        [72, 20],
        [62, 25],
        [50, 28],
        [43, 12],
        [35, 30],
        [40, 40],
        [50, 45],
        [60, 45],
        [60, 55],
        [45, 58],
        [40, 66],
    ]),
    // Indonesien und die Philippinen, sehr grob als ein Band.
    polygon([
        [95, 6],
        [120, 8],
        [127, 2],
        [140, -3],
        [138, -8],
        [120, -9],
        [105, -7],
        [96, 0],
    ]),
    // Australien und Neuseeland.
    polygon([
        [113, -22],
        [122, -18],
        [131, -12],
        [142, -11],
        [147, -19],
        [153, -28],
        [150, -37],
        [141, -38],
        [131, -32],
        [118, -34],
    ]),
    polygon([
        [166, -46],
        [172, -34],
        [178, -38],
        [172, -46],
    ]),
];

// Wo ein Land sitzt — Länge und Breite seines ungefähren Mittelpunkts.
//
// Die Liste ist nicht vollständig, und das ist Absicht: sie deckt ab, woher
// Anwendungen tatsächlich benutzt werden. Ein Kürzel, das hier fehlt, fällt
// nicht unter den Tisch — die Kachel zählt es unter „ohne Ort" auf, statt es
// stillschweigend an den Nullpunkt zu setzen.
export const COUNTRY_CENTROIDS = {
    AE: [54, 24],
    AR: [-64, -34],
    AT: [14.5, 47.5],
    AU: [134, -25],
    BD: [90, 24],
    BE: [4.5, 50.6],
    BG: [25, 43],
    BR: [-52, -12],
    BY: [28, 53.5],
    CA: [-106, 58],
    CH: [8.2, 46.8],
    CL: [-71, -35],
    CN: [104, 35],
    CO: [-74, 4],
    CZ: [15.5, 49.8],
    DE: [10.4, 51.2],
    DK: [10, 56],
    DZ: [3, 28],
    EC: [-78, -2],
    EE: [26, 59],
    EG: [30, 27],
    ES: [-3.7, 40.3],
    ET: [40, 9],
    FI: [26, 64],
    FR: [2.3, 46.6],
    GB: [-2, 54],
    GH: [-1, 8],
    GR: [22, 39],
    HK: [114.2, 22.3],
    HR: [16, 45.2],
    HU: [19.5, 47.2],
    ID: [113, -2],
    IE: [-8, 53.2],
    IL: [35, 31.5],
    IN: [79, 22],
    IQ: [44, 33],
    IR: [53, 32],
    IS: [-19, 65],
    IT: [12.5, 42.8],
    JP: [138, 36],
    KE: [38, 0],
    KR: [128, 36],
    KZ: [67, 48],
    LT: [24, 55.3],
    LU: [6.1, 49.8],
    LV: [25, 57],
    MA: [-6, 32],
    MX: [-102, 23],
    MY: [102, 4],
    NG: [8, 9.5],
    NL: [5.5, 52.2],
    NO: [10, 62],
    NZ: [172, -41],
    PE: [-76, -10],
    PH: [122, 12],
    PK: [70, 30],
    PL: [19.4, 52],
    PT: [-8, 39.5],
    RO: [25, 45.9],
    RS: [21, 44],
    RU: [90, 60],
    SA: [45, 24],
    SE: [16, 62],
    SG: [103.8, 1.35],
    SI: [15, 46.1],
    SK: [19.5, 48.7],
    TH: [101, 15],
    TR: [35, 39],
    TW: [121, 23.7],
    UA: [31, 49],
    US: [-98, 39],
    UY: [-56, -33],
    VE: [-66, 7],
    VN: [106, 16],
    ZA: [24, -29],
};

/**
 * Der Ort eines Länderkürzels — oder `null`, wenn die Liste es nicht kennt.
 */
export function locate(code) {
    if (typeof code !== 'string') {
        return null;
    }

    const centroid = COUNTRY_CENTROIDS[code.trim().toUpperCase()];

    return centroid ? project(centroid) : null;
}

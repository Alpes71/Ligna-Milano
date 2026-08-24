<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) { require_once $localConfig; }
require_once __DIR__ . '/smtp-mailer.php';

/* ---------- Utilidades ---------- */
function sv_strlen(string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }
function sv_substr(string $v, int $s, int $l): string { return function_exists('mb_substr') ? mb_substr($v, $s, $l, 'UTF-8') : substr($v, $s, $l); }
function sv_clean(string $v, int $max = 200): string {
    $v = trim($v);
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
    if (sv_strlen($v) > $max) $v = sv_substr($v, 0, $max);
    return trim($v);
}
function sv_clean_multiline(string $v, int $max = 600): string {
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
    // en CSV colapsamos saltos de línea a " / " para que la celda quede en una línea
    $v = str_replace("\n", ' / ', $v);
    if (sv_strlen($v) > $max) $v = sv_substr($v, 0, $max);
    return trim($v);
}
function sv_out(array $p, int $code = 200): void {
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function sv_int_range($v, int $min, int $max): ?int {
    if ($v === '' || $v === null) return null;
    if (!preg_match('/^-?\d+$/', (string)$v)) return null;
    $n = (int)$v;
    return ($n >= $min && $n <= $max) ? $n : null;
}
/** Escapa un campo para CSV (comillas dobles, siempre entrecomillado). */
function sv_csv_field(string $v): string {
    return '"' . str_replace('"', '""', $v) . '"';
}

/* ---------- Honeypot ---------- */
if (!empty($_POST['_lm_website'] ?? '')) { sv_out(['ok' => true, 'message' => 'OK']); }

/* ---------- Campos ---------- */
$idioma = sv_clean((string)($_POST['idioma'] ?? 'es'), 5);
$idioma = in_array($idioma, ['es','ca','va','eu','gl','en','fr','de'], true) ? $idioma : 'es';
$idiomaLabel = sv_clean((string)($_POST['idiomaLabel'] ?? ''), 30);

$comoConociste = sv_clean((string)($_POST['comoConociste'] ?? ''), 60);
$producto      = sv_clean((string)($_POST['producto'] ?? ''), 120);
$expCompra     = sv_int_range($_POST['expCompra'] ?? '', 1, 5);
$calidad       = sv_int_range($_POST['calidadProducto'] ?? '', 1, 5);
$nps           = sv_int_range($_POST['nps'] ?? '', 0, 10);
$sugerencia    = sv_clean_multiline((string)($_POST['sugerencia'] ?? ''), 600);
$comentarios   = sv_clean_multiline((string)($_POST['comentarios'] ?? ''), 600);
$privacy       = (string)($_POST['privacyAccepted'] ?? '') === '1';

/* ---------- Validación (obligatorias: 3, 4, 5 + privacidad) ---------- */
if ($expCompra === null || $calidad === null || $nps === null) {
    sv_out(['ok' => false, 'message' => 'Faltan las valoraciones obligatorias.'], 422);
}
if (!$privacy) {
    sv_out(['ok' => false, 'message' => 'Debes aceptar la política de privacidad.'], 422);
}

/* ---------- Acumular en CSV protegido ---------- */
$dir = __DIR__ . '/storage/encuestas';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
// Protección extra: .htaccess que impide el acceso web directo a la carpeta
$ht = $dir . '/.htaccess';
if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");

$csv = $dir . '/encuestas.csv';
$nueva = !is_file($csv);

$fecha = date('Y-m-d H:i:s');
$fila = [
    $fecha, $idioma,
    $comoConociste, $producto,
    (string)$expCompra, (string)$calidad, (string)$nps,
    $sugerencia, $comentarios
];
$cabecera = ['Fecha','Idioma','Como nos conocio','Producto','Experiencia (1-5)','Calidad (1-5)','NPS (0-10)','Sugerencia','Comentarios'];

$fp = @fopen($csv, 'a');
$guardado = false;
if ($fp !== false) {
    if (@flock($fp, LOCK_EX)) {
        // BOM UTF-8 al crear, para que Excel abra los acentos bien
        if ($nueva) {
            fwrite($fp, "\xEF\xBB\xBF");
            fwrite($fp, implode(';', array_map('sv_csv_field', $cabecera)) . "\r\n");
        }
        fwrite($fp, implode(';', array_map('sv_csv_field', $fila)) . "\r\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        $guardado = true;
    }
    fclose($fp);
}

/* ---------- Correo a info@ con la fila en CSV adjunto ---------- */
$to   = defined('LIGNA_TO_EMAIL') ? (string)LIGNA_TO_EMAIL : 'info@lignamilano.com';
$from = defined('LIGNA_FROM_EMAIL') ? (string)LIGNA_FROM_EMAIL : 'webform@lignamilano.com';

// CSV de una sola respuesta (con cabecera + BOM) para adjuntar
$csvUnico = "\xEF\xBB\xBF"
    . implode(';', array_map('sv_csv_field', $cabecera)) . "\r\n"
    . implode(';', array_map('sv_csv_field', $fila)) . "\r\n";

$body = implode("\n", [
    'Nueva respuesta de la ENCUESTA de satisfacción',
    'Idioma del cliente: ' . ($idiomaLabel !== '' ? $idiomaLabel : $idioma),
    '',
    'Cómo nos conoció: ' . ($comoConociste !== '' ? $comoConociste : '(sin respuesta)'),
    'Producto comprado: ' . ($producto !== '' ? $producto : '(sin respuesta)'),
    'Experiencia de compra: ' . $expCompra . ' / 5',
    'Calidad del producto: ' . $calidad . ' / 5',
    'Recomendación (NPS): ' . $nps . ' / 10',
    'Sugerencia de producto: ' . ($sugerencia !== '' ? $sugerencia : '(sin respuesta)'),
    'Comentarios: ' . ($comentarios !== '' ? $comentarios : '(sin respuesta)'),
    '',
    'Se adjunta esta respuesta en CSV. Todas las respuestas se acumulan en el',
    'servidor; descárgalas juntas en: descargar-encuestas.php',
    '',
    ($guardado ? 'Estado: guardada en el CSV acumulado.' : 'AVISO: no se pudo escribir el CSV acumulado; revisa permisos de storage/encuestas/.'),
]);

$adjuntos = [[
    'name' => 'encuesta-' . date('Ymd-His') . '.csv',
    'mime' => 'text/csv; charset=UTF-8',
    'data' => $csvUnico
]];

$sent = false;
try {
    $sent = ligna_send_email_with_attachments($to, 'Nueva encuesta de satisfacción', $body, $adjuntos);
} catch (Throwable $e) {
    error_log('Ligna encuesta error: ' . $e->getMessage());
}

/* ---------- Respuesta ---------- */
// Si al menos se guardó en el CSV, damos éxito aunque el correo falle
if (!$guardado && !$sent) {
    sv_out(['ok' => false, 'message' => 'No se pudo registrar la encuesta. Inténtalo de nuevo o escríbenos por WhatsApp.'], 500);
}
sv_out(['ok' => true, 'message' => 'Encuesta enviada correctamente.']);

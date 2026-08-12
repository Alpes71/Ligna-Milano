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
if (is_file($localConfig)) {
    require_once $localConfig;
}
require_once __DIR__ . '/smtp-mailer.php';

/* ---------- Utilidades ---------- */
function sim_u_strlen(string $v): int {
    return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
}
function sim_u_substr(string $v, int $s, int $l): string {
    return function_exists('mb_substr') ? mb_substr($v, $s, $l, 'UTF-8') : substr($v, $s, $l);
}
function sim_clean(string $v, int $max = 200): string {
    $v = trim($v);
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
    if (sim_u_strlen($v) > $max) $v = sim_u_substr($v, 0, $max);
    return trim($v);
}
function sim_out(array $p, int $code = 200): void {
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Decodifica un data URL de imagen (data:image/jpeg;base64,....).
 * Devuelve [mime, binario] o null si no es válido o supera el límite.
 */
function sim_decode_data_url(string $dataUrl, int $maxBytes): ?array {
    if (!preg_match('#^data:(image/(?:png|jpeg));base64,#', $dataUrl, $m)) {
        return null;
    }
    $mime = $m[1];
    $b64 = substr($dataUrl, strlen($m[0]));
    $b64 = strtr($b64, ' ', '+');
    // Límite antes de decodificar: base64 abulta ~4/3.
    if (strlen($b64) > (int)($maxBytes * 1.4)) {
        return null;
    }
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '' || strlen($bin) > $maxBytes) {
        return null;
    }
    // Verificación real: que sea una imagen de verdad.
    $info = @getimagesizefromstring($bin);
    if ($info === false) {
        return null;
    }
    $realMime = $info['mime'] ?? '';
    if (!in_array($realMime, ['image/png', 'image/jpeg'], true)) {
        return null;
    }
    return [$realMime, $bin];
}

function sim_ext_from_mime(string $mime): string {
    return $mime === 'image/png' ? 'png' : 'jpg';
}

function sim_storage_dir(): string {
    $dir = __DIR__ . '/storage/simulador-leads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/* ---------- Honeypot ---------- */
if (!empty($_POST['_lm_website'] ?? '')) {
    // Bot: fingir éxito sin hacer nada.
    sim_out(['ok' => true, 'message' => 'Solicitud enviada.']);
}

/* ---------- Campos ---------- */
$nombre   = sim_clean((string)($_POST['nombre'] ?? ''), 80);
$apellido = sim_clean((string)($_POST['apellido'] ?? ''), 80);
$email    = sim_clean((string)($_POST['email'] ?? ''), 180);
$movil    = sim_clean((string)($_POST['movil'] ?? ''), 40);
$producto = sim_clean((string)($_POST['productLabel'] ?? $_POST['productValue'] ?? ''), 120);
$estilo   = sim_clean((string)($_POST['styleLabel'] ?? ''), 120);
$page     = sim_clean((string)($_POST['page'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 300);
$privacy  = (string)($_POST['privacyAccepted'] ?? '') === '1';

if (!$privacy) {
    sim_out(['ok' => false, 'message' => 'Debes aceptar la política de privacidad.'], 422);
}
if ($nombre === '' || $apellido === '' || $email === '' || $movil === '') {
    sim_out(['ok' => false, 'message' => 'Faltan campos obligatorios (nombre, apellido, email y móvil).'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sim_out(['ok' => false, 'message' => 'El email no es válido.'], 422);
}
if ($producto === '') {
    sim_out(['ok' => false, 'message' => 'Falta el producto seleccionado.'], 422);
}

/* ---------- Imágenes ---------- */
$MAX = 5 * 1024 * 1024; // 5 MB por imagen
$attachments = [];

$orig = sim_decode_data_url((string)($_POST['origImage'] ?? ''), $MAX);
if ($orig !== null) {
    $attachments[] = ['name' => 'imagen-cliente.' . sim_ext_from_mime($orig[0]), 'mime' => $orig[0], 'data' => $orig[1]];
}
$sim = sim_decode_data_url((string)($_POST['simImage'] ?? ''), $MAX);
if ($sim !== null) {
    $attachments[] = ['name' => 'simulacion.' . sim_ext_from_mime($sim[0]), 'mime' => $sim[0], 'data' => $sim[1]];
}

if (empty($attachments)) {
    sim_out(['ok' => false, 'message' => 'No se recibió ninguna imagen válida (PNG o JPG, máx. 5 MB).'], 422);
}

/* ---------- Guardado local de respaldo ---------- */
$stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$dir = sim_storage_dir();
foreach ($attachments as $att) {
    @file_put_contents($dir . '/' . $stamp . '-' . $att['name'], $att['data']);
}
@file_put_contents($dir . '/' . $stamp . '.json', json_encode([
    'created_at' => date(DATE_ATOM),
    'nombre' => $nombre, 'apellido' => $apellido,
    'email' => $email, 'movil' => $movil,
    'producto' => $producto, 'estilo' => $estilo,
    'page' => $page, 'attachments' => array_map(fn($a) => $a['name'], $attachments)
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/* ---------- Email ---------- */
$to   = defined('LIGNA_TO_EMAIL') ? (string)LIGNA_TO_EMAIL : 'info@lignamilano.com';
$from = defined('LIGNA_FROM_EMAIL') ? (string)LIGNA_FROM_EMAIL : 'webform@lignamilano.com';
$subject = '[Ligna Milano] Simulador · ' . $producto . ($estilo !== '' ? ' · ' . $estilo : '');

$body = implode("\n", [
    'Nueva solicitud desde el SIMULADOR de lignamilano.com',
    '',
    'Nombre: ' . $nombre . ' ' . $apellido,
    'Email: ' . $email,
    'Móvil: ' . $movil,
    'Producto: ' . $producto,
    'Estilo elegido: ' . ($estilo !== '' ? $estilo : 'No indicado'),
    'Página: ' . ($page !== '' ? $page : 'No indicada'),
    '',
    'Adjuntos: imagen original del cliente y simulación generada en la web.',
    'Nota: la simulación es una aproximación con filtros de navegador, no el resultado final.',
    '',
    'Responder directamente a: ' . $email,
    'Privacidad aceptada: Sí',
    'Origen: simulador web Ligna Milano',
    'Remitente técnico: ' . $from
]);

$sent = false;
try {
    $sent = ligna_send_email_with_attachments($to, $subject, $body, $attachments, $email, $nombre . ' ' . $apellido);
} catch (Throwable $e) {
    error_log('Ligna simulador error: ' . $e->getMessage());
    $sent = false;
}

// Reintento sin Reply-To externo (algunos servidores lo filtran).
if (!$sent) {
    try {
        $sent = ligna_send_email_with_attachments($to, $subject . ' · reintento', $body, $attachments, $from, 'Desde la web');
    } catch (Throwable $e) {
        error_log('Ligna simulador reintento error: ' . $e->getMessage());
    }
}

if (!$sent) {
    sim_out([
        'ok' => false,
        'message' => 'No se pudo enviar el email, pero tu solicitud quedó registrada. Escríbenos por WhatsApp si quieres confirmarla.'
    ], 500);
}

sim_out(['ok' => true, 'message' => 'Solicitud enviada correctamente.']);

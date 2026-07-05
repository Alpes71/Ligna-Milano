<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.']);
    exit;
}

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}
require_once __DIR__ . '/smtp-mailer.php';

function u_strlen(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function u_substr(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function clean_text(string $value, int $maxLength = 1200): string {
    $value = trim($value);
    $value = str_replace(["\r", "\0"], '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    if (u_strlen($value) > $maxLength) {
        $value = u_substr($value, 0, $maxLength);
    }
    return $value;
}

function one_line(string $value, int $maxLength = 160): string {
    $value = clean_text($value, $maxLength);
    return preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
}

function storage_dir_contact(): string {
    $dir = __DIR__ . '/storage/contact-leads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function save_contact_record(array $data): void {
    $dir = storage_dir_contact();
    $file = $dir . '/contact-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// Honeypot antispam con nombre poco probable para evitar autocompletado del navegador.
// El campo anterior "company" se elimina del HTML porque algunos navegadores lo autocompletan y provocan falsos positivos.
if (!empty($_POST['_lm_website'] ?? '')) {
    echo json_encode(['ok' => true, 'message' => 'Mensaje enviado correctamente.']);
    exit;
}

$name = one_line((string)($_POST['name'] ?? ''), 120);
$email = one_line((string)($_POST['email'] ?? ''), 180);
$phone = one_line((string)($_POST['phone'] ?? ''), 80);
$projectType = one_line((string)($_POST['projectType'] ?? ''), 140);
$quantity = one_line((string)($_POST['quantity'] ?? ''), 100);
$message = clean_text((string)($_POST['message'] ?? ''), 4000);
$page = one_line((string)($_POST['page'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 400);
$privacyAccepted = (string)($_POST['privacyAccepted'] ?? '') === '1';

if (!$privacyAccepted) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Debes aceptar la política de privacidad.']);
    exit;
}

if ($name === '' || $email === '' || $projectType === '' || $quantity === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Faltan campos obligatorios.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Email no válido.']);
    exit;
}

$to = defined('LIGNA_TO_EMAIL') ? (string)LIGNA_TO_EMAIL : 'info@lignamilano.com';
$from = defined('LIGNA_FROM_EMAIL') ? (string)LIGNA_FROM_EMAIL : 'webform@lignamilano.com';
$subject = '[Ligna Milano] Nueva consulta web · ' . $projectType;

$body = implode("\n", [
    'Nueva consulta recibida desde lignamilano.com',
    '',
    'Nombre: ' . $name,
    'Email: ' . $email,
    'Teléfono: ' . ($phone !== '' ? $phone : 'No indicado'),
    'Tipo de proyecto: ' . $projectType,
    'Cantidad estimada: ' . $quantity,
    'Página: ' . ($page !== '' ? $page : 'No indicada'),
    '',
    'Mensaje:',
    $message,
    '',
    'Responder directamente a: ' . $email,
    'Privacidad aceptada: Sí',
    'Origen: formulario web Ligna Milano',
    'Remitente técnico: ' . $from
]);

$record = [
    'created_at' => date(DATE_ATOM),
    'source' => 'contact-form',
    'to' => $to,
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'projectType' => $projectType,
    'quantity' => $quantity,
    'message' => $message,
    'page' => $page,
    'privacyAccepted' => $privacyAccepted,
    'sent' => false,
    'retry_without_reply_to' => false
];

$sent = ligna_send_email($to, $subject, $body, $email, $name);

// Reintento conservador. Algunos servidores filtran mensajes con Reply-To externo.
// Si falla, se reintenta manteniendo el email del cliente dentro del cuerpo.
if (!$sent) {
    $record['retry_without_reply_to'] = true;
    error_log('Ligna contact form SMTP first attempt failed. Retrying without external Reply-To.');
    $sent = ligna_send_email($to, $subject . ' · reintento', $body, $from, 'Desde la web');
}

$record['sent'] = $sent;
save_contact_record($record);

if (!$sent) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'El servidor no pudo enviar el email por SMTP. La consulta quedó registrada en storage/contact-leads para diagnóstico.'
    ]);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Mensaje enviado correctamente.']);

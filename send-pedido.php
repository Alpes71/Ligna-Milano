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
function ped_strlen(string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }
function ped_substr(string $v, int $s, int $l): string { return function_exists('mb_substr') ? mb_substr($v, $s, $l, 'UTF-8') : substr($v, $s, $l); }
function ped_clean(string $v, int $max = 200): string {
    $v = trim($v);
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
    if (ped_strlen($v) > $max) $v = ped_substr($v, 0, $max);
    return trim($v);
}
function ped_clean_multiline(string $v, int $max = 800): string {
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
    if (ped_strlen($v) > $max) $v = ped_substr($v, 0, $max);
    return trim($v);
}
function ped_out(array $p, int $code = 200): void {
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ped_decode_receipt(string $dataUrl, int $maxBytes): ?array {
    if (!preg_match('#^data:(image/(?:png|jpeg)|application/pdf);base64,#', $dataUrl, $m)) return null;
    $mime = $m[1];
    $b64 = substr($dataUrl, strlen($m[0]));
    $b64 = strtr($b64, ' ', '+');
    if (strlen($b64) > (int)($maxBytes * 1.4)) return null;
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '' || strlen($bin) > $maxBytes) return null;
    if ($mime === 'application/pdf') {
        if (substr($bin, 0, 4) !== '%PDF') return null;
    } else {
        $info = @getimagesizefromstring($bin);
        if ($info === false || !in_array(($info['mime'] ?? ''), ['image/png','image/jpeg'], true)) return null;
    }
    return [$mime, $bin];
}
function ped_ext(string $mime): string {
    return $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg');
}

/* ---------- Honeypot ---------- */
if (!empty($_POST['_lm_website'] ?? '')) { ped_out(['ok' => true, 'message' => 'OK']); }

/* ---------- Recogida de campos ---------- */
$idioma   = ped_clean((string)($_POST['idioma'] ?? 'es'), 5);
$idiomaOk = in_array($idioma, ['es','ca','va','eu','gl','en','fr','de'], true) ? $idioma : 'es';
$idiomaLabel = ped_clean((string)($_POST['idiomaLabel'] ?? ''), 30);

$f = [
    'nombre'    => ped_clean((string)($_POST['nombre'] ?? ''), 80),
    'apellido1' => ped_clean((string)($_POST['apellido1'] ?? ''), 80),
    'apellido2' => ped_clean((string)($_POST['apellido2'] ?? ''), 80),
    'tipoVia'   => ped_clean((string)($_POST['tipoViaLabel'] ?? $_POST['tipoVia'] ?? ''), 40),
    'nombreVia' => ped_clean((string)($_POST['nombreVia'] ?? ''), 120),
    'numero'    => ped_clean((string)($_POST['numero'] ?? ''), 10),
    'escalera'  => ped_clean((string)($_POST['escalera'] ?? ''), 10),
    'piso'      => ped_clean((string)($_POST['piso'] ?? ''), 10),
    'puerta'    => ped_clean((string)($_POST['puerta'] ?? ''), 10),
    'ciudad'    => ped_clean((string)($_POST['ciudad'] ?? ''), 80),
    'cp'        => ped_clean((string)($_POST['cp'] ?? ''), 12),
    'provincia' => ped_clean((string)($_POST['provincia'] ?? ''), 80),
    'pais'      => ped_clean((string)($_POST['pais'] ?? 'España'), 60),
    'email'     => ped_clean((string)($_POST['email'] ?? ''), 180),
    'movil'     => ped_clean((string)($_POST['movil'] ?? ''), 40),
    'pago'      => ped_clean((string)($_POST['pagoLabel'] ?? $_POST['pago'] ?? ''), 40),
    'referencia'=> ped_clean((string)($_POST['referencia'] ?? ''), 80),
    'comentarios'=> ped_clean_multiline((string)($_POST['comentarios'] ?? ''), 800),
];
$privacy = (string)($_POST['privacyAccepted'] ?? '') === '1';

/* ---------- Validación (los mismos * que el formulario) ---------- */
$oblig = ['nombre','apellido1','tipoVia','nombreVia','numero','piso','puerta','ciudad','cp','provincia','pais','email','movil','pago','referencia'];
foreach ($oblig as $k) {
    if ($f[$k] === '') { ped_out(['ok' => false, 'message' => 'Faltan campos obligatorios.'], 422); }
}
if (!$privacy) { ped_out(['ok' => false, 'message' => 'Debes aceptar la política de privacidad.'], 422); }
if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { ped_out(['ok' => false, 'message' => 'El email no es válido.'], 422); }

/* ---------- Comprobante (opcional) ---------- */
$MAX = 10 * 1024 * 1024;
$attachments = [];
$comprobante = ped_decode_receipt((string)($_POST['comprobante'] ?? ''), $MAX);
if ($comprobante !== null) {
    $nombreArch = ped_clean((string)($_POST['comprobanteNombre'] ?? ('comprobante.' . ped_ext($comprobante[0]))), 120);
    if (!preg_match('/\.(png|jpe?g|pdf)$/i', $nombreArch)) $nombreArch = 'comprobante.' . ped_ext($comprobante[0]);
    $attachments[] = ['name' => $nombreArch, 'mime' => $comprobante[0], 'data' => $comprobante[1]];
}

/* ---------- Respaldo local ---------- */
$stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$dir = __DIR__ . '/storage/pedidos';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
@file_put_contents($dir . '/' . $stamp . '.json', json_encode(
    ['created_at' => date(DATE_ATOM), 'idioma' => $idiomaOk] + $f + ['comprobante' => $attachments[0]['name'] ?? null],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
));
if (!empty($attachments)) {
    @file_put_contents($dir . '/' . $stamp . '-' . $attachments[0]['name'], $attachments[0]['data']);
}

/* ---------- Dirección legible ---------- */
$direccion = $f['tipoVia'] . ' ' . $f['nombreVia'] . ', ' . $f['numero'];
if ($f['escalera'] !== '') $direccion .= ', Esc. ' . $f['escalera'];
$direccion .= ', ' . $f['piso'] . ($f['puerta'] !== '' ? ' ' . $f['puerta'] : '');
$nombreCompleto = trim($f['nombre'] . ' ' . $f['apellido1'] . ' ' . $f['apellido2']);

/* ===========================================================
   1) CORREO PARA LIGNA MILANO — SIEMPRE EN CASTELLANO
   =========================================================== */
$to   = defined('LIGNA_TO_EMAIL') ? (string)LIGNA_TO_EMAIL : 'info@lignamilano.com';
$from = defined('LIGNA_FROM_EMAIL') ? (string)LIGNA_FROM_EMAIL : 'webform@lignamilano.com';

$bodyInterno = implode("\n", [
    'NUEVO PEDIDO — Información de envío y pago',
    'Idioma del cliente: ' . ($idiomaLabel !== '' ? $idiomaLabel : $idiomaOk),
    '',
    '— DATOS PERSONALES —',
    'Nombre y apellidos: ' . $nombreCompleto,
    '',
    '— DIRECCIÓN —',
    $direccion,
    'Ciudad: ' . $f['ciudad'] . '  ·  CP: ' . $f['cp'],
    'Provincia: ' . $f['provincia'] . '  ·  País: ' . $f['pais'],
    '',
    '— CONTACTO —',
    'Email: ' . $f['email'],
    'Móvil: ' . $f['movil'],
    '',
    '— PAGO —',
    'Método: ' . $f['pago'],
    'Referencia de producto: ' . $f['referencia'],
    'Concepto que debe usar el cliente: ' . $f['apellido1'] . ($f['apellido2'] !== '' ? ' ' . $f['apellido2'] : '') . ' / ' . $f['referencia'],
    'Comprobante adjunto: ' . (!empty($attachments) ? 'Sí (' . $attachments[0]['name'] . ')' : 'No (lo enviará por WhatsApp)'),
    '',
    '— COMENTARIOS —',
    ($f['comentarios'] !== '' ? $f['comentarios'] : '(sin comentarios)'),
    '',
    'Privacidad aceptada: Sí',
    'Origen: formulario de pedido (pedido.html)',
    'Responder al cliente: ' . $f['email'],
]);

$sentInterno = false;
try {
    if (!empty($attachments)) {
        $sentInterno = ligna_send_email_with_attachments($to, 'Información de envío y pago', $bodyInterno, $attachments, $f['email'], $nombreCompleto);
    } else {
        $sentInterno = ligna_send_email($to, 'Información de envío y pago', $bodyInterno, $f['email'], $nombreCompleto);
    }
} catch (Throwable $e) {
    error_log('Ligna pedido interno error: ' . $e->getMessage());
}

/* ===========================================================
   2) ACUSE PARA EL CLIENTE — EN SU IDIOMA
   =========================================================== */
$acuse = [
 'es' => ["Comprobante de formulario de pedido",
   "Este correo es un acuse de recibo automático. Por favor, no respondas a este mensaje: esta casilla no está monitorizada.",
   "Hemos recibido la información de tu pedido. Estos son los datos que nos has enviado:",
   ["Nombre","Dirección","Ciudad","Código postal","Provincia","País","Email","Móvil","Método de pago","Referencia de producto","Comprobante adjunto"],
   "Sobre el envío: una vez confirmemos tu pago y hayas dado el visto bueno al diseño, tu pedido se envía en un plazo de 72 horas. Recibirás un correo de Correos con el número de seguimiento.",
   "Sí", "No",
   "Gracias por confiar en Ligna Milano.",
   "Horario de atención: de lunes a viernes de 9 a 18 h · Sábados, domingos y festivos cerrado."],
 'ca' => ["Comprovant de formulari de comanda",
   "Aquest correu és un justificant de recepció automàtic. Si us plau, no responguis aquest missatge: aquesta bústia no està supervisada.",
   "Hem rebut la informació de la teva comanda. Aquestes són les dades que ens has enviat:",
   ["Nom","Adreça","Ciutat","Codi postal","Província","País","Correu electrònic","Mòbil","Mètode de pagament","Referència de producte","Comprovant adjunt"],
   "Sobre l'enviament: un cop confirmem el teu pagament i hagis donat el vistiplau al disseny, la teva comanda s'envia en un termini de 72 hores. Rebràs un correu de Correos amb el número de seguiment.",
   "Sí", "No",
   "Gràcies per confiar en Ligna Milano.",
   "Horari d'atenció: de dilluns a divendres de 9 a 18 h · Dissabtes, diumenges i festius tancat."],
 'va' => ["Comprovant de formulari de comanda",
   "Aquest correu és un justificant de recepció automàtic. Per favor, no responga aquest missatge: esta bústia no està supervisada.",
   "Hem rebut la informació de la teua comanda. Estes són les dades que ens has enviat:",
   ["Nom","Adreça","Ciutat","Codi postal","Província","País","Correu electrònic","Mòbil","Mètode de pagament","Referència de producte","Comprovant adjunt"],
   "Sobre l'enviament: una vegada confirmem el teu pagament i hages donat el vistiplau al disseny, la teua comanda s'envia en un termini de 72 hores. Rebràs un correu de Correos amb el número de seguiment.",
   "Sí", "No",
   "Gràcies per confiar en Ligna Milano.",
   "Horari d'atenció: de dilluns a divendres de 9 a 18 h · Dissabtes, diumenges i festius tancat."],
 'eu' => ["Eskaera formularioaren frogagiria",
   "Mezu hau jasotze-agiri automatiko bat da. Mesedez, ez erantzun mezu honi: postontzi hau ez da gainbegiratzen.",
   "Zure eskaeraren informazioa jaso dugu. Hauek dira bidali dizkiguzun datuak:",
   ["Izena","Helbidea","Herria","Posta kodea","Probintzia","Herrialdea","Posta elektronikoa","Mugikorra","Ordainketa modua","Produktu erreferentzia","Frogagiria erantsita"],
   "Bidalketari buruz: zure ordainketa berretsi eta diseinuari oniritzia eman ondoren, zure eskaera 72 orduko epean bidaltzen da. Correos-en mezu bat jasoko duzu jarraipen zenbakiarekin.",
   "Bai", "Ez",
   "Eskerrik asko Ligna Milanogan konfiantza jartzeagatik.",
   "Arreta ordutegia: astelehenetik ostiralera 9etatik 18etara · Larunbat, igande eta jaiegunetan itxita."],
 'gl' => ["Xustificante de formulario de pedido",
   "Este correo é un xustificante de recepción automático. Por favor, non respondas a esta mensaxe: esta caixa non está supervisada.",
   "Recibimos a información do teu pedido. Estes son os datos que nos enviaches:",
   ["Nome","Enderezo","Cidade","Código postal","Provincia","País","Correo electrónico","Móbil","Método de pagamento","Referencia de produto","Xustificante adxunto"],
   "Sobre o envío: unha vez confirmemos o teu pagamento e deras o visto e prace ao deseño, o teu pedido envíase nun prazo de 72 horas. Recibirás un correo de Correos co número de seguimento.",
   "Si", "Non",
   "Grazas por confiar en Ligna Milano.",
   "Horario de atención: de luns a venres de 9 a 18 h · Sábados, domingos e festivos pechado."],
 'en' => ["Order form receipt",
   "This email is an automatic acknowledgement of receipt. Please do not reply to this message: this mailbox is not monitored.",
   "We've received your order information. Here are the details you sent us:",
   ["Name","Address","City","Postal code","Province","Country","Email","Mobile","Payment method","Product reference","Receipt attached"],
   "About shipping: once we confirm your payment and you approve the design, your order ships within 72 hours. You'll receive an email from Correos with the tracking number.",
   "Yes", "No",
   "Thank you for trusting Ligna Milano.",
   "Opening hours: Monday to Friday, 9 a.m. to 6 p.m. · Closed on weekends and public holidays."],
 'fr' => ["Justificatif de formulaire de commande",
   "Cet e-mail est un accusé de réception automatique. Merci de ne pas répondre à ce message : cette boîte n'est pas surveillée.",
   "Nous avons bien reçu les informations de votre commande. Voici les données que vous nous avez envoyées :",
   ["Nom","Adresse","Ville","Code postal","Province","Pays","E-mail","Portable","Mode de paiement","Référence produit","Justificatif joint"],
   "À propos de l'expédition : une fois votre paiement confirmé et le design validé, votre commande est expédiée sous 72 heures. Vous recevrez un e-mail de Correos avec le numéro de suivi.",
   "Oui", "Non",
   "Merci de votre confiance envers Ligna Milano.",
   "Horaires d'ouverture : du lundi au vendredi de 9 h à 18 h · Fermé les week-ends et jours fériés."],
 'de' => ["Bestätigung des Bestellformulars",
   "Diese E-Mail ist eine automatische Empfangsbestätigung. Bitte antworten Sie nicht auf diese Nachricht: Dieses Postfach wird nicht überwacht.",
   "Wir haben Ihre Bestellinformationen erhalten. Dies sind die von Ihnen übermittelten Daten:",
   ["Name","Adresse","Stadt","Postleitzahl","Provinz","Land","E-Mail","Mobil","Zahlungsart","Produktreferenz","Beleg angehängt"],
   "Zum Versand: Sobald wir Ihre Zahlung bestätigt haben und Sie das Design freigegeben haben, wird Ihre Bestellung innerhalb von 72 Stunden versandt. Sie erhalten eine E-Mail von Correos mit der Sendungsnummer.",
   "Ja", "Nein",
   "Vielen Dank für Ihr Vertrauen in Ligna Milano.",
   "Öffnungszeiten: Montag bis Freitag von 9 bis 18 Uhr · An Wochenenden und Feiertagen geschlossen."],
];
$a = $acuse[$idiomaOk] ?? $acuse['es'];
$lbl = $a[3];
$siNo = !empty($attachments) ? $a[5] : $a[6];

$bodyCliente = implode("\n", [
    $a[1],
    '',
    '— — —',
    '',
    $a[2],
    '',
    $lbl[0] . ': ' . $nombreCompleto,
    $lbl[1] . ': ' . $direccion,
    $lbl[2] . ': ' . $f['ciudad'],
    $lbl[3] . ': ' . $f['cp'],
    $lbl[4] . ': ' . $f['provincia'],
    $lbl[5] . ': ' . $f['pais'],
    $lbl[6] . ': ' . $f['email'],
    $lbl[7] . ': ' . $f['movil'],
    $lbl[8] . ': ' . $f['pago'],
    $lbl[9] . ': ' . $f['referencia'],
    $lbl[10] . ': ' . $siNo,
    '',
    '— — —',
    '',
    $a[4],
    '',
    $a[7],
    '',
    'Instagram: @lignamilano',
    'WhatsApp: +34 615 30 43 50',
    'Email: info@lignamilano.com',
    $a[8],
]);

// El acuse va como email simple (sin adjuntos), remitente webform@
try {
    ligna_send_email($f['email'], $a[0], $bodyCliente);
} catch (Throwable $e) {
    error_log('Ligna pedido acuse error: ' . $e->getMessage());
}

/* ---------- Respuesta ---------- */
if (!$sentInterno) {
    ped_out(['ok' => false, 'message' => 'No se pudo enviar el pedido, pero quedó registrado. Escríbenos por WhatsApp.'], 500);
}
ped_out(['ok' => true, 'message' => 'Pedido enviado correctamente.']);

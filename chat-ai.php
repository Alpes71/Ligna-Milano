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

function env_or_const(string $name, string $default = ''): string {
    if (defined($name)) {
        return (string)constant($name);
    }
    $value = getenv($name);
    return $value !== false ? (string)$value : $default;
}

function bool_env_or_const(string $name, bool $default = false): bool {
    if (defined($name)) {
        $value = constant($name);
    } else {
        $value = getenv($name);
        if ($value === false) return $default;
    }
    if (is_bool($value)) return $value;
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'debug'], true);
}

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function u_strlen(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function u_substr(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function u_strtolower(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function clean_text(string $value, int $maxLength = 1600): string {
    $value = trim($value);
    $value = str_replace(["\r", "\0"], '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    if (u_strlen($value) > $maxLength) {
        $value = u_substr($value, 0, $maxLength);
    }
    return $value;
}

function one_line(string $value, int $maxLength = 180): string {
    return preg_replace('/[\r\n]+/', ' ', clean_text($value, $maxLength)) ?? clean_text($value, $maxLength);
}

function normalize_history(array $history): array {
    $items = [];
    $history = array_slice($history, -24);
    foreach ($history as $item) {
        if (!is_array($item)) continue;
        $roleRaw = u_strtolower((string)($item['role'] ?? ''));
        $text = clean_text((string)($item['text'] ?? $item['content'] ?? ''), 1400);
        if ($text === '') continue;
        $role = (strpos($roleRaw, 'cliente') !== false || $roleRaw === 'user') ? 'user' : 'assistant';
        $items[] = ['role' => $role, 'content' => $text];
    }
    return $items;
}

function last_history_equals_message(array $history, string $message): bool {
    if ($message === '' || empty($history)) return false;
    $last = $history[count($history) - 1]['content'] ?? '';
    return trim(u_strtolower((string)$last)) === trim(u_strtolower($message));
}

function client_text(array $history, string $message): string {
    $parts = [];
    foreach ($history as $item) {
        if (($item['role'] ?? '') === 'user') {
            $content = clean_text((string)($item['content'] ?? ''), 1400);
            if ($content !== '') $parts[] = $content;
        }
    }
    if ($message !== '' && !last_history_equals_message($history, $message)) {
        $parts[] = $message;
    }
    return implode("\n", $parts);
}

function all_conversation_text(array $history, string $message): string {
    $parts = [];
    foreach ($history as $item) {
        $content = clean_text((string)($item['content'] ?? ''), 1400);
        if ($content !== '') $parts[] = $content;
    }
    if ($message !== '' && !last_history_equals_message($history, $message)) {
        $parts[] = $message;
    }
    return implode("\n", $parts);
}

function extract_name(string $text): string {
    $patterns = [
        '/\bmi\s+nombre\s+es\s+([A-Za-zÁÉÍÓÚÜÑáéíóúüñ][A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s\'\-]{1,45})/iu',
        '/\bme\s+llamo\s+([A-Za-zÁÉÍÓÚÜÑáéíóúüñ][A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s\'\-]{1,45})/iu',
        '/\bsoy\s+([A-Za-zÁÉÍÓÚÜÑáéíóúüñ][A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s\'\-]{1,35})(?:[,\.\n]|$)/iu'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $name = trim((string)$m[1]);
            $name = preg_replace('/\s+(estaba|quería|queria|quiero|necesito|busco|pensando).*$/iu', '', $name) ?? $name;
            $name = trim($name, " .,;:\t\n\r\0\x0B");
            if ($name !== '' && !preg_match('/^(el|la|un|una|asistente|cliente|web)$/iu', $name)) return one_line($name, 45);
        }
    }
    return '';
}

function keyword_present(string $text, array $keywords): bool {
    $value = u_strtolower($text);
    foreach ($keywords as $kw) {
        if (strpos($value, u_strtolower($kw)) !== false) return true;
    }
    return false;
}

function extract_materials(string $text): array {
    $map = [
        'acrílico' => ['acrílico', 'acrilico', 'metacrilato', 'plexiglas'],
        'vidrio' => ['vidrio', 'cristal'],
        'madera' => ['madera', 'mdf', 'contrachapado', 'abedul', 'chopo', 'nogal', 'roble', 'fresno', 'bambú', 'bambu'],
        'metal' => ['metal', 'acero', 'aluminio', 'latón', 'laton', 'inoxidable'],
        'cuero' => ['cuero', 'piel', 'vaqueta', 'billetera', 'cartera'],
        'cerámica' => ['cerámica', 'ceramica', 'porcelana'],
        'vinilo' => ['vinilo', 'adhesivo', 'pegatina'],
        'papel premium' => ['papel', 'fedrigoni', 'favini', 'gmund', 'colorplan', 'cartulina'],
        'piedra' => ['piedra', 'pizarra'],
        'plástico' => ['plástico', 'plastico', 'pla', 'petg', 'abs', 'resina']
    ];
    $found = [];
    foreach ($map as $label => $keys) {
        if (keyword_present($text, $keys)) $found[] = $label;
    }
    return array_values(array_unique($found));
}

function extract_use(string $text): string {
    $value = u_strtolower($text);
    if (preg_match('/\b(regalo|obsequio|detalle)\b/u', $value)) return 'regalo';
    if (preg_match('/\b(empresa|corporativo|cliente|clientes|equipo|empleados|branding)\b/u', $value)) return 'corporativo';
    if (preg_match('/\b(boda|novios|invitados)\b/u', $value)) return 'boda';
    if (preg_match('/\b(evento|cumpleaños|cumpleanos|aniversario|feria)\b/u', $value)) return 'evento';
    if (preg_match('/\b(navidad|navideñ[oa]|adviento)\b/u', $value)) return 'Navidad';
    if (preg_match('/\b(mascota|perro|gato)\b/u', $value)) return 'mascotas';
    if (preg_match('/\b(decoraci[oó]n|decorativo|adornos?|hogar)\b/u', $value)) return 'decoración';
    if (preg_match('/\b(prototipo|maqueta|muestra|pieza funcional)\b/u', $value)) return 'prototipo';
    return '';
}

function extract_object(string $text): string {
    $value = u_strtolower($text);
    $objects = [
        'cubo' => ['cubo', 'bloque'],
        'placa' => ['placa', 'chapita', 'señal', 'senal'],
        'botella' => ['botella', 'termo', 'cantimplora'],
        'llavero' => ['llavero'],
        'billetera/cartera' => ['billetera', 'cartera'],
        'caja' => ['caja', 'packaging', 'estuche'],
        'cuadro con textura' => ['cuadro', 'lienzo', 'retrato'],
        'seating plan / cartel de boda' => ['seating', 'marcasitios', 'número de mesa', 'numero de mesa'],
        'invitación' => ['invitación', 'invitacion', 'invitaciones'],
        'adorno decorativo' => ['adorno', 'figura', 'objeto decorativo', 'bola de navidad'],
        'trofeo/reconocimiento' => ['trofeo', 'premio', 'reconocimiento', 'diploma'],
        'imán/souvenir' => ['imán', 'iman', 'souvenir', 'postal'],
        'marcapáginas' => ['marcapáginas', 'marcapaginas', 'agenda', 'cuaderno'],
        'pieza 3D' => ['pieza 3d', 'impresión 3d', 'impresion 3d', 'prototipo'],
        'imagen impresa' => ['imagen grabada', 'foto grabada', 'fotografía grabada', 'fotografia grabada', 'foto con relieve']
    ];
    foreach ($objects as $label => $keys) {
        if (keyword_present($value, $keys)) return $label;
    }
    if (preg_match('/\b(imagen|foto|fotograf[ií]a)\b/u', $value) && preg_match('/\b(grabad[ao]|imprimir|personalizar|relieve)\b/u', $value)) return 'imagen impresa';
    return '';
}

function extract_quantity(string $text): string {
    if (preg_match('/\b(\d{1,5})\s*(unidades|uds|u\.|piezas|copias)?\b/iu', $text, $m)) {
        $n = (int)$m[1];
        if ($n > 0 && $n < 100000) return (string)$n;
    }
    return '';
}

function extract_measures(string $text): string {
    if (preg_match('/\b(\d{1,4}(?:[\.,]\d{1,2})?\s*(?:cm|mm|m)?\s*(?:x|×)\s*\d{1,4}(?:[\.,]\d{1,2})?\s*(?:cm|mm|m)?(?:\s*(?:x|×)\s*\d{1,4}(?:[\.,]\d{1,2})?\s*(?:cm|mm|m)?)?)\b/iu', $text, $m)) {
        return one_line((string)$m[1], 80);
    }
    if (preg_match('/\b(\d{1,4}(?:[\.,]\d{1,2})?\s*(?:cm|mm|metros?|m))\b/iu', $text, $m)) {
        return one_line((string)$m[1], 50);
    }
    return '';
}

function extract_contact(string $text): string {
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
        return strtolower((string)$m[0]);
    }
    if (preg_match('/(?:\+?\d[\d\s().\-]{7,})/', $text, $m)) {
        $candidate = one_line((string)$m[0], 50);
        $digits = preg_replace('/\D+/', '', $candidate) ?? '';
        if (strlen($digits) >= 8) return $candidate;
    }
    return '';
}

function extract_contact_preference(string $text): string {
    $value = u_strtolower($text);
    $lastLines = preg_split('/\n+/', trim($value));
    $last = is_array($lastLines) && $lastLines ? trim((string)end($lastLines)) : $value;

    if (preg_match('/\b(whatsapp|wasap|wsp|m[oó]vil|movil|tel[eé]fono|telefono|llamada|llamar)\b/u', $last)) {
        return 'WhatsApp/teléfono';
    }
    if (preg_match('/\b(email|e-mail|mail|correo)\b/u', $last)) {
        return 'email';
    }
    if (preg_match('/\b(whatsapp|wasap|wsp|m[oó]vil|movil|tel[eé]fono|telefono|llamada|llamar)\b/u', $value)) {
        return 'WhatsApp/teléfono';
    }
    if (preg_match('/\b(email|e-mail|mail|correo)\b/u', $value)) {
        return 'email';
    }
    return '';
}

function extract_file_status(string $text): string {
    $value = u_strtolower($text);
    if (preg_match('/\b(svg|pdf|ai|eps|png|jpg|jpeg|archivo|logo|diseño|diseno|imagen|foto)\b/u', $value)) return 'mencionado';
    return '';
}

function extract_timing(string $text): string {
    if (preg_match('/\b(para\s+(?:el\s+)?\d{1,2}[\/\-\.]\d{1,2}(?:[\/\-\.]\d{2,4})?|esta\s+semana|la\s+semana\s+que\s+viene|urgente|mañana|manana|hoy|en\s+\d+\s+d[ií]as)\b/iu', $text, $m)) {
        return one_line((string)$m[1], 80);
    }
    return '';
}

function detect_process(array $state): string {
    $object = u_strtolower($state['object'] ?? '');
    $materials = $state['materials'] ?? [];
    $materialText = u_strtolower(implode(' ', $materials));
    if (strpos($object, 'pieza 3d') !== false) {
        return 'impresión 3D, un servicio que incorporaremos próximamente; podemos registrar el interés y avisar cuando esté disponible';
    }
    if (strpos($object, 'botella') !== false || strpos($materialText, 'vidrio') !== false || strpos($materialText, 'cerámica') !== false) {
        return 'transferencia UV DTF, ideal para vidrio, cerámica, botellas y superficies curvas';
    }
    if (strpos($materialText, 'metal') !== false) {
        return 'transferencia UV DTF sobre metal, validando superficie y acabado antes de producir';
    }
    if (strpos($materialText, 'cuero') !== false) return 'impresión UV directa a todo color sobre cuero';
    if (strpos($materialText, 'madera') !== false) return 'impresión UV a color sobre madera, con relieve táctil si el diseño lo pide';
    if (strpos($materialText, 'acrílico') !== false) return 'impresión UV directa sobre acrílico, con opción de barniz selectivo';
    if (strpos($materialText, 'papel premium') !== false || strpos($object, 'invitación') !== false) {
        return 'impresión UV sobre papel premium con relieve y barniz selectivo';
    }
    if (strpos($object, 'cuadro') !== false || strpos($object, 'imagen impresa') !== false) {
        return 'impresión UV con textura 3D, para que la pieza se vea y se sienta';
    }
    if (strpos($object, 'placa') !== false || strpos($object, 'llavero') !== false) {
        return 'impresión UV a color con relieve, según material y acabado buscado';
    }
    return '';
}

function extract_state(array $history, string $message): array {
    $text = client_text($history, $message);
    $all = all_conversation_text($history, $message);
    $state = [
        'name' => extract_name($text),
        'object' => extract_object($text),
        'materials' => extract_materials($text),
        'use' => extract_use($text),
        'quantity' => extract_quantity($text),
        'measures' => extract_measures($text),
        'contact' => extract_contact($text),
        'contact_preference' => extract_contact_preference($text),
        'file' => extract_file_status($text),
        'timing' => extract_timing($text),
        'has_context' => trim($all) !== ''
    ];
    $state['process'] = detect_process($state);
    return $state;
}

function summarize_state(array $state): string {
    $parts = [];
    if (($state['name'] ?? '') !== '') $parts[] = 'nombre: ' . $state['name'];
    if (($state['object'] ?? '') !== '') $parts[] = 'objeto/proyecto: ' . $state['object'];
    if (!empty($state['materials'])) $parts[] = 'material: ' . implode(' o ', $state['materials']);
    if (($state['use'] ?? '') !== '') $parts[] = 'uso: ' . $state['use'];
    if (($state['quantity'] ?? '') !== '') $parts[] = 'cantidad: ' . $state['quantity'];
    if (($state['measures'] ?? '') !== '') $parts[] = 'medidas: ' . $state['measures'];
    if (($state['timing'] ?? '') !== '') $parts[] = 'fecha objetivo: ' . $state['timing'];
    if (($state['file'] ?? '') !== '') $parts[] = 'archivo/referencia: mencionado';
    if (($state['contact_preference'] ?? '') !== '') $parts[] = 'canal preferido: ' . $state['contact_preference'];
    if (($state['contact'] ?? '') !== '') $parts[] = 'contacto: ' . $state['contact'];
    if (($state['process'] ?? '') !== '') $parts[] = 'proceso probable: ' . $state['process'];
    return $parts ? implode('; ', $parts) : 'Sin briefing claro todavía.';
}

function category_from_state(array $state, string $message): string {
    $value = u_strtolower($message . ' ' . ($state['object'] ?? '') . ' ' . implode(' ', $state['materials'] ?? []));
    if (preg_match('/whatsapp|persona|humano|hablar|agente/u', $value)) return 'Derivación humana';
    if (preg_match('/presupuesto|precio|coste|cotizaci[oó]n/u', $value)) return 'Presupuesto';
    if (preg_match('/boda|seating|invitaci[oó]n|marcasitios|novios/u', $value)) return 'Bodas';
    if (preg_match('/3d|prototipo|l[aá]ser|laser|corte a medida/u', $value)) return 'Láser/3D (próximamente)';
    if (preg_match('/dtf|botella|cer[aá]mica|vidrio|cristal/u', $value)) return 'UV DTF';
    if (preg_match('/relieve|textura|cuadro/u', $value)) return 'Relieve 3D';
    if (preg_match('/\buv\b|grabado|madera|acrílico|acrilico|cuero|foto|imagen/u', $value)) return 'Impresión UV';
    if (preg_match('/logo|archivo|svg|pdf|ai|eps/u', $value)) return 'Archivos';
    if (($state['use'] ?? '') === 'corporativo') return 'Corporativo';
    return 'General';
}

function is_greeting_only(string $message): bool {
    $value = trim(u_strtolower($message));
    return (bool)preg_match('/^(hola|buenas|buenos d[ií]as|buenas tardes|buenas noches|hey|hello)[!\.\s]*$/u', $value);
}

function is_thanks_only(string $message): bool {
    $value = trim(u_strtolower($message));
    return (bool)preg_match('/^(gracias|muchas gracias|ok gracias|vale gracias|perfecto gracias|genial gracias)[!\.\s]*$/u', $value);
}

function is_short_context_reply(string $message): bool {
    $value = trim(u_strtolower($message));
    return u_strlen($value) <= 26;
}

function next_missing_question(array $state): string {
    if (($state['name'] ?? '') === '') return '¿Cómo te llamas?';
    if (($state['object'] ?? '') === '') return '¿Qué objeto concreto quieres personalizar?';
    if (empty($state['materials'])) return '¿Qué material prefieres o qué superficie quieres trabajar?';
    if (($state['quantity'] ?? '') === '') return '¿Sería una unidad o varias?';
    if (($state['measures'] ?? '') === '') return '¿Qué tamaño aproximado tendría?';
    if (($state['file'] ?? '') === '') return '¿Tienes ya la imagen, logo o archivo que quieres usar?';
    if (($state['timing'] ?? '') === '') return '¿Tienes alguna fecha objetivo de entrega?';
    if (($state['contact'] ?? '') === '') {
        $pref = (string)($state['contact_preference'] ?? '');
        if ($pref === 'email') return 'Perfecto, ¿me indicas tu email para que el equipo pueda responderte?';
        if ($pref === 'WhatsApp/teléfono') return 'Perfecto, ¿me indicas tu número de móvil o WhatsApp para que el equipo pueda contactarte?';
        return '¿Prefieres que el equipo te contacte por email o por WhatsApp?';
    }
    return 'Con esto ya puedo derivar automáticamente la conversación al equipo de Ligna Milano para revisión y próximos pasos.';
}

function local_reply(string $message, string $action = 'message', array $history = []): array {
    $state = extract_state($history, $message);
    $category = category_from_state($state, $message);
    $summary = summarize_state($state);
    $name = ($state['name'] ?? '') !== '' ? ', ' . $state['name'] : '';

    if ($action === 'finalize') {
        return [
            'reply' => 'Listo' . $name . ', he derivado la conversación completa por email al equipo de Ligna Milano. Revisarán el caso y responderán con solución, presupuesto o próximos pasos según corresponda.',
            'lead_ready' => true,
            'lead_summary' => $summary,
            'escalate' => true,
            'category' => 'Derivación humana'
        ];
    }

    if ($action === 'handoff' || preg_match('/whatsapp|persona|humano|agente|hablar con/u', u_strtolower($message))) {
        return [
            'reply' => 'Perfecto' . $name . '. He preparado la derivación con el contexto completo para que el equipo no te haga repetir el briefing. Puedes continuar por WhatsApp y la consulta queda registrada.',
            'lead_ready' => true,
            'lead_summary' => $summary,
            'escalate' => true,
            'category' => 'Derivación humana'
        ];
    }

    if (is_greeting_only($message)) {
        return [
            'reply' => 'Hola, encantado. Soy el asistente de Ligna Milano. ¿Cómo te llamas y qué te gustaría personalizar?',
            'lead_ready' => false,
            'lead_summary' => '',
            'escalate' => false,
            'category' => 'General'
        ];
    }

    if (is_thanks_only($message)) {
        if (($state['object'] ?? '') !== '' || !empty($state['materials'])) {
            $question = next_missing_question($state);
            return [
                'reply' => 'De nada' . $name . '. Tengo apuntado esto: ' . $summary . '. Para avanzar sin perder contexto: ' . $question,
                'lead_ready' => (($state['contact'] ?? '') !== ''),
                'lead_summary' => $summary,
                'escalate' => false,
                'category' => $category
            ];
        }
        return [
            'reply' => 'De nada. Cuando quieras, dime qué objeto te gustaría personalizar y te ayudo a cerrar el briefing.',
            'lead_ready' => false,
            'lead_summary' => '',
            'escalate' => false,
            'category' => 'General'
        ];
    }

    $process = (string)($state['process'] ?? '');
    $hasProject = (($state['object'] ?? '') !== '' || !empty($state['materials']) || ($state['use'] ?? '') !== '');
    $question = next_missing_question($state);

    if ($hasProject) {
        $prefix = 'Perfecto' . $name . '. ';
        if ($process !== '') {
            $prefix .= 'Por lo que cuentas, lo más probable es trabajar con ' . $process . '. ';
        } else {
            $prefix .= 'Ya tengo contexto del proyecto. ';
        }
        $prefix .= 'Resumen: ' . $summary . '. ';
        return [
            'reply' => $prefix . $question,
            'lead_ready' => (($state['contact'] ?? '') !== '' && ($state['object'] ?? '') !== ''),
            'lead_summary' => $summary,
            'escalate' => false,
            'category' => $category
        ];
    }

    if (is_short_context_reply($message) && (($state['use'] ?? '') !== '')) {
        return [
            'reply' => 'Entendido, sería para ' . $state['use'] . '. Ahora necesito aterrizar el soporte: ¿qué objeto concreto quieres personalizar?',
            'lead_ready' => false,
            'lead_summary' => $summary,
            'escalate' => false,
            'category' => $category
        ];
    }

    return [
        'reply' => 'Entiendo. Para ayudarte con criterio necesito cerrar una primera decisión: ¿qué objeto quieres personalizar?',
        'lead_ready' => false,
        'lead_summary' => '',
        'escalate' => false,
        'category' => 'General'
    ];
}

function build_system_prompt(): string {
    return <<<'PROMPT'
Eres el asistente conversacional de Ligna Milano, marca registrada especializada en impresión UV premium: regalos personalizados, decoración con textura, detalles de boda, artículos corporativos, souvenirs y papelería de lujo. Ligna Milano no vende muebles.

Servicios actuales (toda la producción se realiza con impresión UV de alta definición):
1. Impresión UV directa a todo color sobre madera (contrachapado de abedul, chopo, nogal, roble, fresno, bambú, MDF premium, DM negro; grosores de 3, 4, 6 y 9 mm), cuero (vegetal, italiano, vaqueta natural, piel reciclada), acrílicos especiales (espejo, frosted, iridiscente, fluorescente, translúcido, negro brillo, blanco opal), vinilos (transparente, blanco mate, holográfico, efecto espejo, cepillado, texturizado), papeles premium (Fedrigoni, Favini, Gmund, Colorplan) y piedra natural.
2. Relieve y textura 3D táctil de hasta 5 mm: cuadros con textura de pincelada, fotos y retratos con relieve, letras táctiles.
3. Barniz selectivo y acabados combinando brillo y mate.
4. Transferencias UV DTF para vidrio, cerámica, botellas, metal y superficies curvas u objetos difíciles de imprimir en directo.

Líneas de producto: regalos personalizados, decoración, empresas (regalos corporativos, placas, welcome packs, trofeos, diplomas), bodas (seating plans, marcasitios, invitaciones, números de mesa, cajas para alianzas), Navidad, mascotas, turismo y souvenirs de Barcelona, y papelería premium (marcapáginas, agendas, cuadernos, organizadores).
Colecciones temáticas: medieval, templaria, vikinga, steampunk, floral, infantil, gamer, mascotas, Barcelona y vintage.

Servicios NO disponibles todavía (llegan próximamente): corte y grabado láser e impresión 3D. Si el cliente los pide, explícalo con naturalidad, ofrece la alternativa UV cuando exista (por ejemplo, impresión UV con relieve en lugar de grabado láser) y ofrece registrar su interés para avisarle cuando el servicio esté activo. Ese interés también es un lead válido.
Nota operativa: trabajamos sobre bases y siluetas seleccionadas en cada material; los cortes totalmente a medida se valoran caso a caso.

Objetivo: resolver dudas, calificar leads y construir un briefing útil sin sonar robótico.

Reglas críticas:
1. Lee todo el historial antes de responder. No preguntes de nuevo datos que el cliente ya dio.
2. Interpreta respuestas breves según el contexto. Ejemplo: si antes dijo "foto con relieve sobre madera de nogal" y luego responde "regalo", eso significa uso = regalo. No vuelvas a preguntar el objeto ni el uso.
3. Si el cliente dice "gracias", responde de forma natural, resume lo que ya sabes y propone el siguiente paso. No repitas la misma pregunta anterior salvo que sea imprescindible.
4. Haz una sola pregunta concreta por turno.
5. Mantén tono premium, humano, claro y profesional. Nada de menú automático.
6. Recomienda el proceso más probable cuando haya datos suficientes: impresión UV directa a color, relieve y textura 3D, barniz selectivo o transferencia UV DTF.
7. No inventes precios cerrados, plazos garantizados ni compatibilidades de material si faltan datos.
8. Briefing ideal: nombre, email/teléfono, objeto, uso, material, cantidad, medidas, técnica, fecha objetivo, estilo, presupuesto aproximado y archivo disponible.
9. Si preguntas cómo quiere ser contactado y el cliente responde solo "email", "correo", "WhatsApp" o "teléfono", no des por cerrado el contacto: pide el email concreto o el número de móvil/WhatsApp según corresponda.
10. No marques lead_ready como true hasta tener un dato de contacto real, email o teléfono, y una intención comercial mínima.
11. Cuando ya tengas contacto real y briefing suficiente, confirma que la conversación se derivará automáticamente al equipo por email. No dependas de que el cliente pulse botones.
12. Cuando pidas email o teléfono, indica de forma natural que se usará solo para responder la consulta o preparar el presupuesto. No conviertas la respuesta en texto legal pesado.
13. Si el cliente pide humano, WhatsApp, presupuesto formal, revisión de archivo o decisión técnica compleja, marca escalate como true.
14. Si ya hay intención comercial clara, escribe lead_summary ejecutivo.

Datos de contacto:
Web: lignamilano.com
Instagram y TikTok: @lignamilano
WhatsApp: +34 615 30 43 50
Email: info@lignamilano.com

Devuelve siempre JSON válido, sin markdown y sin texto fuera del JSON, con esta estructura exacta:
{
  "reply": "respuesta al usuario en español",
  "lead_ready": true o false,
  "lead_summary": "resumen ejecutivo del pedido si hay intención comercial clara; si no, cadena vacía",
  "escalate": true o false,
  "category": "General | Presupuesto | Impresión UV | Relieve 3D | UV DTF | Bodas | Corporativo | Archivos | Láser/3D (próximamente) | Derivación humana"
}
PROMPT;
}

function parse_json_text(string $content): array {
    $content = trim($content);
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
    $content = preg_replace('/\s*```$/', '', $content) ?? $content;
    $parsed = json_decode($content, true);
    if (is_array($parsed)) return $parsed;
    if (preg_match('/\{.*\}/s', $content, $m)) {
        $parsed = json_decode((string)$m[0], true);
        if (is_array($parsed)) return $parsed;
    }
    throw new RuntimeException('La IA no devolvió JSON válido.');
}

function normalize_ai_result(array $parsed): array {
    return [
        'reply' => clean_text((string)($parsed['reply'] ?? ''), 1600),
        'lead_ready' => (bool)($parsed['lead_ready'] ?? false),
        'lead_summary' => clean_text((string)($parsed['lead_summary'] ?? ''), 2600),
        'escalate' => (bool)($parsed['escalate'] ?? false),
        'category' => one_line((string)($parsed['category'] ?? 'General'), 80)
    ];
}

function call_openai_chat(array $messages, string $apiKey, string $model): array {
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.35,
        'max_tokens' => 520,
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        throw new RuntimeException('Error cURL OpenAI: ' . $error);
    }

    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($data) ? (string)($data['error']['message'] ?? 'Error API OpenAI') : 'Error API OpenAI';
        throw new RuntimeException('HTTP ' . $status . ': ' . $msg);
    }

    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    return normalize_ai_result(parse_json_text($content));
}

function log_openai_error(string $message): void {
    $dir = storage_dir();
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . '/openai-error.log', $line, FILE_APPEND);
    error_log('Ligna chatbot OpenAI: ' . $message);
}

function storage_dir(): string {
    $dir = __DIR__ . '/storage/chat-leads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function session_file(string $sessionId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId) ?: 'anon';
    return storage_dir() . '/' . $safe . '.json';
}

function marker_data(string $sessionId): array {
    $file = session_file($sessionId);
    if (!is_file($file)) return [];
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_send_marker(string $sessionId, string $action): void {
    $file = session_file($sessionId);
    $data = marker_data($sessionId);
    $data['last_final_sent'] = time();
    $data['last_action'] = $action;
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function can_send_final(string $sessionId): bool {
    $data = marker_data($sessionId);
    $last = (int)($data['last_final_sent'] ?? 0);
    return $last === 0 || (time() - $last) > 600;
}

function build_transcript(array $history, string $message): array {
    $transcript = [];
    foreach ($history as $item) {
        $role = ($item['role'] ?? '') === 'user' ? 'Cliente' : 'Asistente';
        $content = clean_text((string)($item['content'] ?? ''), 1800);
        if ($content !== '') {
            $transcript[] = $role . ': ' . $content;
        }
    }
    if ($message !== '' && !last_history_equals_message($history, $message)) {
        $transcript[] = 'Cliente: ' . $message;
    }
    return $transcript;
}

function has_commercial_context(array $state, string $text): bool {
    if (($state['object'] ?? '') !== '') return true;
    if (!empty($state['materials'])) return true;
    if (($state['use'] ?? '') !== '') return true;
    if (($state['file'] ?? '') !== '') return true;
    if (($state['process'] ?? '') !== '') return true;
    return (bool)preg_match('/personalizar|presupuesto|precio|cotizaci[oó]n|regalo|corporativo|boda|relieve|textura|uv|dtf|impresi[oó]n|colecci[oó]n|producto|proyecto/u', u_strtolower($text));
}

function should_auto_send_lead(array $state, array $result, array $history, string $message): bool {
    if (($state['contact'] ?? '') === '') return false;
    $text = all_conversation_text($history, $message);
    if (!has_commercial_context($state, $text)) return false;
    if (!empty($result['lead_ready']) || !empty($result['escalate'])) return true;
    return (($state['object'] ?? '') !== '' || !empty($state['materials']) || ($state['use'] ?? '') !== '');
}

function contact_channel_label(array $state): string {
    $contact = (string)($state['contact'] ?? '');
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) return 'email';
    $pref = (string)($state['contact_preference'] ?? '');
    if ($pref !== '') return $pref;
    return 'el dato de contacto indicado';
}

function append_auto_sent_notice(string $reply, array $state, bool $alreadySent = false): string {
    $name = ($state['name'] ?? '') !== '' ? ', ' . $state['name'] : '';
    $channel = contact_channel_label($state);
    if ($alreadySent) {
        return 'Perfecto' . $name . '. La conversación ya está registrada en el equipo de Ligna Milano. Te responderán por ' . $channel . ' con la solución, presupuesto o próximos pasos.';
    }
    return 'Perfecto' . $name . '. Ya tengo el contacto y he derivado automáticamente la conversación completa al equipo de Ligna Milano. Te responderán por ' . $channel . ' con la solución, presupuesto o próximos pasos. Gracias por compartir el briefing.';
}

function send_lead_email(array $result, array $history, string $message, string $sessionId, string $page, string $action): bool {
    $to = env_or_const('LIGNA_TO_EMAIL', 'info@lignamilano.com');
    $from = env_or_const('LIGNA_FROM_EMAIL', 'webform@lignamilano.com');
    $fromName = env_or_const('LIGNA_FROM_NAME', 'Desde la web');

    if ($action === 'handoff') {
        $subjectPrefix = 'Derivación WhatsApp';
    } elseif ($action === 'auto') {
        $subjectPrefix = 'Lead chatbot automático';
    } else {
        $subjectPrefix = 'Conversación chatbot finalizada';
    }
    $subject = $subjectPrefix . ' · ' . (($result['category'] ?? '') ?: 'Consulta');
    $transcript = build_transcript($history, $message);

    $body = implode("\n", [
        'Conversación completa recibida desde el chatbot de lignamilano.com',
        '',
        'Categoría: ' . (($result['category'] ?? '') ?: 'General'),
        'Acción: ' . $action,
        'Derivación humana: ' . (!empty($result['escalate']) ? 'Sí' : 'No'),
        'Session ID: ' . $sessionId,
        'Página: ' . ($page !== '' ? $page : 'No indicada'),
        '',
        'Resumen ejecutivo:',
        (($result['lead_summary'] ?? '') !== '' ? (string)$result['lead_summary'] : 'El cliente finalizó o derivó una conversación desde el chatbot web.'),
        '',
        'Última respuesta del asistente:',
        (string)($result['reply'] ?? ''),
        '',
        'Transcripción completa:',
        implode("\n", $transcript),
        '',
        'Origen: chatbot IA Ligna Milano',
        'Remitente técnico: ' . $fromName . ' <' . $from . '>'
    ]);

    $contact = extract_contact(client_text($history, $message));
    $replyTo = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : $from;
    return ligna_send_email(
        $to,
        $subject,
        $body,
        $replyTo,
        $replyTo !== $from ? 'Cliente web' : $fromName
    );
}

$rawInput = file_get_contents('php://input') ?: '';
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    json_out(['ok' => false, 'message' => 'JSON no válido.'], 400);
}

$action = one_line((string)($input['action'] ?? 'message'), 40);
$message = clean_text((string)($input['message'] ?? ''), 1600);
$sessionId = one_line((string)($input['sessionId'] ?? ('lm-' . bin2hex(random_bytes(4)))), 90);
$page = one_line((string)($input['page'] ?? ''), 400);
$history = normalize_history(is_array($input['history'] ?? null) ? $input['history'] : []);

$allowedActions = ['message', 'finalize', 'handoff'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'message';
}

if ($message === '' && $action === 'message') {
    json_out(['ok' => false, 'message' => 'Mensaje vacío.'], 422);
}

$apiKey = trim(env_or_const('OPENAI_API_KEY', ''));
$model = trim(env_or_const('OPENAI_MODEL', 'gpt-4.1-mini')) ?: 'gpt-4.1-mini';
$debug = bool_env_or_const('LIGNA_DEBUG', false);
$result = null;
$mode = 'local';
$openAiError = '';
$state = extract_state($history, $message);

if ($apiKey !== '') {
    $modelCandidates = array_values(array_unique(array_filter([$model, 'gpt-4o-mini'])));
    $baseMessages = array_merge(
        [
            ['role' => 'system', 'content' => build_system_prompt()],
            ['role' => 'system', 'content' => 'Estado del briefing detectado por el servidor. Úsalo para no repetir preguntas: ' . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        ],
        $history
    );
    if ($message !== '' && !last_history_equals_message($history, $message)) {
        $baseMessages[] = ['role' => 'user', 'content' => $message];
    }
    if ($action === 'finalize') {
        $baseMessages[] = ['role' => 'user', 'content' => 'El cliente finaliza la conversación. Genera resumen ejecutivo breve y confirma que se derivará por email al equipo.'];
    } elseif ($action === 'handoff') {
        $baseMessages[] = ['role' => 'user', 'content' => 'El cliente solicita derivación humana por WhatsApp. Genera resumen ejecutivo breve y confirma que se enviará el contexto al equipo.'];
    }

    foreach ($modelCandidates as $candidate) {
        try {
            $result = call_openai_chat($baseMessages, $apiKey, $candidate);
            $mode = 'openai:' . $candidate;
            break;
        } catch (Throwable $e) {
            $openAiError = $e->getMessage();
            log_openai_error('Modelo ' . $candidate . ' falló. ' . $openAiError);
        }
    }
}

if (!is_array($result) || (($result['reply'] ?? '') === '')) {
    $result = local_reply($message, $action, $history);
    $mode = $apiKey === '' ? 'local:no-api-key' : 'local:openai-error';
}

$emailSent = false;
$emailAlreadySent = false;
$emailAttempted = false;
$autoEmailSent = false;
$autoEmailAlreadySent = false;

if ($action === 'finalize' || $action === 'handoff') {
    $emailAttempted = true;
    if (can_send_final($sessionId)) {
        $emailSent = send_lead_email($result, $history, $message, $sessionId, $page, $action);
        if ($emailSent) {
            save_send_marker($sessionId, $action);
        }
    } else {
        $emailAlreadySent = true;
    }

    $name = ($state['name'] ?? '') !== '' ? ', ' . $state['name'] : '';
    if ($emailSent || $emailAlreadySent) {
        if ($action === 'handoff') {
            $result['reply'] = $emailAlreadySent
                ? 'Ya había enviado esta conversación al equipo. Ahora puedes continuar por WhatsApp con el resumen incluido.'
                : 'Perfecto' . $name . ', he enviado la conversación completa al equipo de Ligna Milano y ahora puedes continuar por WhatsApp sin repetir el briefing.';
        } else {
            $result['reply'] = $emailAlreadySent
                ? 'Esta conversación ya había sido derivada al equipo. La tenemos registrada para su revisión.'
                : 'Listo' . $name . ', he derivado la conversación completa por email al equipo de Ligna Milano. Te responderán con una solución, presupuesto o próximos pasos según corresponda.';
        }
    } else {
        $result['reply'] = 'Tengo el briefing preparado, pero no pude confirmar el envío por email desde el servidor. Puedes continuar por WhatsApp para que el equipo lo reciba con el contexto incluido.';
    }
} elseif ($action === 'message' && should_auto_send_lead($state, $result, $history, $message)) {
    $emailAttempted = true;
    if (can_send_final($sessionId)) {
        $emailSent = send_lead_email($result, $history, $message, $sessionId, $page, 'auto');
        $autoEmailSent = $emailSent;
        if ($emailSent) {
            save_send_marker($sessionId, 'auto');
            $result['reply'] = append_auto_sent_notice((string)($result['reply'] ?? ''), $state, false);
            $result['lead_ready'] = true;
            $result['escalate'] = true;
        }
    } else {
        $emailAlreadySent = true;
        $autoEmailAlreadySent = true;
        $result['reply'] = append_auto_sent_notice((string)($result['reply'] ?? ''), $state, true);
        $result['lead_ready'] = true;
    }

    if (!$emailSent && !$emailAlreadySent) {
        $result['reply'] = 'Ya tengo tus datos y el briefing, pero no pude confirmar el envío automático al equipo desde el servidor. Para evitar que se pierda, puedes continuar por WhatsApp con el resumen incluido.';
    }
}

$response = [
    'ok' => true,
    'reply' => $result['reply'],
    'leadReady' => (bool)$result['lead_ready'],
    'leadSummary' => $result['lead_summary'],
    'escalate' => (bool)$result['escalate'],
    'category' => $result['category'],
    'emailSent' => $emailSent,
    'emailAlreadySent' => $emailAlreadySent,
    'emailAttempted' => $emailAttempted,
    'autoEmailSent' => $autoEmailSent,
    'autoEmailAlreadySent' => $autoEmailAlreadySent,
    'needsConfig' => $apiKey === '',
    'mode' => $mode
];

if ($debug && $openAiError !== '') {
    $response['debugReason'] = $openAiError;
}

json_out($response);

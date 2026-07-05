<?php
declare(strict_types=1);

/**
 * SMTP mailer ligero para Ligna Milano.
 * Usa SMTP autenticado si está configurado en config.local.php.
 * Si no hay SMTP, hace fallback a mail().
 */

function ligna_env_or_const(string $name, string $default = ''): string {
    if (defined($name)) {
        return (string)constant($name);
    }
    $value = getenv($name);
    return $value !== false ? (string)$value : $default;
}

function ligna_has_smtp_config(): bool {
    return ligna_env_or_const('LIGNA_SMTP_HOST') !== ''
        && ligna_env_or_const('LIGNA_SMTP_USER') !== ''
        && ligna_env_or_const('LIGNA_SMTP_PASSWORD') !== '';
}

function ligna_header_safe(string $value, int $maxLength = 240): string {
    $value = trim($value);
    $value = str_replace(["\r", "\n", "\0"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function ligna_mailbox(string $email, string $name = ''): string {
    $email = ligna_header_safe($email, 254);
    $name = ligna_header_safe($name, 160);
    if ($name === '') {
        return $email;
    }
    $encodedName = '=?UTF-8?B?' . base64_encode($name) . '?=';
    return $encodedName . ' <' . $email . '>';
}

function ligna_encode_subject(string $subject): string {
    return '=?UTF-8?B?' . base64_encode(ligna_header_safe($subject, 220)) . '?=';
}

function ligna_smtp_read($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function ligna_smtp_expect($socket, array $codes, string $context): string {
    $response = ligna_smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException($context . ' SMTP falló. Respuesta: ' . trim($response));
    }
    return $response;
}

function ligna_smtp_command($socket, string $command, array $codes, string $context): string {
    fwrite($socket, $command . "\r\n");
    return ligna_smtp_expect($socket, $codes, $context);
}

function ligna_dot_stuff(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }
    unset($line);
    return implode("\r\n", $lines);
}

function ligna_send_via_smtp(string $to, string $subject, string $body, string $replyToEmail = '', string $replyToName = ''): bool {
    $host = ligna_env_or_const('LIGNA_SMTP_HOST');
    $port = (int)ligna_env_or_const('LIGNA_SMTP_PORT', '465');
    $secure = strtolower(ligna_env_or_const('LIGNA_SMTP_SECURE', 'ssl'));
    $username = ligna_env_or_const('LIGNA_SMTP_USER');
    $password = ligna_env_or_const('LIGNA_SMTP_PASSWORD');
    $fromEmail = ligna_env_or_const('LIGNA_FROM_EMAIL', $username);
    $fromName = ligna_env_or_const('LIGNA_FROM_NAME', 'Ligna Milano');

    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email de origen o destino no válido.');
    }

    if ($replyToEmail !== '' && !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $replyToEmail = '';
        $replyToName = '';
    }

    $remote = ($secure === 'ssl' || $secure === 'tls' || $port === 465)
        ? 'ssl://' . $host . ':' . $port
        : 'tcp://' . $host . ':' . $port;

    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('No se pudo conectar al SMTP: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, 25);

    try {
        ligna_smtp_expect($socket, [220], 'Conexión');
        ligna_smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'lignamilano.com'), [250], 'EHLO');

        if ($secure === 'starttls' || $secure === 'tls-starttls') {
            ligna_smtp_command($socket, 'STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo activar TLS.');
            }
            ligna_smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'lignamilano.com'), [250], 'EHLO TLS');
        }

        ligna_smtp_command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        ligna_smtp_command($socket, base64_encode($username), [334], 'Usuario SMTP');
        ligna_smtp_command($socket, base64_encode($password), [235], 'Contraseña SMTP');
        ligna_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
        ligna_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        ligna_smtp_command($socket, 'DATA', [354], 'DATA');

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . ligna_mailbox($fromEmail, $fromName),
            'To: ' . $to,
            'Subject: ' . ligna_encode_subject($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@lignamilano.com>',
            'X-Mailer: Ligna Milano SMTP'
        ];

        if ($replyToEmail !== '') {
            $headers[] = 'Reply-To: ' . ligna_mailbox($replyToEmail, $replyToName);
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . ligna_dot_stuff($body) . "\r\n.";
        fwrite($socket, $message . "\r\n");
        ligna_smtp_expect($socket, [250], 'Envío del mensaje');
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        return true;
    } catch (Throwable $e) {
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        error_log('Ligna SMTP error: ' . $e->getMessage());
        return false;
    }
}

function ligna_send_email(string $to, string $subject, string $body, string $replyToEmail = '', string $replyToName = ''): bool {
    $to = ligna_header_safe($to, 254);
    $fromEmail = ligna_env_or_const('LIGNA_FROM_EMAIL', 'webform@lignamilano.com');
    $fromName = ligna_env_or_const('LIGNA_FROM_NAME', 'Desde la web');

    if (ligna_has_smtp_config()) {
        return ligna_send_via_smtp($to, $subject, $body, $replyToEmail, $replyToName);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . ligna_mailbox($fromEmail, $fromName),
        'X-Mailer: PHP/' . phpversion()
    ];

    if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . ligna_mailbox($replyToEmail, $replyToName);
    }

    return @mail($to, ligna_encode_subject($subject), $body, implode("\r\n", $headers));
}
?>

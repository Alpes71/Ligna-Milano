<?php
declare(strict_types=1);

/* ============================================
   Ligna Milano — Descarga protegida de encuestas
   La contraseña NO está en texto plano: solo su hash.
   Escribe tu contraseña para descargar el CSV acumulado.
   ============================================ */

// Hash bcrypt de la contraseña (no reversible). Generado para Ligna Milano.
$HASH = '$2y$10$AVi7r4WI3HnThaNF7ys0RemZrpESfQk6nXP/6KaUdDddiUuTZCPC.';

// Permite sobreescribir el hash desde config.local.php si algún día se quiere
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) { require_once $localConfig; }
if (defined('LIGNA_SURVEY_HASH') && LIGNA_SURVEY_HASH !== '') {
    $HASH = (string)LIGNA_SURVEY_HASH;
}

$csv = __DIR__ . '/storage/encuestas/encuestas.csv';

/* ---------- Descarga (POST con contraseña correcta) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['clave'] ?? '');
    // pequeña pausa para frenar fuerza bruta
    usleep(400000);
    if ($pass === '' || !password_verify($pass, $HASH)) {
        $error = 'Contraseña incorrecta.';
    } elseif (!is_file($csv)) {
        $error = 'Todavía no hay ninguna encuesta registrada.';
    } else {
        $nombre = 'encuestas-ligna-milano-' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($csv));
        header('X-Content-Type-Options: nosniff');
        readfile($csv);
        exit;
    }
}

$totalTexto = '';
if (is_file($csv)) {
    $lineas = count(file($csv, FILE_SKIP_EMPTY_LINES)) - 1; // menos la cabecera
    if ($lineas < 0) $lineas = 0;
    $totalTexto = $lineas . ' respuesta' . ($lineas === 1 ? '' : 's') . ' registrada' . ($lineas === 1 ? '' : 's') . '.';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Descargar encuestas | Ligna Milano</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#080807">
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--copper:#b87333;--cream:#f7f0e6;--cream-soft:#e7d8c4;--carbon:#151311;--line:rgba(184,115,51,.26);--muted:#9b8874}
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
      background:radial-gradient(130% 90% at 50% -10%,#1a1512 0%,#0d0b09 55%,#080807 100%);
      color:var(--cream);font-family:"Montserrat",system-ui,sans-serif}
    .card{max-width:420px;width:100%;padding:36px;border:1px solid var(--line);border-radius:22px;background:var(--carbon)}
    .kicker{font-size:11px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--copper)}
    h1{font-family:"Cormorant Garamond",Georgia,serif;font-weight:600;font-size:30px;margin:8px 0 6px}
    p{color:var(--cream-soft);font-size:14px;line-height:1.6;margin:0 0 20px}
    .total{color:var(--muted);font-size:13px;margin-bottom:20px}
    label{display:block;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
    input{width:100%;padding:13px 15px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--cream);font-size:15px;font-family:inherit}
    input:focus-visible{outline:none;border-color:var(--copper)}
    button{width:100%;margin-top:18px;padding:14px;border:1px solid var(--copper);border-radius:999px;background:var(--copper);color:#120904;font-weight:700;font-size:15px;font-family:inherit;cursor:pointer;transition:.2s}
    button:hover{filter:brightness(1.08)}
    .err{color:#e08a5a;font-size:13.5px;margin-top:14px}
  </style>
</head>
<body>
  <div class="card">
    <span class="kicker">Ligna Milano</span>
    <h1>Descargar encuestas</h1>
    <p>Introduce tu contraseña para descargar todas las respuestas acumuladas en un archivo CSV (se abre en Excel).</p>
    <?php if ($totalTexto !== ''): ?><div class="total"><?php echo htmlspecialchars($totalTexto, ENT_QUOTES); ?></div><?php endif; ?>
    <form method="POST" autocomplete="off">
      <label for="clave">Contraseña</label>
      <input id="clave" type="password" name="clave" required autofocus>
      <button type="submit">Descargar CSV</button>
      <?php if (!empty($error)): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>
    </form>
  </div>
</body>
</html>

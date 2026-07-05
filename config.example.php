<?php
// Copia este archivo como config.local.php y completa los datos reales.
// No subas config.local.php a repositorios públicos.

// Chatbot IA.
define('OPENAI_API_KEY', ''); // Ejemplo: sk-proj-...
define('OPENAI_MODEL', 'gpt-4.1-mini');
define('LIGNA_DEBUG', false);

// Correos operativos.
define('LIGNA_TO_EMAIL', 'info@lignamilano.com');
define('LIGNA_FROM_EMAIL', 'webform@lignamilano.com');
define('LIGNA_FROM_NAME', 'Desde la web');

// SMTP autenticado.
define('LIGNA_SMTP_HOST', 'smtp.ionos.es');
define('LIGNA_SMTP_PORT', '465');
define('LIGNA_SMTP_SECURE', 'ssl'); // ssl, starttls o vacío.
define('LIGNA_SMTP_USER', 'webform@lignamilano.com');
define('LIGNA_SMTP_PASSWORD', 'PEGAR_AQUI_LA_CONTRASEÑA');
?>

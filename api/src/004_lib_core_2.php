function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* SA1 v21 hardening: strip CR/LF/NUL from any value that lands in an SMTP header
   (defense in depth against email header injection via user-controlled fields). */

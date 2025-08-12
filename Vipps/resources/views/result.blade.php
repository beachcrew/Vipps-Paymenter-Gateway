<!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8">
  <title>Vipps</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 2rem; }
    .box { max-width: 560px; margin: auto; padding: 1.5rem; border: 1px solid #eee; border-radius: 12px; text-align:center;}
    .ok { color: #0a8; }
    .bad { color: #c00; }
  </style>
</head>
<body>
  <div class="box">
    @if($ok)
      <h1 class="ok">✅ Betaling fullført</h1>
      <p>Takk! Vi har registrert betalingen.</p>
    @else
      <h1 class="bad">⚠️ Betaling ikke fullført</h1>
      <p>{{ $message ?? 'Noe gikk galt.' }}</p>
    @endif
    <p><a href="/client/invoices">Tilbake til fakturaer</a></p>
  </div>
</body>
</html>

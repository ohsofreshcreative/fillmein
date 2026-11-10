{{-- Wiadomość e-mail z zaproszeniem (HTML, style inline — klienty poczty nie obsługują CSS z plików). --}}
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Zaproszenie do Fill Me In</title></head>
<body style="margin:0;padding:24px;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1d2229;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e6e8ec;border-radius:16px;">
    <tr><td style="padding:32px;">
      <p style="margin:0 0 24px;font-size:16px;font-weight:600;">Fill Me In</p>
      <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;">Cześć{{ $user->first_name ? ' ' . $user->first_name : '' }}!</h1>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#4a5260;">
        {{ \App\Support\Staff::fullName($inviter) }} zaprasza Cię do Fill Me In — aplikacji OhSoFresh Creative do zbierania treści od klientów.
        Twój login to <strong>{{ $user->user_login }}</strong> (możesz też logować się adresem e-mail).
      </p>
      <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#4a5260;">Aby rozpocząć, ustaw hasło:</p>
      <p style="margin:0 0 24px;">
        <a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:#1d2229;color:#ffffff;text-decoration:none;border-radius:10px;font-size:15px;font-weight:600;">Ustaw hasło</a>
      </p>
      <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#6b7380;">
        Link jest ważny {{ $expirationDays }} dni i działa jednorazowo. Jeśli przycisk nie działa, skopiuj adres do przeglądarki:
      </p>
      <p style="margin:0 0 24px;font-size:13px;line-height:1.5;word-break:break-all;color:#6b7380;">{{ $url }}</p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7380;">Jeśli nie spodziewasz się tej wiadomości, zignoruj ją.</p>
    </td></tr>
  </table>
</body>
</html>

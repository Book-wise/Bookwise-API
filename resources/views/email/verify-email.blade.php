<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .body { padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .button { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .link { word-break: break-all; color: #6b7280; font-size: 13px; }
        .footer { text-align: center; padding: 15px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Verifica tu email</h1>
        </div>
        <div class="body">
            <p>Hola <strong>{{ $userName }}</strong>,</p>
            <p>Gracias por crear tu cuenta. Para activarla, verifica tu email haciendo clic en el siguiente botón:</p>
            <p style="text-align: center;">
                <a class="button" href="{{ $verifyUrl }}">Verificar email</a>
            </p>
            <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
            <p class="link">{{ $verifyUrl }}</p>
            <p>Este enlace expira en 48 horas.</p>
        </div>
        <div class="footer">
            <p>Si no creaste esta cuenta, ignora este correo.</p>
        </div>
    </div>
</body>
</html>

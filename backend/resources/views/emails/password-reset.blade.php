<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $locale === 'en' ? 'Reset your LHMS password' : 'Réinitialisation de votre mot de passe LHMS' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;">
        <tr>
            <td style="padding:28px 28px 12px;background:#0f3d2e;color:#ffffff;">
                <div style="font-size:12px;letter-spacing:.28em;opacity:.7;">LALI HOLDINGS</div>
                <h1 style="margin:12px 0 0;font-size:24px;">LHMS</h1>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                @if ($locale === 'en')
                    <p style="margin:0 0 16px;">Hello {{ $user->name }},</p>
                    <p style="margin:0 0 16px;">We received a request to reset your LHMS password. Click the button below to choose a new password.</p>
                    <p style="margin:0 0 20px;color:#6b7280;font-size:14px;">This link expires in {{ $expiresMinutes }} minutes. If you did not request this, you can ignore this email.</p>
                @else
                    <p style="margin:0 0 16px;">Bonjour {{ $user->name }},</p>
                    <p style="margin:0 0 16px;">Nous avons reçu une demande de réinitialisation de votre mot de passe LHMS. Cliquez sur le bouton ci-dessous pour en choisir un nouveau.</p>
                    <p style="margin:0 0 20px;color:#6b7280;font-size:14px;">Ce lien expire dans {{ $expiresMinutes }} minutes. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.</p>
                @endif

                <p style="margin:0;">
                    <a href="{{ $resetUrl }}" style="display:inline-block;background:#0f3d2e;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;">
                        {{ $locale === 'en' ? 'Reset password' : 'Réinitialiser le mot de passe' }}
                    </a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>

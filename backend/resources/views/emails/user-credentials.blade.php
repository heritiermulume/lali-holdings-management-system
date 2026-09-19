<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $locale === 'en' ? 'Your LHMS login credentials' : 'Vos identifiants de connexion LHMS' }}</title>
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
                    <p style="margin:0 0 16px;">An agent account has been created for you on LHMS. You can sign in with your email or phone number and the password below.</p>
                @else
                    <p style="margin:0 0 16px;">Bonjour {{ $user->name }},</p>
                    <p style="margin:0 0 16px;">Un compte agent a été créé pour vous sur LHMS. Vous pouvez vous connecter avec votre e-mail ou votre numéro de téléphone et le mot de passe ci-dessous.</p>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;border-radius:12px;">
                    <tr>
                        <td style="padding:16px 18px;">
                            <div style="font-size:12px;color:#6b7280;">{{ $locale === 'en' ? 'Email' : 'E-mail' }}</div>
                            <div style="font-size:16px;font-weight:700;margin-top:4px;">{{ $user->email }}</div>
                        </td>
                    </tr>
                    @if ($user->phone)
                        <tr>
                            <td style="padding:0 18px 16px;">
                                <div style="font-size:12px;color:#6b7280;">{{ $locale === 'en' ? 'Phone' : 'Téléphone' }}</div>
                                <div style="font-size:16px;font-weight:700;margin-top:4px;">{{ $user->phone }}</div>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:0 18px 16px;">
                            <div style="font-size:12px;color:#6b7280;">{{ $locale === 'en' ? 'Password' : 'Mot de passe' }}</div>
                            <div style="font-size:16px;font-weight:700;margin-top:4px;font-family:Menlo,Consolas,monospace;">{{ $password }}</div>
                        </td>
                    </tr>
                </table>

                <p style="margin:20px 0 0;">
                    <a href="{{ $loginUrl }}" style="display:inline-block;background:#0f3d2e;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;">
                        {{ $locale === 'en' ? 'Sign in to LHMS' : 'Se connecter à LHMS' }}
                    </a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>

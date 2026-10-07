<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $locale === 'en' ? 'Finance request decision' : 'Décision sur une demande financière' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    @php
        $approved = ($payload['decision'] ?? '') === 'approve';
        $type = $payload['type'] ?? '';
        $typeLabel = $locale === 'en'
            ? ($type === 'deposit' ? 'deposit' : 'withdrawal')
            : ($type === 'deposit' ? 'dépôt' : 'retrait');
        $amount = number_format(abs((float) ($payload['amount'] ?? 0)), 2, '.', ' ');
        $memberName = $payload['member_name'] ?? null;
        $notes = $payload['notes'] ?? null;
        $reason = $payload['reason'] ?? null;
    @endphp
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
                    <p style="margin:0 0 16px;">
                        Your {{ $typeLabel }} request
                        @if ($memberName) for <strong>{{ $memberName }}</strong> @endif
                        of <strong>{{ $amount }}</strong>
                        has been <strong>{{ $approved ? 'approved' : 'rejected' }}</strong>.
                    </p>
                @else
                    <p style="margin:0 0 16px;">Bonjour {{ $user->name }},</p>
                    <p style="margin:0 0 16px;">
                        Votre demande de {{ $typeLabel }}
                        @if ($memberName) pour <strong>{{ $memberName }}</strong> @endif
                        de <strong>{{ $amount }}</strong>
                        a été <strong>{{ $approved ? 'acceptée' : 'refusée' }}</strong>.
                    </p>
                @endif

                @if ($notes)
                    <p style="margin:0 0 12px;color:#6b7280;font-size:14px;">
                        {{ $locale === 'en' ? 'Request note' : 'Motif de la demande' }}: {{ $notes }}
                    </p>
                @endif

                @if (! $approved && $reason)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border-radius:12px;margin:0 0 16px;">
                        <tr>
                            <td style="padding:16px 18px;">
                                <div style="font-size:12px;color:#991b1b;">{{ $locale === 'en' ? 'Rejection reason' : 'Motif du refus' }}</div>
                                <div style="font-size:15px;font-weight:600;margin-top:4px;color:#7f1d1d;">{{ $reason }}</div>
                            </td>
                        </tr>
                    </table>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>

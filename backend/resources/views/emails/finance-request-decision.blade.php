@php
    $approved = ($payload['decision'] ?? '') === 'approve';
    $type = $payload['type'] ?? '';
    $typeLabel = match ($locale) {
        'en' => $type === 'deposit' ? 'deposit' : 'withdrawal',
        'sw' => $type === 'deposit' ? 'kuweka pesa' : 'kutoa pesa',
        default => $type === 'deposit' ? 'dépôt' : 'retrait',
    };
    $amount = number_format(abs((float) ($payload['amount'] ?? 0)), 2, '.', ' ');
    $memberName = $payload['member_name'] ?? null;
    $notes = $payload['notes'] ?? null;
    $reason = $payload['reason'] ?? null;
    $title = match ($locale) {
        'en' => $approved ? 'Your finance request was approved' : 'Your finance request was rejected',
        'sw' => $approved ? 'Ombi lako la fedha limekubaliwa' : 'Ombi lako la fedha limekataliwa',
        default => $approved ? 'Votre demande financière a été acceptée' : 'Votre demande financière a été refusée',
    };
    $hello = match ($locale) {
        'en' => 'Hello',
        'sw' => 'Habari',
        default => 'Bonjour',
    };
    $noteLabel = match ($locale) {
        'en' => 'Request note',
        'sw' => 'Maelezo ya ombi',
        default => 'Motif de la demande',
    };
    $reasonLabel = match ($locale) {
        'en' => 'Rejection reason',
        'sw' => 'Sababu ya kukataliwa',
        default => 'Motif du refus',
    };
    $preheader = $title;
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $hello }} {{ $user->name }},</p>
    <x-mail.panel :tone="$approved ? 'success' : 'danger'">
        @if ($locale === 'en')
            Your {{ $typeLabel }} request
            @if ($memberName) for <strong>{{ $memberName }}</strong> @endif
            of <strong>{{ $amount }}</strong>
            has been <strong>{{ $approved ? 'approved' : 'rejected' }}</strong>.
        @elseif ($locale === 'sw')
            Ombi lako la {{ $typeLabel }}
            @if ($memberName) kwa <strong>{{ $memberName }}</strong> @endif
            la <strong>{{ $amount }}</strong>
            limekuwa <strong>{{ $approved ? 'limekubaliwa' : 'limekataliwa' }}</strong>.
        @else
            Votre demande de {{ $typeLabel }}
            @if ($memberName) pour <strong>{{ $memberName }}</strong> @endif
            de <strong>{{ $amount }}</strong>
            a été <strong>{{ $approved ? 'acceptée' : 'refusée' }}</strong>.
        @endif
    </x-mail.panel>
    @if ($notes)
        <p style="margin:0 0 12px;color:#d5e8da;font-size:14px;">
            {{ $noteLabel }} : {{ $notes }}
        </p>
    @endif
    @if (! $approved && $reason)
        <p style="margin:0 0 12px;font-size:14px;">
            <span style="color:#f3c7c7;font-weight:700;">{{ $reasonLabel }}</span><br>
            {{ $reason }}
        </p>
    @endif
@endsection
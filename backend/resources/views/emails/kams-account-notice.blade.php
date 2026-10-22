@php
    $confirm = ($payload['purpose'] ?? '') === 'confirm';
    $movement = $payload['movement'] ?? 'deposit';
    $amount = number_format(abs((float) ($payload['amount'] ?? 0)), 2, '.', ' ');
    $currency = trim((string) ($payload['currency'] ?? ''));
    $amountLabel = trim($amount.($currency !== '' ? ' '.$currency : ''));
    $balance = array_key_exists('balance_after', $payload) && $payload['balance_after'] !== null
        ? number_format((float) $payload['balance_after'], 2, '.', ' ')
        : null;
    $balanceLabel = $balance !== null ? trim($balance.($currency !== '' ? ' '.$currency : '')) : null;
    $memberName = $payload['member_name'] ?? null;
    $office = $payload['office'] ?? null;
    $notes = $payload['notes'] ?? null;
    $wallet = $payload['wallet'] ?? null;
    $actionUrl = $payload['action_url'] ?? null;
    $typeLabel = match ($movement) {
        'withdrawal' => $locale === 'en' ? 'withdrawal' : 'retrait',
        'transfer' => $locale === 'en' ? 'transfer' : 'transfert',
        default => $locale === 'en' ? 'deposit' : 'dépôt',
    };
    $preheader = $title;
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $locale === 'en' ? 'Hello' : 'Bonjour' }} {{ $user->name }},</p>
    <x-mail.panel :tone="$confirm ? 'neutral' : 'success'">
        @if ($confirm)
            @if ($locale === 'en')
                A {{ $typeLabel }} of <strong>{{ $amountLabel }}</strong>
                @if ($memberName) for <strong>{{ $memberName }}</strong> @endif
                is waiting for your confirmation.
            @else
                @if ($movement === 'transfer')
                    Un transfert de <strong>{{ $amountLabel }}</strong> vers votre compte attend votre confirmation.
                @else
                    Une demande de {{ $typeLabel }} de <strong>{{ $amountLabel }}</strong>
                    @if ($memberName) pour <strong>{{ $memberName }}</strong> @endif
                    attend votre confirmation.
                @endif
            @endif
        @else
            @if ($locale === 'en')
                A {{ $typeLabel }} of <strong>{{ $amountLabel }}</strong> was recorded on your account.
                @if ($balanceLabel !== null)
                    Your balance is now <strong>{{ $balanceLabel }}</strong>.
                @endif
            @else
                Un {{ $typeLabel }} de <strong>{{ $amountLabel }}</strong> a été enregistré sur votre compte.
                @if ($balanceLabel !== null)
                    Votre solde est maintenant de <strong>{{ $balanceLabel }}</strong>.
                @endif
            @endif
        @endif
    </x-mail.panel>
    @if ($office)
        <p style="margin:0 0 8px;color:#d5e8da;font-size:14px;">{{ $locale === 'en' ? 'Office' : 'Bureau' }} : {{ $office }}</p>
    @endif
    @if ($wallet)
        <p style="margin:0 0 8px;color:#d5e8da;font-size:14px;">{{ $locale === 'en' ? 'Account' : 'Compte' }} : {{ $wallet }}</p>
    @endif
    @if ($notes)
        <p style="margin:0 0 8px;color:#d5e8da;font-size:14px;">{{ $locale === 'en' ? 'Details' : 'Détails' }} : {{ $notes }}</p>
    @endif
    @if ($confirm && $actionUrl)
        <x-mail.button
            :href="$actionUrl"
            :show-url="false"
            :label="$locale === 'en' ? 'Open and confirm' : 'Ouvrir pour confirmer'"
        />
    @endif
@endsection

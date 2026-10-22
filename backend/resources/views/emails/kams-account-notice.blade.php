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
        'withdrawal' => match ($locale) {
            'en' => 'withdrawal',
            'sw' => 'utoaji',
            default => 'retrait',
        },
        'transfer' => match ($locale) {
            'en' => 'transfer',
            'sw' => 'uhamisho',
            default => 'transfert',
        },
        default => match ($locale) {
            'en' => 'deposit',
            'sw' => 'amana',
            default => 'dépôt',
        },
    };
    $hello = match ($locale) {
        'en' => 'Hello',
        'sw' => 'Habari',
        default => 'Bonjour',
    };
    $officeLabel = match ($locale) {
        'en' => 'Office',
        'sw' => 'Ofisi',
        default => 'Bureau',
    };
    $accountLabel = match ($locale) {
        'en' => 'Account',
        'sw' => 'Akaunti',
        default => 'Compte',
    };
    $detailsLabel = match ($locale) {
        'en' => 'Details',
        'sw' => 'Maelezo',
        default => 'Détails',
    };
    $confirmLabel = match ($locale) {
        'en' => 'Open and confirm',
        'sw' => 'Fungua na uthibitishe',
        default => 'Ouvrir pour confirmer',
    };
    $preheader = $title;
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $hello }} {{ $user->name }},</p>
    <x-mail.panel :tone="$confirm ? 'neutral' : 'success'">
        @if ($confirm)
            @if ($locale === 'en')
                A {{ $typeLabel }} of <strong>{{ $amountLabel }}</strong>
                @if ($memberName) for <strong>{{ $memberName }}</strong> @endif
                is waiting for your confirmation.
            @elseif ($locale === 'sw')
                @if ($movement === 'transfer')
                    Uhamisho wa <strong>{{ $amountLabel }}</strong> kuelekea akaunti yako unasubiri uthibitisho wako.
                @else
                    Ombi la {{ $typeLabel }} la <strong>{{ $amountLabel }}</strong>
                    @if ($memberName) kwa <strong>{{ $memberName }}</strong> @endif
                    linasubiri uthibitisho wako.
                @endif
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
            @elseif ($locale === 'sw')
                {{ ucfirst($typeLabel) }} ya <strong>{{ $amountLabel }}</strong> imerekodiwa kwenye akaunti yako.
                @if ($balanceLabel !== null)
                    Salio lako sasa ni <strong>{{ $balanceLabel }}</strong>.
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
        <p style="margin:0 0 8px;color:#d5e8da;font-size:14px;">{{ $officeLabel }} : {{ $office }}</p>
    @endif
    @if ($wallet)
        <p style="margin:0 0 8px;color:#d5e8da;font-size:14px;">{{ $accountLabel }} : {{ $wallet }}</p>
    @endif
    @if ($notes)
        <p style="margin:0 0 8px;color:#d5e8da;font-size:14px;">{{ $detailsLabel }} : {{ $notes }}</p>
    @endif
    @if ($confirm && $actionUrl)
        <x-mail.button
            :href="$actionUrl"
            :show-url="false"
            :label="$confirmLabel"
        />
    @endif
@endsection

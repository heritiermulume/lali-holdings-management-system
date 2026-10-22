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
    $title = $locale === 'en'
        ? ($approved ? 'Your finance request was approved' : 'Your finance request was rejected')
        : ($approved ? 'Votre demande financière a été acceptée' : 'Votre demande financière a été refusée');
    $preheader = $title;
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $locale === 'en' ? 'Hello' : 'Bonjour' }} {{ $user->name }},</p>
    <x-mail.panel :tone="$approved ? 'success' : 'danger'">
        @if ($locale === 'en')
            Your {{ $typeLabel }} request
            @if ($memberName) for <strong>{{ $memberName }}</strong> @endif
            of <strong>{{ $amount }}</strong>
            has been <strong>{{ $approved ? 'approved' : 'rejected' }}</strong>.
        @else
            Votre demande de {{ $typeLabel }}
            @if ($memberName) pour <strong>{{ $memberName }}</strong> @endif
            de <strong>{{ $amount }}</strong>
            a été <strong>{{ $approved ? 'acceptée' : 'refusée' }}</strong>.
        @endif
    </x-mail.panel>
    @if ($notes)
        <p style="margin:0 0 12px;color:#d5e8da;font-size:14px;">
            {{ $locale === 'en' ? 'Request note' : 'Motif de la demande' }} : {{ $notes }}
        </p>
    @endif
    @if (! $approved && $reason)
        <p style="margin:0 0 12px;font-size:14px;">
            <span style="color:#f3c7c7;font-weight:700;">{{ $locale === 'en' ? 'Rejection reason' : 'Motif du refus' }}</span><br>
            {{ $reason }}
        </p>
    @endif
@endsection
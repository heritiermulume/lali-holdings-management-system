@php
    $title = match ($locale) {
        'en' => 'Reset your password',
        'sw' => 'Weka upya nenosiri lako',
        default => 'Réinitialisez votre mot de passe',
    };
    $preheader = match ($locale) {
        'en' => 'Choose a new LHMS password.',
        'sw' => 'Chagua nenosiri jipya la LHMS.',
        default => 'Choisissez un nouveau mot de passe LHMS.',
    };
    $hello = match ($locale) {
        'en' => 'Hello',
        'sw' => 'Habari',
        default => 'Bonjour',
    };
    $body = match ($locale) {
        'en' => 'We received a request to reset your LHMS password. Use the button below to choose a new one.',
        'sw' => 'Tumepokea ombi la kuweka upya nenosiri lako la LHMS. Tumia kitufe hapa chini kuchagua jipya.',
        default => 'Nous avons reçu une demande de réinitialisation de votre mot de passe LHMS. Utilisez le bouton ci-dessous pour en choisir un nouveau.',
    };
    $expiry = match ($locale) {
        'en' => 'This link expires in '.$expiresMinutes.' minutes.',
        'sw' => 'Kiungo hiki kinaisha baada ya dakika '.$expiresMinutes.'.',
        default => 'Ce lien expire dans '.$expiresMinutes.' minutes.',
    };
    $button = match ($locale) {
        'en' => 'Reset password',
        'sw' => 'Weka upya nenosiri',
        default => 'Réinitialiser le mot de passe',
    };
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $hello }} {{ $user->name }},</p>
    <p style="margin:0 0 14px;">{{ $body }}</p>
    <p style="margin:0;color:#d5e8da;font-size:14px;">{{ $expiry }}</p>
    <x-mail.button
        :href="$resetUrl"
        :show-url="false"
        :label="$button"
    />
@endsection

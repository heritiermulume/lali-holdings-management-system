@php
    $title = $locale === 'en' ? 'Reset your password' : 'Réinitialisez votre mot de passe';
    $preheader = $locale === 'en' ? 'Choose a new LHMS password.' : 'Choisissez un nouveau mot de passe LHMS.';
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $locale === 'en' ? 'Hello' : 'Bonjour' }} {{ $user->name }},</p>
    <p style="margin:0 0 14px;">
        {{ $locale === 'en'
            ? 'We received a request to reset your LHMS password. Use the button below to choose a new one.'
            : 'Nous avons reçu une demande de réinitialisation de votre mot de passe LHMS. Utilisez le bouton ci-dessous pour en choisir un nouveau.' }}
    </p>
    <p style="margin:0;color:#d5e8da;font-size:14px;">
        {{ $locale === 'en'
            ? 'This link expires in '.$expiresMinutes.' minutes.'
            : 'Ce lien expire dans '.$expiresMinutes.' minutes.' }}
    </p>
    <x-mail.button
        :href="$resetUrl"
        :show-url="false"
        :label="$locale === 'en' ? 'Reset password' : 'Réinitialiser le mot de passe'"
    />
@endsection

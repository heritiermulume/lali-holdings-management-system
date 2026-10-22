@php
    $title = $locale === 'en' ? 'Your login credentials' : 'Vos identifiants de connexion';
    $preheader = $locale === 'en' ? 'Your LHMS agent account is ready.' : 'Votre compte agent LHMS est prêt.';
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $locale === 'en' ? 'Hello' : 'Bonjour' }} {{ $user->name }},</p>
    <p style="margin:0 0 16px;">
        {{ $locale === 'en'
            ? 'An agent account has been created for you. Sign in with your email or phone number and the password below.'
            : 'Un compte agent a été créé pour vous. Connectez-vous avec votre e-mail ou votre numéro de téléphone et le mot de passe ci-dessous.' }}
    </p>
    <x-mail.panel>
        <div style="font-size:12px;color:#5d6b62;">{{ $locale === 'en' ? 'Email' : 'E-mail' }}</div>
        <div style="margin-top:4px;font-size:16px;font-weight:700;">{{ $user->email }}</div>
        @if ($user->phone)
            <div style="margin-top:14px;font-size:12px;color:#5d6b62;">{{ $locale === 'en' ? 'Phone' : 'Téléphone' }}</div>
            <div style="margin-top:4px;font-size:16px;font-weight:700;">{{ $user->phone }}</div>
        @endif
        <div style="margin-top:14px;font-size:12px;color:#5d6b62;">{{ $locale === 'en' ? 'Password' : 'Mot de passe' }}</div>
        <div style="margin-top:4px;font-size:16px;font-weight:700;font-family:Menlo,Consolas,monospace;">{{ $password }}</div>
    </x-mail.panel>
    <x-mail.button
        :href="$loginUrl"
        :label="$locale === 'en' ? 'Sign in to LHMS' : 'Se connecter à LHMS'"
    />
@endsection

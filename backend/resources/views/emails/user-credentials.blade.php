@php
    $title = match ($locale) {
        'en' => 'Your login credentials',
        'sw' => 'Taarifa zako za kuingia',
        default => 'Vos identifiants de connexion',
    };
    $preheader = match ($locale) {
        'en' => 'Your LHMS agent account is ready.',
        'sw' => 'Akaunti yako ya wakala wa LHMS iko tayari.',
        default => 'Votre compte agent LHMS est prêt.',
    };
    $hello = match ($locale) {
        'en' => 'Hello',
        'sw' => 'Habari',
        default => 'Bonjour',
    };
    $intro = match ($locale) {
        'en' => 'An agent account has been created for you. Sign in with your email or phone number and the password below.',
        'sw' => 'Akaunti ya wakala imeundwa kwa ajili yako. Ingia kwa barua pepe au nambari ya simu na nenosiri hapa chini.',
        default => 'Un compte agent a été créé pour vous. Connectez-vous avec votre e-mail ou votre numéro de téléphone et le mot de passe ci-dessous.',
    };
    $emailLabel = match ($locale) {
        'en' => 'Email',
        'sw' => 'Barua pepe',
        default => 'E-mail',
    };
    $phoneLabel = match ($locale) {
        'en' => 'Phone',
        'sw' => 'Simu',
        default => 'Téléphone',
    };
    $passwordLabel = match ($locale) {
        'en' => 'Password',
        'sw' => 'Nenosiri',
        default => 'Mot de passe',
    };
    $button = match ($locale) {
        'en' => 'Sign in to LHMS',
        'sw' => 'Ingia kwenye LHMS',
        default => 'Se connecter à LHMS',
    };
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $hello }} {{ $user->name }},</p>
    <p style="margin:0 0 16px;">{{ $intro }}</p>
    <x-mail.panel>
        <div style="font-size:12px;color:#5d6b62;">{{ $emailLabel }}</div>
        <div style="margin-top:4px;font-size:16px;font-weight:700;">{{ $user->email }}</div>
        @if ($user->phone)
            <div style="margin-top:14px;font-size:12px;color:#5d6b62;">{{ $phoneLabel }}</div>
            <div style="margin-top:4px;font-size:16px;font-weight:700;">{{ $user->phone }}</div>
        @endif
        <div style="margin-top:14px;font-size:12px;color:#5d6b62;">{{ $passwordLabel }}</div>
        <div style="margin-top:4px;font-size:16px;font-weight:700;font-family:Menlo,Consolas,monospace;">{{ $password }}</div>
    </x-mail.panel>
    <x-mail.button
        :href="$loginUrl"
        :label="$button"
    />
@endsection

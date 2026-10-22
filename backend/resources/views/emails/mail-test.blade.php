@php
    $title = $locale === 'en' ? 'Mail configuration test' : 'Test de configuration e-mail';
    $preheader = $locale === 'en' ? 'Your LHMS mail settings are working.' : 'Votre configuration e-mail LHMS fonctionne.';
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">
        {{ $locale === 'en'
            ? 'This message confirms that LHMS can send email with the settings saved in Administration.'
            : 'Ce message confirme que LHMS peut envoyer des e-mails avec les réglages enregistrés dans Administration.' }}
    </p>
    <x-mail.panel tone="success">
        {{ $locale === 'en' ? 'Delivery from this mailbox is working.' : 'L’envoi depuis cette boîte fonctionne.' }}
    </x-mail.panel>
@endsection

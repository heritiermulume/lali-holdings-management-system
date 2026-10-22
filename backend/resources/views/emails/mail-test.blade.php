@php
    $title = match ($locale) {
        'en' => 'Mail configuration test',
        'sw' => 'Jaribio la usanidi wa barua pepe',
        default => 'Test de configuration e-mail',
    };
    $preheader = match ($locale) {
        'en' => 'Your LHMS mail settings are working.',
        'sw' => 'Mipangilio yako ya barua pepe ya LHMS inafanya kazi.',
        default => 'Votre configuration e-mail LHMS fonctionne.',
    };
    $body = match ($locale) {
        'en' => 'This message confirms that LHMS can send email with the settings saved in Administration.',
        'sw' => 'Ujumbe huu unathibitisha kuwa LHMS inaweza kutuma barua pepe kwa mipangilio iliyohifadhiwa katika Usimamizi.',
        default => 'Ce message confirme que LHMS peut envoyer des e-mails avec les réglages enregistrés dans Administration.',
    };
    $panel = match ($locale) {
        'en' => 'Delivery from this mailbox is working.',
        'sw' => 'Kutuma kutoka kwenye sanduku hili la barua kunafanya kazi.',
        default => 'L’envoi depuis cette boîte fonctionne.',
    };
@endphp
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 14px;">{{ $body }}</p>
    <x-mail.panel tone="success">
        {{ $panel }}
    </x-mail.panel>
@endsection

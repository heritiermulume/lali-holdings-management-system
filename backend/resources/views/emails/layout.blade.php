@php
    $headerText = '#02250b';
    $ink = '#f4f7f5';
    $muted = '#d5e8da';
    $line = '#f0a202';
    $body = '#043711';
    $year = now()->year;
    $product = 'LHMS';
    $locale = $locale ?? 'fr';
    $preheader = $preheader ?? '';
    $eyebrow = $eyebrow ?? 'LALI HOLDINGS MANAGEMENT SYSTEM';
    $logoUrl = asset('lali_holdings_logo.png');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" style="margin:0;padding:0;background:{{ $body }};background-color:{{ $body }};">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light only; supported-color-schemes: light; }
        .lhms-header-text,
        .lhms-header-text span,
        .lhms-header-text font {
            color: #02250b !important;
            -webkit-text-fill-color: #02250b !important;
        }
        .lhms-body,
        .lhms-body-wrap {
            background: #043711 !important;
            background-color: #043711 !important;
        }
        .lhms-body a.lhms-link {
            color: #f0a202 !important;
            -webkit-text-fill-color: #f0a202 !important;
            text-decoration: underline !important;
        }
        .lhms-body a.lhms-button {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
            text-decoration: none !important;
        }
        @media (prefers-color-scheme: dark) {
            .lhms-header-text,
            .lhms-header-text span,
            .lhms-header-text font {
                color: #02250b !important;
                -webkit-text-fill-color: #02250b !important;
            }
            .lhms-body,
            .lhms-body-wrap {
                background: #043711 !important;
                background-color: #043711 !important;
            }
            .lhms-body a.lhms-link {
                color: #f0a202 !important;
                -webkit-text-fill-color: #f0a202 !important;
                text-decoration: underline !important;
            }
            .lhms-body a.lhms-button {
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                text-decoration: none !important;
            }
        }
    </style>
</head>
<body bgcolor="{{ $body }}" style="margin:0;padding:0;background:{{ $body }} !important;background-color:{{ $body }} !important;color:{{ $ink }};font-family:'Plus Jakarta Sans',Arial,Helvetica,sans-serif;">
    @if ($preheader !== '')
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}</div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{{ $body }}" style="margin:0;background:{{ $body }} !important;background-color:{{ $body }} !important;">
        <tr>
            <td align="center" bgcolor="{{ $body }}" style="padding:0;background:{{ $body }} !important;background-color:{{ $body }} !important;">
                <table role="presentation" class="lhms-body-wrap" width="100%" cellpadding="0" cellspacing="0" bgcolor="{{ $body }}" style="margin:0;background:{{ $body }} !important;background-color:{{ $body }} !important;">
                    <tr>
                        <td color="{{ $headerText }}" align="center" style="background-color:#e8f3ea;background-image:linear-gradient(135deg,#f3f8f4 0%,#d5e8da 52%,#9fc9a8 100%);padding:10px 32px 10px;border-bottom:4px solid {{ $line }};color:{{ $headerText }};text-align:center;">
                            @if ($logoUrl !== '')
                                <img src="{{ $logoUrl }}" alt="Lali Holdings Limited" width="128" style="display:block;width:128px;max-width:100%;height:auto;border:0;margin:0 auto;">
                            @endif
                            @php
                                $forcedText = 'color:#02250b !important;-webkit-text-fill-color:#02250b !important;background-image:linear-gradient(#02250b,#02250b);background-repeat:repeat;-webkit-background-clip:text;background-clip:text;text-align:center;';
                            @endphp
                            <div class="lhms-header-text" align="center" style="margin-top:6px;font-size:11px;line-height:1.3;letter-spacing:.12em;font-weight:700;text-align:center;{{ $forcedText }}">
                                <font color="#02250b"><span class="lhms-header-text" style="{{ $forcedText }}">{{ $eyebrow }}</span></font>
                            </div>
                            <div class="lhms-header-text" align="center" style="margin-top:2px;font-size:18px;line-height:1.2;font-weight:800;text-align:center;{{ $forcedText }}">
                                <font color="#02250b"><span class="lhms-header-text" style="{{ $forcedText }}">{{ $product }}</span></font>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="lhms-body" bgcolor="{{ $body }}" style="padding:28px 32px 8px;background:{{ $body }} !important;background-color:{{ $body }} !important;">
                            <h1 style="margin:0 0 18px;font-size:22px;line-height:1.3;font-weight:800;color:{{ $ink }};">{{ $title }}</h1>
                            <div style="font-size:15px;line-height:1.6;color:{{ $ink }};">
                                @yield('content')
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="lhms-body" align="center" bgcolor="{{ $body }}" style="padding:8px 32px 28px;background:{{ $body }} !important;background-color:{{ $body }} !important;font-size:12px;line-height:1.5;color:{{ $muted }};text-align:center;">
                            <div style="height:2px;background:{{ $line }};background-color:{{ $line }};margin:0 0 16px;"></div>
                            {{ $locale === 'en'
                                ? 'This message was sent automatically by '.$product.'. Please do not reply to it.'
                                : 'Ce message a été envoyé automatiquement par '.$product.'. Merci de ne pas y répondre.' }}
                            <div align="center" style="margin-top:8px;color:#e8f3ea;font-weight:700;text-align:center;">© {{ $year }} Lali Holdings Limited</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

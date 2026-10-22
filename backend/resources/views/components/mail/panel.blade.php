@props(['tone' => 'neutral'])
@php
    $styles = match ($tone) {
        'danger' => 'background:#fdecec;border:1px solid #f3c7c7;',
        'success' => 'background:#eef7e8;border:1px solid #cfe6c4;',
        default => 'background:#cfe3d4;border:1px solid #b7d4be;',
    };
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;border-radius:14px;{{ $styles }}">
    <tr>
        <td style="padding:16px 18px;font-size:14px;line-height:1.5;color:#122018;">
            {{ $slot }}
        </td>
    </tr>
</table>

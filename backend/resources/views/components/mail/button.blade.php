@props(['href', 'label', 'showUrl' => true])
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 8px;">
    <tr>
        <td align="center" bgcolor="#4e9a2c" style="border-radius:12px;background:#4e9a2c;background-color:#4e9a2c;">
            <a href="{{ $href }}" target="_blank" rel="noopener" class="lhms-button" style="display:inline-block;padding:14px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;line-height:1.2;color:#ffffff;text-decoration:none;">{{ $label }}</a>
        </td>
    </tr>
</table>
@if ($showUrl)
    <p style="margin:0 0 8px;font-size:13px;line-height:1.5;">
        <a href="{{ $href }}" target="_blank" rel="noopener" class="lhms-link" style="color:#f0a202;text-decoration:underline;-webkit-text-fill-color:#f0a202;">{{ $href }}</a>
    </p>
@endif

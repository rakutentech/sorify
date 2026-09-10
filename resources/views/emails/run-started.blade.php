<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sorify</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:8px;border:1px solid #e0e0e0;">
                    <tr>
                        <td style="padding:20px 24px;border-bottom:1px solid #e0e0e0;">
                            <p style="margin:0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#757575;">Sorify</p>
                            <h1 style="margin:4px 0 0;font-size:20px;line-height:1.3;color:#212121;">
                                Suite: {{ $suite->name }} — Run started
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-bottom:1px solid #e0e0e0;">
                            <p style="margin:0 0 8px;font-size:15px;color:#212121;">
                                <strong>{{ $run->total_tests }} test{{ $run->total_tests === 1 ? '' : 's' }} queued</strong>
                            </p>
                            <p style="margin:0;font-size:13px;color:#757575;">Triggered by: {{ $triggeredBy }}</p>
                            @if ($cooldownNotice)
                            <p style="margin:8px 0 0;font-size:13px;color:#757575;">{{ $cooldownNotice }}</p>
                            @endif
                            <p style="margin:12px 0 0;">
                                <a href="{{ $runUrl }}" style="display:inline-block;padding:8px 16px;background:#b3261e;color:#ffffff;text-decoration:none;border-radius:16px;font-size:13px;">View Run</a>
                                &nbsp;
                                <a href="{{ $suiteUrl }}" style="display:inline-block;padding:8px 16px;background:#f4f4f4;color:#212121;text-decoration:none;border-radius:16px;font-size:13px;">View Test Suite</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 24px;font-size:12px;color:#9e9e9e;">
                            You received this email because you are a recipient of suite notifications in Sorify.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

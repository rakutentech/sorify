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
                    {{-- Header --}}
                    <tr>
                        <td style="padding:20px 24px;border-bottom:1px solid #e0e0e0;">
                            <p style="margin:0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#757575;">Sorify</p>
                            <h1 style="margin:4px 0 0;font-size:20px;line-height:1.3;color:{{ $isSuccess ? '#1e7e34' : '#c62828' }};">
                                Suite: {{ $suite->name }} — {{ $isSuccess ? 'Success' : 'Failure' }}
                            </h1>
                        </td>
                    </tr>

                    {{-- Summary --}}
                    <tr>
                        <td style="padding:16px 24px;border-bottom:1px solid #e0e0e0;">
                            <p style="margin:0 0 8px;font-size:15px;color:#212121;">
                                <strong style="color:{{ $isSuccess ? '#1e7e34' : '#c62828' }};">{{ $run->passed_count }}/{{ $run->total_tests }} passed</strong>
                                &nbsp;•&nbsp; {{ $run->failed_count }} failed &nbsp;•&nbsp; {{ $run->error_count }} errors &nbsp;•&nbsp; {{ $duration }}
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

                    {{-- Tests --}}
                    @if (count($tests) > 0)
                    <tr>
                        <td style="padding:16px 24px;border-bottom:1px solid #e0e0e0;">
                            <p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#757575;">Tests</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#212121;">
                                @foreach ($tests as $test)
                                <tr>
                                    <td style="padding:3px 0;color:#212121;">
                                        <span style="color:{{ in_array($test['status'], ['passed'], true) ? '#1e7e34' : '#c62828' }};">{{ $test['status'] === 'passed' ? '✔' : '✘' }}</span>
                                        &nbsp;{{ $test['name'] }}
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @if ($remainingTests > 0)
                            <p style="margin:8px 0 0;font-size:13px;color:#757575;">
                                <a href="{{ $runUrl }}" style="color:#b3261e;">+{{ $remainingTests }} more test{{ $remainingTests === 1 ? '' : 's' }}</a>
                            </p>
                            @endif
                        </td>
                    </tr>
                    @endif

                    {{-- Screenshots: embedded inline, each linking to the app --}}
                    @if (count($screenshots) > 0)
                    <tr>
                        <td style="padding:16px 24px;border-bottom:1px solid #e0e0e0;">
                            <p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#757575;">Screenshots</p>
                            @foreach ($screenshots as $screenshot)
                            <div style="margin:0 0 16px;">
                                <p style="margin:0 0 4px;font-size:13px;">
                                    <a href="{{ $screenshot['url'] }}" style="color:#b3261e;text-decoration:none;font-weight:600;">{{ $screenshot['label'] }}</a>
                                </p>
                                <a href="{{ $screenshot['url'] }}">
                                    <img src="{{ $message->embedData($screenshot['data'], $screenshot['filename'], $screenshot['mime']) }}" alt="{{ $screenshot['label'] }}" style="display:block;max-width:100%;height:auto;border:1px solid #e0e0e0;border-radius:6px;">
                                </a>
                            </div>
                            @endforeach
                            @if ($remainingScreenshots > 0)
                            <p style="margin:0;font-size:13px;color:#757575;">
                                <a href="{{ $runUrl }}" style="color:#b3261e;">+{{ $remainingScreenshots }} more screenshot{{ $remainingScreenshots === 1 ? '' : 's' }}</a>
                            </p>
                            @endif
                        </td>
                    </tr>
                    @endif

                    {{-- Footer --}}
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

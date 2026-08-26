<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentor Maths — your tcode &amp; next steps</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="background:#0f766e;padding:22px 28px;color:#ffffff;">
                        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Mentor Maths · Early access</div>
                        <div style="font-size:22px;font-weight:700;margin-top:4px;">Your tcode + next steps</div>
                        <div style="font-size:14px;margin-top:8px;opacity:.95;">Hello {{ $recipientName }},</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px;">
                        <p style="margin:0 0 14px;font-size:15px;line-height:1.55;">
                            Your trial access is ready — <strong>no admin approval needed</strong>.
                            Use the login details below, then follow the short next steps with your class.
                        </p>

                        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                            <div style="font-weight:700;font-size:15px;color:#065f46;margin-bottom:8px;">Login details</div>
                            <ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.7;color:#064e3b;">
                                <li><strong>Login page:</strong> <a href="{{ $loginUrl }}" style="color:#0f766e;">{{ $loginUrl }}</a></li>
                                <li><strong>Email:</strong> {{ $loginEmail }}</li>
                                <li><strong>Access code (tcode):</strong> <span style="font-family:Consolas,monospace;font-weight:700;">{{ $plainCode }}</span></li>
                                @if ($expiresOn)
                                    <li><strong>Valid until:</strong> {{ $expiresOn }}</li>
                                @endif
                            </ul>
                            <p style="margin:10px 0 0;font-size:13px;color:#047857;">
                                Use your <strong>email + tcode</strong> (as password). You can also enter the tcode alone in the email field.
                            </p>
                        </div>

                        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                            <div style="font-weight:700;font-size:15px;color:#9a3412;margin-bottom:6px;">Next steps (do this today)</div>
                            <ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.65;color:#7c2d12;">
                                <li>Log in and open your <a href="{{ $classesUrl }}" style="color:#0f766e;font-weight:600;">Classes hub</a></li>
                                <li>Ask students to enrol at <a href="{{ $registerUrl }}" style="color:#0f766e;">{{ $registerUrl }}</a>
                                    — choose <strong>Coaching</strong> → your institute → your name</li>
                                <li>Students get their own tcode by email, then tick <strong>My Study Plan</strong> (Studied / Under study)</li>
                                <li>Book-wise practice &amp; tests unlock for those chapters — track completion from your class dashboard</li>
                            </ol>
                        </div>

                        <h2 style="margin:8px 0 8px;font-size:16px;">What the study plan looks like</h2>
                        <p style="margin:0 0 10px;font-size:13px;color:#64748b;">Students tick chapters already done in school, and mark one chapter as under study.</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:20px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:8px;">My Study Plan · Class example</div>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
                                        <tr>
                                            <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:13px;">
                                                Ch 1 · Patterns
                                                <span style="float:right;background:#d1fae5;color:#065f46;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">✓ Studied</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:13px;background:#fffbeb;">
                                                Ch 2 · Lines &amp; Angles
                                                <span style="float:right;background:#fde68a;color:#92400e;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">◉ Under study</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:10px 12px;font-size:13px;color:#94a3b8;">
                                                Ch 3 · Number Play
                                                <span style="float:right;font-size:11px;font-weight:600;">Not started</span>
                                            </td>
                                        </tr>
                                    </table>
                                    <div style="margin-top:8px;font-size:12px;color:#0f766e;font-weight:600;">→ Book-wise practice &amp; tests unlock for Studied / Under study chapters</div>
                                </td>
                            </tr>
                        </table>

                        <h2 style="margin:0 0 12px;font-size:17px;border-bottom:2px solid #0f766e;padding-bottom:6px;">How the system works (brief)</h2>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">1</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Drills (warm-up every day)</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Basics (tables/squares/cubes) and Formula drill unlock after the study plan is marked.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">2</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Study plan — tick topics</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Mark chapters as <strong>Studied</strong> / <strong>Under study</strong> so matching book sets appear.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">3</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Practice / tests</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Students work Learner / Achiever / Expert sets and chapter tests from To-do.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">4</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">MCQ / fill-blank method</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Wrong → re-attempt → hint → ask teacher → flag if the sum looks wrong.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">5</div>
                                </td>
                                <td style="padding:0 0 4px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Revision of first-attempt misses</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Wrong answers go to a revision queue until cleared — weak spots don’t get skipped.
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:18px 0 0;font-size:13px;color:#64748b;line-height:1.5;">
                            Browse content:
                            <a href="{{ $coverageUrl }}" style="color:#0f766e;">Content coverage</a>
                            · Your hub:
                            <a href="{{ $classesUrl }}" style="color:#0f766e;">Classes</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
                        {{ config('app.name') }} · Trial welcome · You will also get a short daily early-access note while your tcode is active.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

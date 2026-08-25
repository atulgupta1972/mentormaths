<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentor Maths — how to start</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;">
@php($p = $payload)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="background:#1d4ed8;padding:22px 28px;color:#ffffff;">
                        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Mentor Maths · Welcome</div>
                        <div style="font-size:22px;font-weight:700;margin-top:4px;">Your complete process</div>
                        <div style="font-size:14px;margin-top:8px;opacity:.95;">Hello {{ $p['student_name'] }},</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px;">
                        <p style="margin:0 0 14px;font-size:15px;line-height:1.55;">
                            You already have your login / tcode email. This note explains <strong>exactly how to work</strong> on Mentor Maths.
                            Important: <strong>daily drills start only after you fill your school study plan</strong>.
                        </p>

                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                            <div style="font-weight:700;font-size:15px;color:#1e40af;margin-bottom:6px;">Start here (in order)</div>
                            <ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.65;color:#1e3a8a;">
                                <li>Log in: <a href="{{ $p['login_url'] }}" style="color:#1d4ed8;">{{ $p['login_url'] }}</a></li>
                                <li>Open <a href="{{ $p['study_plan_url'] }}" style="color:#1d4ed8;">My Study Plan</a> and tick chapters</li>
                                <li>Then daily drills unlock — formula + basics</li>
                                <li>Do book-wise practice / tests from To do</li>
                            </ol>
                        </div>

                        <h2 style="margin:8px 0 8px;font-size:16px;">What the study plan looks like</h2>
                        <p style="margin:0 0 10px;font-size:13px;color:#64748b;">Tick chapters already done in school, and mark one chapter as under study.</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:20px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:8px;">My Study Plan</div>
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
                                    <div style="margin-top:8px;font-size:12px;color:#1d4ed8;font-weight:600;">→ After this, drills + book-wise sets unlock</div>
                                </td>
                            </tr>
                        </table>

                        <h2 style="margin:0 0 8px;font-size:16px;">How a test / MCQ looks</h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;border-radius:10px;margin-bottom:22px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="background:#1e293b;border-radius:8px;padding:12px 14px;color:#e2e8f0;">
                                        <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;">Question 3 of 12 · Timer running</div>
                                        <div style="font-size:15px;font-weight:600;line-height:1.45;margin-bottom:12px;color:#f8fafc;">
                                            The perimeter of a rectangle is 36 cm. If length is 10 cm, what is the breadth?
                                        </div>
                                        <div style="font-size:13px;line-height:1.8;">
                                            <div style="background:#334155;border-radius:6px;padding:6px 10px;margin-bottom:6px;">A) 6 cm</div>
                                            <div style="background:#1d4ed8;border:2px solid #93c5fd;border-radius:6px;padding:6px 10px;margin-bottom:6px;font-weight:700;">B) 8 cm ← selected</div>
                                            <div style="background:#334155;border-radius:6px;padding:6px 10px;margin-bottom:6px;">C) 16 cm</div>
                                            <div style="background:#334155;border-radius:6px;padding:6px 10px;">D) 26 cm</div>
                                        </div>
                                        <div style="margin-top:12px;">
                                            <span style="display:inline-block;background:#3b82f6;color:#eff6ff;font-size:12px;font-weight:700;padding:6px 12px;border-radius:6px;">Submit</span>
                                            <span style="display:inline-block;margin-left:8px;background:#334155;color:#cbd5e1;font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;">Hint</span>
                                            <span style="display:inline-block;margin-left:8px;background:#7f1d1d;color:#fecaca;font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;">Flag question</span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <h2 style="margin:0 0 12px;font-size:17px;border-bottom:2px solid #1d4ed8;padding-bottom:6px;">How the system works (step by step)</h2>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#1d4ed8;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">1</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Drills (after study plan is filled)</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        <strong>Basics drill:</strong> tables, squares, cubes.<br>
                                        <strong>Formula drill:</strong> short daily formula recall + corrections.
                                        These start the first time only once your study plan has at least one chapter marked.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#1d4ed8;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">2</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Study plan — tick topics</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Mark chapters already covered in school as <strong>Studied</strong>, and the current chapter as <strong>Under study</strong>.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#1d4ed8;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">3</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Take test / formula revision</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Chapter tests and formula practice appear for planned chapters. Open them from the dashboard To-do list.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#1d4ed8;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">4</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">MCQ / fill-in-the-blank methodology</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        If not correct → <strong>re-attempt</strong>.<br>
                                        Still stuck → use the <strong>hint</strong>.<br>
                                        Still not resolving → ask your <strong>teacher</strong> for help, then move on.<br>
                                        If something feels wrong in the question → <strong>flag</strong> it.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#1d4ed8;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">5</div>
                                </td>
                                <td style="padding:0 0 4px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Revision of first-attempt misses</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Questions not done correctly on the first attempt go into <strong>revision</strong>. Clear them later so weak spots are closed.
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:20px 0 0;font-size:14px;">
                            <a href="{{ $p['dashboard_url'] }}" style="display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;font-weight:700;padding:10px 16px;border-radius:8px;">Open dashboard</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
                        {{ config('app.name') }} · Keep your tcode email safe for login.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentor Maths early access</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;">
@php
    $hasStudents = (bool) ($payload['has_students'] ?? false);
    $students = $payload['students'] ?? [];
    $stats = $payload['stats'] ?? ['total' => 0, 'with_plan' => 0, 'without_plan' => 0];
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                {{-- Header --}}
                <tr>
                    <td style="background:#0f766e;padding:22px 28px;color:#ffffff;">
                        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Mentor Maths · Early access</div>
                        <div style="font-size:22px;font-weight:700;margin-top:4px;">Daily class update · {{ $payload['as_of_label'] }}</div>
                        <div style="font-size:14px;margin-top:8px;opacity:.95;">Hello {{ $payload['mentor_name'] }},</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px;">

                        @if ($hasStudents)
                            <p style="margin:0 0 12px;font-size:15px;line-height:1.5;">
                                Here are the students enrolled under you today.
                                Ask anyone without a study plan to tick chapters so book-wise sets can start.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
                                <tr>
                                    <td width="33%" style="padding:10px;background:#ecfdf5;border-radius:8px;text-align:center;">
                                        <div style="font-size:22px;font-weight:700;color:#047857;">{{ $stats['total'] }}</div>
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Students</div>
                                    </td>
                                    <td width="4%"></td>
                                    <td width="33%" style="padding:10px;background:#eff6ff;border-radius:8px;text-align:center;">
                                        <div style="font-size:22px;font-weight:700;color:#1d4ed8;">{{ $stats['with_plan'] }}</div>
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Plan marked</div>
                                    </td>
                                    <td width="4%"></td>
                                    <td width="33%" style="padding:10px;background:#fff7ed;border-radius:8px;text-align:center;">
                                        <div style="font-size:22px;font-weight:700;color:#c2410c;">{{ $stats['without_plan'] }}</div>
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Not marked</div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:20px;">
                                <tr style="background:#f8fafc;">
                                    <th align="left" style="padding:10px 12px;font-size:11px;text-transform:uppercase;color:#64748b;">Student</th>
                                    <th align="left" style="padding:10px 12px;font-size:11px;text-transform:uppercase;color:#64748b;">Class</th>
                                    <th align="left" style="padding:10px 12px;font-size:11px;text-transform:uppercase;color:#64748b;">Study plan</th>
                                </tr>
                                @foreach ($students as $row)
                                    <tr>
                                        <td style="padding:10px 12px;border-top:1px solid #e2e8f0;font-size:14px;font-weight:600;">
                                            {{ $row['name'] }}
                                            @if (!empty($row['coaching_class_name']))
                                                <div style="font-size:12px;font-weight:400;color:#64748b;">{{ $row['coaching_class_name'] }}</div>
                                            @endif
                                        </td>
                                        <td style="padding:10px 12px;border-top:1px solid #e2e8f0;font-size:13px;color:#334155;">
                                            {{ $row['grade_name'] ?? '—' }}
                                            @if (!empty($row['board_name']))
                                                <div style="font-size:11px;color:#94a3b8;">{{ $row['board_name'] }}</div>
                                            @endif
                                        </td>
                                        <td style="padding:10px 12px;border-top:1px solid #e2e8f0;font-size:13px;">
                                            @if ($row['has_study_plan'])
                                                <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#d1fae5;color:#065f46;font-weight:600;">Marked</span>
                                            @else
                                                <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#ffedd5;color:#9a3412;font-weight:600;">Ask to tick</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            <p style="margin:0 0 20px;font-size:14px;">
                                <a href="{{ $payload['classes_url'] }}" style="color:#0f766e;font-weight:600;">Open your Classes hub →</a>
                            </p>
                        @else
                            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:16px 18px;margin-bottom:18px;">
                                <div style="font-size:16px;font-weight:700;color:#9a3412;margin-bottom:6px;">No students enrolled under you yet</div>
                                <p style="margin:0;font-size:14px;line-height:1.55;color:#7c2d12;">
                                    Please ask your students to enrol and select your coaching institute / mentor,
                                    then tick their <strong>school study plan</strong> so book-wise sets can start.
                                </p>
                            </div>

                            <h2 style="margin:0 0 10px;font-size:16px;color:#0f172a;">How students enrol (show them this)</h2>
                            <ol style="margin:0 0 18px;padding-left:20px;font-size:14px;line-height:1.65;color:#334155;">
                                <li>Open <a href="{{ $payload['register_url'] }}" style="color:#0f766e;">{{ $payload['register_url'] }}</a></li>
                                <li>Choose <strong>Coaching</strong> → pick your institute and your name as mentor<br>
                                    <span style="color:#64748b;">(or Home learning → parent/mentor contact with Notify tick)</span></li>
                                <li>They receive a <strong>tcode</strong> by email — log in at
                                    <a href="{{ $payload['login_url'] }}" style="color:#0f766e;">{{ $payload['login_url'] }}</a></li>
                                <li>On the dashboard, open <strong>My Study Plan</strong> and tick chapters</li>
                            </ol>
                        @endif

                        {{-- Visual: study plan --}}
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

                        {{-- Visual: test screen --}}
                        <h2 style="margin:0 0 8px;font-size:16px;">How a test / MCQ looks when they start</h2>
                        <p style="margin:0 0 10px;font-size:13px;color:#64748b;">Same idea for fill-in-the-blank — answer, check, then revise wrongs.</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;border-radius:10px;margin-bottom:22px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="background:#1e293b;border-radius:8px;padding:12px 14px;color:#e2e8f0;">
                                        <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;">C6-PERIM-T1 · Question 3 of 12 · Timer running</div>
                                        <div style="font-size:15px;font-weight:600;line-height:1.45;margin-bottom:12px;color:#f8fafc;">
                                            The perimeter of a rectangle is 36 cm. If length is 10 cm, what is the breadth?
                                        </div>
                                        <div style="font-size:13px;line-height:1.8;">
                                            <div style="background:#334155;border-radius:6px;padding:6px 10px;margin-bottom:6px;">A) 6 cm</div>
                                            <div style="background:#0f766e;border:2px solid #5eead4;border-radius:6px;padding:6px 10px;margin-bottom:6px;font-weight:700;">B) 8 cm ← selected</div>
                                            <div style="background:#334155;border-radius:6px;padding:6px 10px;margin-bottom:6px;">C) 16 cm</div>
                                            <div style="background:#334155;border-radius:6px;padding:6px 10px;">D) 26 cm</div>
                                        </div>
                                        <div style="margin-top:12px;">
                                            <span style="display:inline-block;background:#14b8a6;color:#042f2e;font-size:12px;font-weight:700;padding:6px 12px;border-radius:6px;">Submit answer</span>
                                            <span style="display:inline-block;margin-left:8px;background:#334155;color:#cbd5e1;font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;">Hint</span>
                                            <span style="display:inline-block;margin-left:8px;background:#7f1d1d;color:#fecaca;font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;">Flag question</span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        {{-- Methodology --}}
                        <h2 style="margin:0 0 12px;font-size:17px;border-bottom:2px solid #0f766e;padding-bottom:6px;">How the system works (brief)</h2>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">1</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Drills (warm-up every day)</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        <strong>Basics drill:</strong> multiplication tables, squares, cubes (quick blanks).<br>
                                        <strong>Formula drill:</strong> short daily formula recall + correction of yesterday’s misses.
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
                                        Mark chapters already covered in school as <strong>Studied</strong>, and the current chapter as <strong>Under study</strong>.
                                        This unlocks the matching book-wise sets.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">3</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">Take test / formula revision</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        Chapter tests and formula practice appear for planned chapters.
                                        Finish pending work from the dashboard To-do list.
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:36px;vertical-align:top;padding-top:2px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:#0f766e;color:#fff;text-align:center;line-height:28px;font-weight:700;font-size:13px;">4</div>
                                </td>
                                <td style="padding:0 0 14px 8px;">
                                    <div style="font-weight:700;font-size:15px;">MCQ / fill-in-the-blank methodology</div>
                                    <div style="font-size:13px;color:#475569;line-height:1.55;margin-top:4px;">
                                        If not correct → <strong>re-attempt</strong>.<br>
                                        Still stuck → use the <strong>hint</strong>.<br>
                                        Still not resolving → ask the <strong>teacher</strong> for help, then move on.<br>
                                        If the student feels something is wrong in the question → <strong>flag</strong> it so we can check.
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
                                        Questions not done correctly on the first attempt go into <strong>revision</strong>.
                                        Students clear them later so weak spots are closed, not skipped.
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:18px 0 0;font-size:13px;color:#64748b;line-height:1.5;">
                            Browse syllabus content (no test taking for mentors):
                            <a href="{{ $payload['coverage_url'] }}" style="color:#0f766e;">Content coverage</a>
                            · Your hub:
                            <a href="{{ $payload['classes_url'] }}" style="color:#0f766e;">Classes</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
                        {{ config('app.name') }} · Early access daily note · You receive this while your mentor trial is active.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

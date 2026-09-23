<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $worksheet->set_code }} — Written {{ $kindLabel }}</title>
    <style>
        @page { margin: 10mm 12mm 12mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            color: #111827;
            margin: 0;
            padding: 0 0 8mm 0;
        }
        .header { margin-bottom: 6px; }
        h1 { font-size: 14px; margin: 0 0 2px; line-height: 1.2; }
        .meta { font-size: 9px; color: #374151; margin: 0; line-height: 1.3; }
        .instructions {
            margin: 0 0 6px;
            padding: 5px 7px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            font-size: 8.5px;
            line-height: 1.35;
        }
        .questions { margin: 0; padding: 0; }
        .question { margin: 0 0 5px; padding: 0; page-break-inside: auto; }
        .q-head { font-weight: bold; margin: 0; }
        .diagram { max-width: 100%; width: 280px; max-height: 140px; margin: 4px 0 2px; display: block; object-fit: contain; }
        .options { margin: 2px 0 0 12px; }
        .option { margin: 0; line-height: 1.3; }
        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            font-size: 8.5px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 3px;
        }
        .answer-guide {
            margin-top: 8px;
            padding: 6px 7px;
            border: 1.5px solid #4338ca;
            background: #f5f3ff;
            page-break-inside: avoid;
        }
        .answer-guide-title {
            font-size: 9.5px;
            font-weight: bold;
            margin: 0 0 5px;
            color: #312e81;
        }
        .guide-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
            line-height: 1.4;
        }
        .guide-table td {
            vertical-align: top;
            width: 50%;
            padding: 0 6px 0 0;
        }
        .guide-table td + td {
            padding: 0 0 0 6px;
            border-left: 1px solid #c7d2fe;
        }
        .guide-label {
            font-weight: bold;
            margin: 0 0 3px;
            color: #1e1b4b;
        }
        .guide-line { margin: 0 0 2px; }

        /* Congruence written practice: 2 sums per page with proof scaffold */
        .cong-page {
            page-break-after: always;
            margin: 0;
            padding: 0;
        }
        .cong-page:last-child { page-break-after: auto; }
        .cong-slot {
            border: 1px solid #94a3b8;
            padding: 6px 8px 8px;
            margin: 0 0 8px;
            min-height: 118mm;
            page-break-inside: avoid;
        }
        .cong-q { font-weight: bold; font-size: 10px; margin: 0 0 4px; }
        .cong-diagram {
            max-width: 100%;
            width: 240px;
            max-height: 110px;
            margin: 3px 0 4px;
            display: block;
            object-fit: contain;
        }
        .cong-scaffold { margin-top: 4px; font-size: 9px; }
        .cong-line { margin: 0 0 4px; }
        .cong-blank {
            display: inline-block;
            border-bottom: 1px solid #64748b;
            min-width: 70%;
            height: 11px;
            vertical-align: bottom;
        }
        .cong-diagram-box {
            border: 1px dashed #94a3b8;
            height: 42mm;
            margin: 3px 0 6px;
            padding: 3px 5px;
            color: #64748b;
            font-size: 8px;
        }
        .cong-table {
            width: 100%;
            border-collapse: collapse;
            margin: 3px 0 6px;
            font-size: 8.5px;
        }
        .cong-table th, .cong-table td {
            border: 1px solid #64748b;
            padding: 4px 5px;
            height: 14px;
            text-align: left;
        }
        .cong-table th { background: #f1f5f9; width: 50%; }
        .cong-final { margin: 2px 0 0; }
    </style>
</head>
<body>
    @php
        $isCongruence = (bool) ($isCongruenceSheet ?? false);
    @endphp

    <div class="header">
        <h1>{{ $worksheet->set_code }} — Written {{ $kindLabel }}</h1>
        <p class="meta">
            @if ($className) <strong>Class:</strong> {{ $className }} · @endif
            @if ($boardCode) <strong>Board:</strong> {{ $boardCode }} · @endif
            @if ($chapterName) <strong>Chapter:</strong> {{ $chapterName }} · @endif
            @if ($topicName) <strong>Topic:</strong> {{ $topicName }} · @endif
            <strong>Sums:</strong> {{ count($questions) }}
        </p>
    </div>

    @if ($isCongruence)
        <p class="instructions">
            <strong>How to answer (congruence):</strong>
            Write on this sheet. For every sum use:
            (1) To prove · (2) Diagram with point-to-point marks · (3) Condition / Reason table (3 rows) ·
            (4) Congruence criterion · (5) Final numeric answer from CPCT (length or angle).
            Two sums per page. Upload photo(s) in page order for checking.
        </p>

        @foreach (array_chunk($questions, 2) as $pageQuestions)
            <div class="cong-page">
                @foreach ($pageQuestions as $question)
                    <div class="cong-slot">
                        <div class="cong-q">Q{{ $question['number'] }}. {{ $question['text'] }}</div>
                        @if ($question['diagram_path'] && file_exists($question['diagram_path']))
                            <img src="{{ $question['diagram_path'] }}" class="cong-diagram" alt="Diagram">
                        @endif

                        <div class="cong-scaffold">
                            <p class="cong-line"><strong>1. To prove:</strong> <span class="cong-blank"></span></p>
                            <div class="cong-diagram-box">
                                2. Diagram — redraw point-to-point; mark equal sides / equal angles / right angles.
                            </div>
                            <p class="cong-line" style="margin-bottom:2px;"><strong>3. Condition / Reason</strong></p>
                            <table class="cong-table">
                                <tr><th>Condition</th><th>Reason</th></tr>
                                <tr><td>1.</td><td></td></tr>
                                <tr><td>2.</td><td></td></tr>
                                <tr><td>3.</td><td></td></tr>
                            </table>
                            <p class="cong-line"><strong>4. Congruent by which criterion:</strong> <span class="cong-blank" style="min-width:45%;"></span></p>
                            <p class="cong-final"><strong>5. Final answer (measure from CPCT):</strong> <span class="cong-blank" style="min-width:30%;"></span></p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    @else
        <p class="instructions">
            <strong>How to answer:</strong> Write each answer on a separate sheet in order — Q1, Q2, Q3, … Use the format below (Given → To find → Solution → Answer). Upload photo(s) in page order for AI checking.
        </p>

        <div class="questions">
            @foreach ($questions as $question)
                <div class="question">
                    <div class="q-head">Q{{ $question['number'] }}. {{ $question['text'] }}</div>
                    @if ($question['diagram_path'] && file_exists($question['diagram_path']))
                        <img src="{{ $question['diagram_path'] }}" class="diagram" alt="Diagram">
                    @endif
                    @if ($question['type'] === 'mcq' && count($question['options']) > 0)
                        <div class="options">
                            @foreach ($question['options'] as $option)
                                <div class="option">({{ $option['letter'] }}) {{ $option['text'] }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="answer-guide">
            <div class="answer-guide-title">How to write each answer (on your answer sheet)</div>
            <table class="guide-table">
                <tr>
                    <td>
                        <p class="guide-label">Format — use for every sum on this sheet</p>
                        <p class="guide-line"><strong>Q no:</strong> ______</p>
                        <p class="guide-line"><strong>1. Given:</strong> …</p>
                        <p class="guide-line"><strong>2. To find:</strong> …</p>
                        <p class="guide-line"><strong>3. Solution:</strong> … (step-by-step working)</p>
                        <p class="guide-line"><strong>4. Answer:</strong> … (final value, with units)</p>
                    </td>
                    <td>
                        <p class="guide-label">Example (not from this sheet — practice the format)</p>
                        <p class="guide-line"><em>Q:</em> A garden is 14 m long and 9 m wide. Find its perimeter.</p>
                        <p class="guide-line"><strong>Q no:</strong> Sample</p>
                        <p class="guide-line"><strong>1. Given:</strong> Length = 14 m, Breadth = 9 m</p>
                        <p class="guide-line"><strong>2. To find:</strong> Perimeter</p>
                        <p class="guide-line"><strong>3. Solution:</strong> P = 2 × (14 + 9) = 2 × 23 = 46</p>
                        <p class="guide-line"><strong>4. Answer:</strong> 46 m</p>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <p class="footer">
        Name: ______________________ &nbsp; Date: ____________ &nbsp; Sheet: {{ $worksheet->set_code }}
        @if ($isCongruence)
            &nbsp; Congruence format · 2 sums / page
        @else
            &nbsp; Answers on separate sheet (Q1, Q2, …)
        @endif
    </p>
</body>
</html>

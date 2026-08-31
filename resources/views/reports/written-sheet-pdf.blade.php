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
            margin: 0 0 8px;
            padding: 5px 7px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            font-size: 8.5px;
            line-height: 1.35;
        }
        .questions { margin: 0; padding: 0; }
        .question { margin: 0 0 5px; padding: 0; page-break-inside: auto; }
        .q-head { font-weight: bold; margin: 0; }
        .diagram { max-width: 100%; width: 320px; max-height: 180px; margin: 4px 0 2px; display: block; object-fit: contain; }
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
            margin-top: 10px;
            padding-top: 8px;
            border-top: 2px solid #4338ca;
            page-break-before: always;
        }
        .answer-guide h2 {
            font-size: 12px;
            margin: 0 0 6px;
            color: #312e81;
        }
        .answer-guide p {
            margin: 0 0 6px;
            font-size: 9px;
            line-height: 1.45;
        }
        .format-box {
            margin: 6px 0 10px;
            padding: 7px 9px;
            border: 1px dashed #6366f1;
            background: #eef2ff;
            font-size: 9px;
            line-height: 1.55;
        }
        .format-box .line { margin: 0 0 3px; }
        .format-box .qno { font-weight: bold; margin-bottom: 5px; }
        .sample-box {
            margin-top: 8px;
            padding: 8px 9px;
            border: 1px solid #94a3b8;
            background: #f8fafc;
            font-size: 9px;
            line-height: 1.5;
        }
        .sample-box h3 {
            font-size: 10px;
            margin: 0 0 6px;
            color: #0f172a;
        }
        .sample-box .label { font-weight: bold; }
    </style>
</head>
<body>
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

    <p class="instructions">
        <strong>How to answer:</strong> Write each answer on a separate sheet in order — Q1, then Q2, then Q3, … (one below the other). Use the answer format at the <strong>end of this sheet</strong> (Q no, Given, To find, Solution, Answer). Upload photo(s) in page order for AI checking.
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
        <h2>Guidance — how to write your answers</h2>
        <p>
            Please solve <strong>each sum</strong> on your answer sheet using the headings below.
            Write one complete solution under the other (Q1, then Q2, then Q3, …).
        </p>

        <div class="format-box">
            <div class="qno">Q no: ______</div>
            <div class="line"><strong>1. Given:</strong> …</div>
            <div class="line"><strong>2. To find:</strong> …</div>
            <div class="line"><strong>3. Solution:</strong> … (show working step by step)</div>
            <div class="line"><strong>4. Answer:</strong> … (final value with units if needed)</div>
        </div>

        <div class="sample-box">
            <h3>Sample (practice format — this sum is NOT on your sheet)</h3>
            <p class="label">Question:</p>
            <p>A rectangular garden is 14 m long and 9 m wide. Find its perimeter.</p>
            <p class="label" style="margin-top: 6px;">Your answer sheet should look like this:</p>
            <p><strong>Q no:</strong> Sample</p>
            <p><strong>1. Given:</strong> Length = 14 m, Breadth = 9 m</p>
            <p><strong>2. To find:</strong> Perimeter of the rectangle</p>
            <p><strong>3. Solution:</strong> Perimeter = 2 × (length + breadth) = 2 × (14 + 9) = 2 × 23 = 46</p>
            <p><strong>4. Answer:</strong> 46 m</p>
        </div>

        <p style="margin-top: 8px;">
            Follow this same pattern for every question on this worksheet. Neat steps help your teacher and AI checking.
        </p>
    </div>

    <p class="footer">
        Name: ______________________ &nbsp; Date: ____________ &nbsp; Sheet: {{ $worksheet->set_code }} &nbsp; Answers on separate sheet (Q1, Q2, …)
    </p>
</body>
</html>

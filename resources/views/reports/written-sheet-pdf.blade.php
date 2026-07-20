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
        .diagram { max-width: 160px; max-height: 100px; margin: 2px 0; display: block; }
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
        <strong>How to answer:</strong> Questions only — write each answer on a separate sheet labelled Q1, Q2, Q3, … then upload a photo for AI checking.
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

    <p class="footer">
        Name: ______________________ &nbsp; Date: ____________ &nbsp; Sheet: {{ $worksheet->set_code }} &nbsp; Answers on separate sheet (Q1, Q2, …)
    </p>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $worksheet->set_code }} — Written {{ $kindLabel }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { font-size: 11px; color: #374151; margin-bottom: 16px; }
        .question { margin-bottom: 18px; page-break-inside: avoid; }
        .q-head { font-weight: bold; margin-bottom: 6px; }
        .diagram { max-width: 220px; max-height: 160px; margin: 6px 0; }
        .options { margin: 6px 0 0 18px; }
        .option { margin: 2px 0; }
        .work-space { border: 1px dashed #cbd5e1; height: 70px; margin-top: 8px; }
        .footer { margin-top: 24px; font-size: 10px; color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ $worksheet->set_code }} — Written {{ $kindLabel }}</h1>
    <p class="meta">
        @if ($className) <strong>Class:</strong> {{ $className }} · @endif
        @if ($boardCode) <strong>Board:</strong> {{ $boardCode }} · @endif
        @if ($chapterName) <strong>Chapter:</strong> {{ $chapterName }} · @endif
        @if ($topicName) <strong>Topic:</strong> {{ $topicName }} · @endif
        <strong>Sums:</strong> {{ count($questions) }}
    </p>

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
            <div class="work-space"></div>
        </div>
    @endforeach

    <p class="footer">Name: _____________________________ &nbsp;&nbsp; Date: _______________ &nbsp;&nbsp; Sheet: {{ $worksheet->set_code }}</p>
</body>
</html>

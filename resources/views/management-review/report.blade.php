{{-- Ledelsens gjennomgåelse as a PDF (ManagementReviewReportController::pdf). Rendered by dompdf from
     ManagementReviewReportBuilder::document() — the same document as the print view, already narrowed
     to what the reader may see. DejaVu Sans carries æ, ø and å. --}}
@php($labels = $document['labels'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<title>{{ $document['title'] }}</title>
<style>
    @page { margin: 22mm 18mm 20mm 18mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #0f172a; line-height: 1.45; }
    h1 { font-size: 18pt; margin: 0 0 4pt 0; }
    h2 { font-size: 13pt; margin: 18pt 0 6pt 0; border-bottom: 1px solid #cbd5e1; padding-bottom: 3pt; }
    h3 { font-size: 11pt; margin: 12pt 0 4pt 0; }
    p { margin: 0 0 6pt 0; }
    .muted { color: #475569; }
    .draft { color: #b45309; font-weight: bold; font-size: 12pt; }
    .note { background: #f1f5f9; padding: 6pt 8pt; margin: 6pt 0; }
    table { width: 100%; border-collapse: collapse; margin: 4pt 0 8pt 0; }
    th, td { text-align: left; vertical-align: top; padding: 3pt 4pt; border-bottom: 1px solid #e2e8f0; font-size: 9pt; }
    th { color: #334155; }
    .meta td { border: none; padding: 1pt 4pt 1pt 0; font-size: 10pt; }
    .meta td.label { width: 34%; color: #475569; }
    .judgement { font-weight: bold; }
    .section { page-break-inside: auto; }
    .pre { white-space: pre-wrap; }
</style>
</head>
<body>
    @if ($document['is_draft'])
        <p class="draft">{{ $labels['draft_banner'] }}</p>
    @endif
    <h1>{{ $document['title'] }}</h1>
    <p class="muted">{{ $document['status'] }} · {{ $document['basis_note'] }}</p>

    <table class="meta">
        @foreach ($document['meta'] as [$label, $value])
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    @if ($document['restricted_note'])
        <p class="note">{{ $document['restricted_note'] }}</p>
    @endif

    @if ($document['purpose'])
        <h2>{{ $labels['purpose'] }}</h2>
        <p class="pre">{{ $document['purpose'] }}</p>
    @endif

    <h2>{{ $labels['participants'] }}</h2>
    @forelse ($document['participants'] as $participant)
        <p>{{ $participant['name'] }}@if ($participant['role_label']) – {{ $participant['role_label'] }}@endif</p>
    @empty
        <p class="muted">{{ $labels['none'] }}</p>
    @endforelse

    <h2>{{ $labels['conclusion'] }}</h2>
    <p class="pre">{{ $document['conclusion'] ?: $labels['none'] }}</p>

    @foreach ($document['sections'] as $section)
        <div class="section">
            <h2>{{ $section['title'] }}</h2>
            @if ($section['state_text'])<p class="muted">{{ $section['state_text'] }}</p>@endif
            @if ($section['coverage_text'])<p class="muted">{{ $section['coverage_text'] }}</p>@endif
            @if ($section['notes'])<p class="pre">{{ $section['notes'] }}</p>@endif

            @if ($section['basis'] && ! $section['basis']['empty'])
                @foreach ($section['basis']['groups'] as $group)
                    <h3>{{ $group['label'] }}</h3>
                    <table>
                        @foreach ($group['metrics'] as $metric)
                            <tr><td>{{ $metric['label'] }}</td><td style="width: 18%; text-align: right;">{{ $metric['value'] }}</td></tr>
                        @endforeach
                    </table>
                @endforeach
                @foreach ($section['basis']['lists'] as $list)
                    @if (count($list['rows']) > 0)
                        <h3>{{ $list['label'] }} ({{ $list['total'] }})</h3>
                        <table>
                            <tr>
                                <th>{{ $labels['item'] }}</th>
                                @foreach ($list['columns'] as $column)<th>{{ $column['label'] }}</th>@endforeach
                            </tr>
                            @foreach ($list['rows'] as $row)
                                <tr>
                                    <td>{{ $row['title'] }}</td>
                                    @foreach ($list['columns'] as $column)<td>{{ $row['cells'][$column['key']] ?? '' }}</td>@endforeach
                                </tr>
                                @if (! empty($row['case']))
                                    <tr><td colspan="{{ count($list['columns']) + 1 }}" class="muted">{{ $labels['case'] }}: {{ $row['case']['title'] }} – {{ implode(' · ', array_filter($row['case']['cells'])) }}</td></tr>
                                @elseif (! empty($row['case_hidden']))
                                    <tr><td colspan="{{ count($list['columns']) + 1 }}" class="muted">{{ $labels['case_hidden'] }}</td></tr>
                                @endif
                            @endforeach
                        </table>
                        @if ($list['total'] > $list['shown'])<p class="muted">{{ str_replace([':shown', ':total'], [$list['shown'], $list['total']], $labels['truncated']) }}</p>@endif
                    @endif
                @endforeach
                @foreach ($section['basis']['notes'] as $note)<p class="muted">{{ $note }}</p>@endforeach
            @endif

            <p><span class="judgement">{{ $labels['judgement'] }}:</span> {{ $section['judgement'] ?? $labels['not_judged'] }}</p>
            @if ($section['comment'])<p class="pre">{{ $section['comment'] }}</p>@endif
        </div>
    @endforeach

    <h2>{{ $labels['decisions'] }}</h2>
    @if (count($document['decisions']) === 0)
        <p class="muted">{{ $labels['none'] }}</p>
    @else
        <table>
            <tr><th>{{ $labels['type'] }}</th><th>{{ $labels['decision'] }}</th><th>{{ $labels['owner'] }}</th><th>{{ $labels['due'] }}</th><th>{{ $labels['follow_up'] }}</th></tr>
            @foreach ($document['decisions'] as $decision)
                <tr>
                    <td>{{ $decision['kind'] }}</td>
                    <td>{{ $decision['text'] }}@if ($decision['section'])<br><span class="muted">{{ $decision['section'] }}</span>@endif</td>
                    <td>{{ $decision['owner'] }}</td>
                    <td>{{ $decision['due_date'] }}</td>
                    <td>{{ $decision['follow_up'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @foreach ($document['frameworks'] as $framework)
        <h2>{{ $framework['name'] }}</h2>
        @if ($framework['coverage'] === 'none')
            <p class="note">{{ $labels['framework_no_coverage'] }}</p>
        @else
            <p class="note">{{ $labels['framework_disclaimer'] }}</p>
            <table>
                @foreach ($framework['inputs'] as $input)
                    <tr><td style="width: 18%;">{{ $input['clause'] }}</td><td>{{ $input['label'] }}</td><td style="width: 22%;">{{ $input['state'] }}</td></tr>
                @endforeach
            </table>
        @endif
    @endforeach

    @if (count($document['amendments']) > 0)
        <h2>{{ $labels['amendments'] }}</h2>
        @foreach ($document['amendments'] as $amendment)
            <p class="pre">{{ $amendment['text'] }}</p>
            <p class="muted">{{ $labels['reason'] }}: {{ $amendment['reason'] }} · {{ $amendment['by'] }} · {{ $amendment['at'] }}</p>
        @endforeach
    @endif

    <h2>{{ $labels['history'] }}</h2>
    <table>
        @foreach ($document['history'] as $event)
            <tr><td style="width: 26%;">{{ $event['at'] }}</td><td>{{ $event['event'] }}</td><td>{{ $event['by'] }}</td></tr>
        @endforeach
    </table>

    <p class="muted">{{ $labels['generated'] }} {{ $document['generated_at'] }}</p>
</body>
</html>

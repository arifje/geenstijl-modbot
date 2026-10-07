@php($tz = config('modbot.geenstijl.timezone'))
<!doctype html>
<html lang="nl">
<body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="680" cellspacing="0" cellpadding="0" style="width:100%;max-width:680px;background:#ffffff;border-radius:12px;overflow:hidden;">
    <tr><td style="background:#0f172a;padding:28px 32px;color:#ffffff;">
        <div style="font-size:24px;font-weight:700;line-height:1.25;">GeenStijl Moderatierapport</div>
        <div style="margin-top:8px;font-size:14px;color:#cbd5e1;">
            {{ $report->scope() }} · {{ $report->commentsCount }} reacties beoordeeld van {{ $report->articlesCount }} artikelen
        </div>
    </td></tr>
    <tr><td style="padding:30px 32px;font-size:15px;line-height:1.65;">
        <p style="margin:0 0 8px;font-size:16px;"><strong>{{ $report->items->count() }}</strong> reacties vereisen moderatie</p>
        @if ($report->failedCount > 0)
            <p style="margin:0 0 8px;color:#b45309;">{{ $report->failedCount }} reacties konden niet worden geanalyseerd</p>
        @endif
        @if ($report->syncFailedCount > 0)
            <p style="margin:0 0 8px;color:#b45309;">Reacties van {{ $report->syncFailedCount }} artikelen konden niet worden opgehaald</p>
        @endif

        <h2 style="font-size:18px;color:#0f172a;margin:24px 0 8px;">Sfeer en sentiment</h2>
        <p style="margin:0;">
            {{ $report->commentsCount === 0 ? 'Geen recente reacties beschikbaar voor beoordeling.' : ($report->sentiment ?? 'Geen samenvatting beschikbaar.') }}
        </p>

        <h2 style="font-size:18px;color:#0f172a;margin:28px 0 8px;">Reacties ter beoordeling</h2>
        @forelse ($report->groups() as $group)
            <div style="margin:20px 0 10px;padding:10px 14px;background:#f1f5f9;border-radius:8px;">
                <a href="{{ $group['article']->url() }}" style="color:#0f172a;font-weight:700;text-decoration:none;">📰 {{ $group['article']->title }}</a>
                <span style="color:#64748b;font-size:13px;"> · {{ $group['items']->count() }} {{ $group['items']->count() === 1 ? 'reactie' : 'reacties' }}</span>
            </div>
            @foreach ($group['items'] as $verdict)
                <div style="border-left:4px solid {{ $verdict->priority->value === 'high' ? '#dc2626' : '#f59e0b' }};padding:8px 0 8px 14px;margin:0 0 16px;overflow-wrap:anywhere;">
                    <div style="font-weight:700;">
                        {{ $verdict->priority->emoji() }} {{ $verdict->priority->label() }} · {{ $verdict->category->label() }} · {{ $verdict->comment->displayName() }} · {{ $verdict->comment->posted_at->timezone($tz)->format('d/m H:i') }}
                    </div>
                    <div style="margin-top:4px;">“{{ $verdict->quote }}”</div>
                    <div style="margin-top:4px;color:#475569;font-style:italic;">{{ $verdict->reason }}</div>
                    <div style="margin-top:4px;"><a href="{{ $verdict->comment->url() }}" style="color:#2563eb;">Bewerk reactie</a></div>
                </div>
            @endforeach
        @empty
            <p style="margin:0;">Geen reacties gevonden die moderatie vereisen.</p>
        @endforelse
        @if ($report->hiddenCount() > 0)
            <p style="margin:0;">Nog {{ $report->hiddenCount() }} reacties vereisen beoordeling.</p>
        @endif
    </td></tr>
    <tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
        Automatisch gegenereerd door GeenStijl ModBot.
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>

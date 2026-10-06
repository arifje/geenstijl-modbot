@php($tz = config('modbot.geenstijl.timezone'))
GeenStijl {!! lcfirst($report->title()) !!}

{!! $report->commentsCount !!} reacties beoordeeld van {!! $report->articlesCount !!} artikelen
{!! $report->items->count() !!} reacties vereisen moderatie
@if ($report->failedCount > 0)
{!! $report->failedCount !!} reacties konden niet worden geanalyseerd
@endif
@if ($report->syncFailedCount > 0)
Reacties van {!! $report->syncFailedCount !!} artikelen konden niet worden opgehaald
@endif

SFEER EN SENTIMENT
{!! $report->commentsCount === 0 ? 'Geen recente reacties beschikbaar voor beoordeling.' : ($report->sentiment ?? 'Geen samenvatting beschikbaar.') !!}

REACTIES TER BEOORDELING
@forelse ($report->groups() as $group)

== {!! $group['article']->title !!} ({!! $group['items']->count() !!}) ==
{!! $group['article']->url() !!}
@foreach ($group['items'] as $verdict)

{!! $verdict->priority->label() !!} · {!! $verdict->category->label() !!} · {!! $verdict->comment->displayName() !!} · {!! $verdict->comment->posted_at->timezone($tz)->format('d/m H:i') !!}
"{!! $verdict->quote !!}"
{!! $verdict->reason !!}
{!! $verdict->comment->url() !!}
@endforeach
@empty
Geen reacties gevonden die moderatie vereisen.
@endforelse
@if ($report->hiddenCount() > 0)

Nog {!! $report->hiddenCount() !!} reacties vereisen beoordeling.
@endif

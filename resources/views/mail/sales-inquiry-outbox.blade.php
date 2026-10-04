Hallo {{ $recipientName }},

{{ $payload['event_label'] }}

Dispoauftrag: {{ $payload['order_number'] }}
@if (! empty($payload['customer_name']))
Kunde: {{ $payload['customer_name'] }}
@endif
@if (! empty($payload['campaign']))
Kampagne: {{ $payload['campaign'] }}
@endif
Auslöser: {{ $payload['actor_name'] }}
Zeitpunkt: {{ $payload['occurred_at'] }}

Interner Link:
{{ $payload['internal_url'] }}
@if (($eventType ?? '') === 'dispo_order.approval.rejected')

Die Ablehnungsbegründung ist in der Anwendung einsehbar. Bitte den Dispoauftrag über den internen Link öffnen.
@endif

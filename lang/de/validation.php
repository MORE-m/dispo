<?php

return [
    'accepted' => ':Attribute muss akzeptiert werden.',
    'required' => ':Attribute ist erforderlich.',
    'numeric' => ':Attribute muss eine Zahl sein.',
    'integer' => ':Attribute muss eine ganze Zahl sein.',
    'min' => [
        'numeric' => ':Attribute muss mindestens :min sein.',
        'integer' => ':Attribute muss mindestens :min sein.',
        'string' => ':Attribute muss mindestens :min Zeichen haben.',
    ],
    'max' => [
        'numeric' => ':Attribute darf maximal :max sein.',
        'integer' => ':Attribute darf maximal :max sein.',
        'string' => ':Attribute darf maximal :max Zeichen haben.',
    ],
    'attributes' => [
        'target_budget_nn' => 'Zielbudget N/N',
        'budget_elements' => 'Budget-Werbeelemente',
        'budget_elements.*.inventory_id' => 'Sender',
        'budget_elements.*.spot_length_seconds' => 'Spotlänge im Budget-Werbeelement',
        'positions' => 'Werbeelemente',
        'positions.*.length_seconds' => 'Spotlänge',
        'positions.*.total_spot_count' => 'Gesamtspotzahl',
        'positions.*.time_ranges.*.spot_count' => 'Spotanzahl im Preiszeitraum',
        'positions.*.time_ranges.*.start_hour' => 'Beginn im Preiszeitraum',
        'positions.*.time_ranges.*.end_hour_exclusive' => 'Ende im Preiszeitraum',
        'positions.*.position_discounts.*.percent' => 'Positionsrabatt',
        'order_discounts.*.percent' => 'Auftragsrabatt',
    ],
];

<?php

// Blocks of the internal flow engine (Blocks/, Services/Engine). Titles are composed when shown, never stored.
return [
    'families'  => [
        'trigger'   => 'Triggers',
        'condition' => 'Voorwaarden',
        'wait'      => 'Wachttijden',
        'action'    => 'Acties',
        'util'      => 'Hulpmiddelen',
    ],
    'outputs'   => [
        'next'  => 'verder',
        'yes'   => 'ja',
        'no'    => 'nee',
        'error' => 'fout',
    ],
    'already'   => 'al gedaan, niets te wijzigen',
    'trigger'   => [
        'new_mail' => [
            'name'  => 'Nieuwe e-mail',
            'title' => 'Nieuwe e-mail',
            'help'  => 'Een klant schrijft een nieuwe e-mail in een mailbox van de stroom.',
        ],
    ],
    'condition' => [
        'ticket' => [
            'name'       => 'Als…',
            'title'      => 'Als :var :operator „:value”',
            'title_bool' => 'Als :var: :operator',
            'decision'   => ':var „:actual” :operator „:value” → :output',
            'help'       => 'Test het ticket of het resultaat van een eerder blok.',
        ],
    ],
    'wait'      => [
        'duration' => [
            'name'  => 'Wachten',
            'title' => ':delay wachten',
            'help'  => 'Het ticket wacht en gaat dan verder.',
        ],
    ],
    'action'    => [
        'status'      => [
            'name'  => 'Status',
            'title' => 'Status: :status',
            'help'  => 'Wijzigt de status van het ticket.',
        ],
        'assign'      => [
            'name'  => 'Toewijzen',
            'title' => 'Toewijzen aan :user',
            'help'  => 'Wijst het ticket toe aan een gebruiker van de mailbox, of aan niemand.',
        ],
        'note'        => [
            'name'  => 'Interne notitie',
            'title' => 'Notitie: :text',
            'help'  => 'Voegt een interne notitie toe; {{ticket.subject}} wordt vervangen.',
        ],
        'imap_folder' => [
            'name'  => 'In een map opbergen',
            'title' => 'Map: :folder',
            'help'  => 'Bergt de e-mail op in een map van de mailserver.',
        ],
    ],
    'vars'      => [
        'ticket' => [
            'subject'  => 'Onderwerp',
            'body'     => 'Tekst van de e-mail',
            'from'     => 'Afzender',
            'status'   => 'Status',
            'assigned' => 'Toegewezen',
            'replied'  => 'Het team heeft geantwoord',
        ],
    ],
    'ops'       => [
        'contains'     => 'bevat',
        'not_contains' => 'bevat niet',
        'equal'        => 'is gelijk aan',
        'not_equal'    => 'verschilt van',
        'starts'       => 'begint met',
        'ends'         => 'eindigt op',
        'regex'        => 'komt overeen met de expressie',
        'is'           => 'is',
        'is_not'       => 'is niet',
        'yes'          => 'ja',
        'no'           => 'nee',
        'gte'          => '≥',
        'lte'          => '≤',
        'eq'           => '=',
    ],
    'values'    => [
        'status' => [
            'active'  => 'Actief',
            'pending' => 'In afwachting',
            'closed'  => 'Gesloten',
            'spam'    => 'Spam',
        ],
        'nobody' => 'niemand',
    ],
    'errors'    => [
        'required'     => 'Verplicht.',
        'choice'       => 'Waarde niet toegestaan.',
        'id'           => 'Ongeldig of dubbel blok-id.',
        'link'         => 'Ongeldige koppeling (onbekend blok of uitgang al gekoppeld).',
        'count'        => 'Een stroom heeft 1 tot :max blokken.',
        'type'         => 'Onbekend bloktype: „:type”.',
        'var'          => 'Deze variabele wordt door geen eerder blok geleverd.',
        'trigger'      => 'Een stroom heeft precies één trigger.',
        'port'         => 'Dit blok heeft geen uitgang „:port”.',
        'into_trigger' => 'Een koppeling kan niet naar een trigger gaan.',
        'unreached'    => 'Dit blok is niet met de trigger gekoppeld.',
        'loop'         => 'Lus zonder wachttijd: die zou nooit eindigen.',
        'steps'        => 'Gestopt na :max blokken achter elkaar (lus?).',
        'internal'     => 'Interne fout, later opnieuw geprobeerd.',
        'delay'        => 'Ongeldige duur (1 tot 100.000 minuten, uren of dagen).',
        'regex'        => 'Ongeldige of te lange expressie (hoogstens :max tekens).',
        'long'         => 'Tekst te lang (hoogstens :max bytes).',
        'user'         => 'Deze gebruiker heeft geen toegang meer tot de mailbox.',
        'folder'       => 'Ongeldige mapnaam.',
        'imap_off'     => 'Optie „In een e-mailmap opbergen” staat uit (Beheren › Instellingen › Workflow Packs).',
        'imap_nothing' => 'Geen e-mail om op te bergen (zie de geschiedenis).',
    ],
];

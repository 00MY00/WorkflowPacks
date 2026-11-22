<?php

// Messages of the services (state of the module, e-mail folders, delays). Reference catalogue: also used without Laravel.
return [
    // state of the module (Diagnostic, workflowpacks:check)
    'chk_core_detail'          => 'version minimale testée : 1.8.0',
    'chk_php_detail'           => 'minimum de FreeScout : 7.1',
    'chk_db'                   => 'Tables de Workflow Packs',
    'chk_db_missing'           => 'manquantes : :tables — lancez php artisan migrate',
    'chk_tags'                 => 'Module Tags',
    'chk_refresh'              => 'Style Refresh',
    'cmd_state'                => 'État : :state',
    'cmd_pack_valid'           => 'Pack valide (structure).',
    'cmd_pack_invalid'         => 'Pack invalide :',
    'raw'                      => ':text',
    'imap_folder_empty'        => 'dossier e-mail : nom vide.',
    'imap_folder_chars'        => 'dossier e-mail : caractères interdits (* % " ou contrôle, ou nom commençant par un point).',
    'imap_folder_long'         => 'dossier e-mail : nom trop long (60 caractères au plus par niveau).',
    'imap_folder_deep'         => 'dossier e-mail : 4 niveaux au plus (« A/B/C/D »).',
    'timer_i'                  => ':count minute(s)',
    'timer_h'                  => ':count heure(s)',
    'timer_d'                  => ':count jour(s)',
    'timer_shape'              => 'délai de « :type » : il faut un nombre et une unité ({ "number": 30, "metric": "d" }).',
    'timer_number'             => 'délai : un nombre entier de 1 à 100000.',
    'timer_metric'             => 'délai : unité i (minutes), h (heures) ou d (jours).',
    'retention_mode'           => 'nettoyage : mode « trash » (corbeille) ou « delete » (définitif).',
    // optional modules (state of the module)
    'chk_refreshglobal' => 'Module RefreshGlobal (étiquettes e-mail)',
    'chk_webpush'       => 'Module WebPush (notifications push)',
    'chk_module_on'     => 'actif',
    'chk_module_off'    => 'absent ou désactivé (facultatif)',
];

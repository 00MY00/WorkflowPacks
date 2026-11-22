<?php

use Modules\WorkflowPacks\Services\Imap\FolderPath;

test('Dossiers e-mail : chemin écrit dans la règle -> niveaux sur le serveur, sous le préfixe', function () {
    same(['segments' => ['Factures', 'Fournisseurs'], 'error' => null], FolderPath::parse(' Factures / Fournisseurs/ '));
    same(['[WP] Factures', '[WP] Factures/Fournisseurs'], FolderPath::levels(['Factures', 'Fournisseurs'], '[WP]'));
    // délimiteur "." et espace de noms INBOX (Dovecot, Courier) ; le délimiteur ne peut pas apparaître dans un nom
    same(['INBOX.[WP] Factures', 'INBOX.[WP] Factures.2026-10'], FolderPath::levels(['Factures', '2026.10'], '[WP]', '.', 'INBOX'));
    same('[WP] Factures › Fournisseurs', FolderPath::label('Factures/Fournisseurs', '[WP]'));
    check(FolderPath::isManaged('[WP] Factures/Fournisseurs', '[WP]'), 'dossier géré');
    check(!FolderPath::isManaged('Factures', '[WP]'), 'dossier de l’utilisateur jamais touché');
    check(FolderPath::isManaged('INBOX.[WP] Factures', '[WP]', '.', 'INBOX'), 'sous INBOX');
});

test('Dossiers e-mail : noms refusés (vide, jokers IMAP, contrôle, point initial, longueur, profondeur)', function () {
    foreach (['' => 'imap_folder_empty', ' / ' => 'imap_folder_empty', 'Fact*' => 'imap_folder_chars', 'A%' => 'imap_folder_chars',
        'a"b' => 'imap_folder_chars', "a\x01b" => 'imap_folder_chars', '.cache' => 'imap_folder_chars', 'a\\b' => 'imap_folder_chars',
        str_repeat('x', 61) => 'imap_folder_long', 'a/b/c/d/e' => 'imap_folder_deep'] as $value => $error) {
        same($error, FolderPath::parse((string) $value)['error'], json_encode($value));
    }
    same('imap_folder_empty', FolderPath::parse(['x'])['error']);
    same(null, FolderPath::parse('Clients/Société & Fils/2026.10')['error'], 'accents, & et points internes acceptés');
    same(['a b'], FolderPath::parse("a\n\tb")['segments'], 'retours à la ligne et tabulations ramenés à une espace');
});

test('Dossiers e-mail : préfixe des réglages nettoyé, [WP] par défaut', function () {
    same('[WP]', FolderPath::prefix(''));
    same('[WP]', FolderPath::prefix('***'));
    same('[Tri] Auto', FolderPath::prefix(' [Tri] Auto/* '));
    same(20, mb_strlen(FolderPath::prefix(str_repeat('A', 40))));
});

test('Dossier actuel des tickets : dossier de la règle retrouvé depuis le chemin du serveur (historique d’avant 1.14.0)', function () {
    $v = function ($path) { return \Modules\WorkflowPacks\Services\Imap\TicketFolders::valueFromPath($path, '[WP]'); };
    same('Factures/Fournisseurs', $v('[WP] Factures/Fournisseurs'), 'délimiteur /');
    same('Factures/Fournisseurs', $v('INBOX.[WP] Factures.Fournisseurs'), 'délimiteur . sous INBOX (Courier)');
    same('Factures/Fournisseurs', $v('[WP] Factures › Fournisseurs'), 'libellé de l’historique');
    same('Newsletters', $v('[WP] Newsletters'), 'un seul niveau');
    same('Mr. Smith', $v('[WP] Mr. Smith'), 'point dans un nom, délimiteur /');
    same(null, $v('Factures'), 'dossier de l’utilisateur : rien');
    same(null, $v(''), 'vide');
});
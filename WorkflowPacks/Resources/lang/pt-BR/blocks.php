<?php

// Blocks of the internal flow engine (Blocks/, Services/Engine). Titles are composed when shown, never stored.
return [
    'families'  => [
        'trigger'   => 'Gatilhos',
        'condition' => 'Condições',
        'wait'      => 'Esperas',
        'action'    => 'Ações',
        'util'      => 'Ferramentas',
    ],
    'outputs'   => [
        'next'  => 'seguinte',
        'yes'   => 'sim',
        'no'    => 'não',
        'error' => 'erro',
    ],
    'already'   => 'já feito, nada a mudar',
    'trigger'   => [
        'new_mail' => [
            'name'  => 'Novo e-mail',
            'title' => 'Novo e-mail',
            'help'  => 'Um cliente escreve um novo e-mail em uma caixa do fluxo.',
        ],
    ],
    'condition' => [
        'ticket'  => [
            'name'       => 'Se…',
            'title'      => 'Se :var :operator “:value”',
            'title_bool' => 'Se :var: :operator',
            'decision'   => ':var “:actual” :operator “:value” → :output',
            'help'       => 'Testa o ticket ou o resultado de um bloco anterior.',
        ],
        'similar' => [
            'name'     => 'Parece com…',
            'title'    => 'Parece com :count exemplo(s) (≥ :threshold %)',
            'decision' => 'Semelhança :score % (assunto :subject %, texto :body %), limiar :threshold % → :output',
            'help'     => 'Compara o ticket com tickets de exemplo (seus ids), localmente.',
        ],
    ],
    'wait'      => [
        'duration' => [
            'name'  => 'Aguardar',
            'title' => 'Aguardar :delay',
            'help'  => 'O ticket aguarda e depois continua.',
        ],
    ],
    'action'    => [
        'status'      => [
            'name'  => 'Status',
            'title' => 'Status: :status',
            'help'  => 'Muda o status do ticket.',
        ],
        'assign'      => [
            'name'  => 'Atribuir',
            'title' => 'Atribuir a :user',
            'help'  => 'Atribui o ticket a um usuário da caixa, ou a ninguém.',
        ],
        'note'        => [
            'name'  => 'Nota interna',
            'title' => 'Nota: :text',
            'help'  => 'Adiciona uma nota interna; {{ticket.subject}} é substituído.',
        ],
        'imap_folder' => [
            'name'  => 'Arquivar em uma pasta',
            'title' => 'Pasta: :folder',
            'help'  => 'Arquiva o e-mail em uma pasta do servidor de e-mail.',
        ],
    ],
    'vars'      => [
        'ticket'  => [
            'subject'  => 'Assunto',
            'body'     => 'Texto do e-mail',
            'from'     => 'Remetente',
            'status'   => 'Status',
            'assigned' => 'Atribuído',
            'replied'  => 'A equipe respondeu',
        ],
        'similar' => [
            'score' => 'Semelhança (%)',
        ],
    ],
    'ops'       => [
        'contains'     => 'contém',
        'not_contains' => 'não contém',
        'equal'        => 'é igual a',
        'not_equal'    => 'é diferente de',
        'starts'       => 'começa com',
        'ends'         => 'termina com',
        'regex'        => 'corresponde à expressão',
        'is'           => 'é',
        'is_not'       => 'não é',
        'yes'          => 'sim',
        'no'           => 'não',
        'gte'          => '≥',
        'lte'          => '≤',
        'eq'           => '=',
    ],
    'values'    => [
        'status' => [
            'active'  => 'Ativo',
            'pending' => 'Pendente',
            'closed'  => 'Fechado',
            'spam'    => 'Spam',
        ],
        'nobody' => 'ninguém',
    ],
    'errors'    => [
        'required'     => 'Obrigatório.',
        'choice'       => 'Valor não permitido.',
        'id'           => 'Identificador de bloco inválido ou duplicado.',
        'link'         => 'Ligação inválida (bloco desconhecido ou saída já ligada).',
        'count'        => 'Um fluxo tem de 1 a :max blocos.',
        'type'         => 'Tipo de bloco desconhecido: “:type”.',
        'var'          => 'Esta variável não é fornecida por um bloco anterior.',
        'trigger'      => 'Um fluxo tem exatamente um gatilho.',
        'port'         => 'Este bloco não tem a saída “:port”.',
        'into_trigger' => 'Uma ligação não pode ir para um gatilho.',
        'unreached'    => 'Este bloco não está ligado ao gatilho.',
        'loop'         => 'Laço sem espera: nunca terminaria.',
        'steps'        => 'Parado após :max blocos seguidos (laço?).',
        'internal'     => 'Erro interno, nova tentativa mais tarde.',
        'delay'        => 'Duração inválida (1 a 100.000 minutos, horas ou dias).',
        'regex'        => 'Expressão inválida ou longa demais (no máximo :max caracteres).',
        'long'         => 'Texto longo demais (no máximo :max bytes).',
        'user'         => 'Este usuário não tem mais acesso à caixa.',
        'folder'       => 'Nome de pasta inválido.',
        'imap_off'     => 'Opção “Arquivar em uma pasta de e-mail” desativada (Gerenciar › Configurações › Workflow Packs).',
        'imap_nothing' => 'Nenhum e-mail a arquivar (veja o histórico).',
        'examples'     => 'De 1 a :max ids de tickets de exemplo.',
        'percent'      => 'Porcentagem de 0 a 100.',
    ],
];

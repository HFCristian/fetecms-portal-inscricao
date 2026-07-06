<?php

return [
    /*
    | Fallback do alerta diário (07:00) de mensagens de suporte não respondidas.
    | O aviso vai para TODOS os administradores ativos; este endereço só é usado
    | caso não exista nenhum admin cadastrado.
    */
    'suporte_alerta_email' => env('SUPORTE_ALERTA_EMAIL', 'pedro.b.g.neto@ufms.br'),
];

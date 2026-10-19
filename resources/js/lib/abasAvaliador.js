/**
 * As abas do avaliador, na ordem do menu.
 *
 * Como as do admin ({@link ./abasAdmin.js}), a lista mora aqui porque a
 * **Home do avaliador** (`/avaliador`) desenha o mesmo recorte em forma de
 * botões: duas listas separadas divergiriam na primeira aba nova.
 *
 * A aba **Avaliação presencial** é uma só desde a Sprint 246: enquanto o
 * avaliador não diz "sim" e não é liberado pela organização, ela mostra a
 * pergunta e as orientações (o que era a aba "Presencial"); depois, a
 * avaliação no estande.
 */
const ABAS = [
    {
        chave: 'online',
        to: '/avaliador/avaliacao-online',
        icon: 'fact_check',
        label: 'Avaliação online',
        descricao: 'Os projetos da sua fila, a rubrica e o que você já avaliou.',
        // Conta só do evento presencial (Sprint 191): sem avaliação online.
        visivel: (perfil) => !perfil?.apenas_presencial,
    },
    {
        chave: 'presencial',
        to: '/avaliador/avaliacao-presencial',
        icon: 'rate_review',
        label: 'Avaliação presencial',
        descricao: 'Diga se vai avaliar no dia da feira e, liberado, avalie os estandes.',
        visivel: () => true,
    },
    {
        chave: 'comite',
        to: '/avaliador/comite',
        icon: 'workspace_premium',
        label: 'Comitê especial',
        descricao: 'Indique finalistas para as credenciais e os prêmios da feira.',
        // Só a comissão especial recomenda projetos para a premiação (Sprint 193).
        visivel: (perfil) => perfil?.comissao_especial === true,
    },
    {
        chave: 'perfil',
        to: '/avaliador/perfil',
        icon: 'account_circle',
        label: 'Perfil',
        descricao: 'Seus números, a carga horária do certificado e seus dados.',
        visivel: () => true,
    },
];

/** As abas que este avaliador vê, na ordem do menu. */
export function abasDoAvaliador(perfil) {
    return ABAS.filter((a) => a.visivel(perfil));
}

/**
 * A mesma lista, na ordem da Home: durante o evento, quem vai ao estande
 * encontra a avaliação presencial em primeiro lugar (o que era o desvio
 * automático da Sprint 236).
 */
export function abasDaHome(user) {
    const perfil = user?.avaliador_profile;
    const abas = abasDoAvaliador(perfil);
    const presencialPrimeiro = perfil?.apenas_presencial || (user?.evento_em_andamento && perfil?.presencial === true);

    if (!presencialPrimeiro) return abas.map((a) => ({ ...a, destaque: false }));

    return [
        ...abas.filter((a) => a.chave === 'presencial').map((a) => ({ ...a, destaque: true })),
        ...abas.filter((a) => a.chave !== 'presencial').map((a) => ({ ...a, destaque: false })),
    ];
}

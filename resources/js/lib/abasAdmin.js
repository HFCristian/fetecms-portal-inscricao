/**
 * As abas do admin, na ordem do menu.
 *
 * `aba` é a *rule* do RBAC (o enum `App\Enums\AbaAdmin` no backend): a pessoa
 * só vê as abas que algum dos escopos dela abre — e quem não tem escopo
 * atribuído na edição em curso recebe todas.
 *
 * A lista mora aqui, e não dentro do menu, porque a **Home do admin**
 * (`AdminInicio`) desenha exatamente o mesmo recorte em forma de botões: duas
 * listas separadas divergiriam na primeira aba nova.
 */
export const ABAS_ADMIN = [
    {
        aba: 'projetos',
        to: '/admin/projetos',
        icon: 'folder',
        label: 'Projetos',
        descricao: 'O painel da edição, os projetos por área e por localidade.',
    },
    {
        aba: 'dashboards',
        to: '/admin/dashboards',
        icon: 'insights',
        label: 'Dashboards',
        descricao: 'Os números da feira: projetos, pessoas, camisetas e localidades.',
    },
    {
        aba: 'avaliacao',
        to: '/admin/avaliacao',
        icon: 'grading',
        label: 'Avaliação online',
        descricao: 'Distribuição, avaliadores, ranking e listas finais.',
    },
    {
        aba: 'credenciamento',
        to: '/admin/credenciamento',
        icon: 'badge',
        label: 'Credenciamento',
        descricao: 'O balcão do evento: conferir documentos e credenciar finalistas.',
    },
    {
        aba: 'almoxarifado',
        to: '/admin/almoxarifado',
        icon: 'inventory_2',
        label: 'Almoxarifado',
        descricao: 'Guardar e devolver o material dos finalistas durante o evento.',
    },
    {
        aba: 'comite',
        to: '/admin/comite',
        icon: 'directions_bus',
        label: 'Comitê especial',
        descricao: 'O transporte das equipes e o mapa de quem está a caminho.',
    },
    {
        aba: 'comunicacao',
        to: '/admin/comunicacao',
        icon: 'campaign',
        label: 'Comunicação',
        descricao: 'Mala direta, avisos na tela e modelos de e-mail.',
    },
    {
        aba: 'suporte',
        to: '/admin/suporte',
        icon: 'forum',
        label: 'Suporte',
        descricao: 'A caixa de entrada do chat de orientadores e avaliadores.',
        badge: true,
    },
    {
        aba: 'parametrizacao',
        to: '/admin/parametrizacao',
        icon: 'tune',
        label: 'Parametrização',
        descricao: 'Edições, datas, áreas, escolas e escopos de acesso.',
    },
    {
        aba: 'administradores',
        to: '/admin/gerir-admins',
        icon: 'people',
        label: 'Administradores',
        descricao: 'Criar, desativar e definir o acesso de cada administrador.',
    },
    {
        aba: 'registros',
        to: '/admin/registros',
        icon: 'history',
        label: 'Registros',
        descricao: 'A trilha de auditoria completa do portal.',
    },
];

/**
 * Filtra as abas pelo que esta pessoa abre, **na ordem em que a lista vier**.
 *
 * A ordem é a de Parametrização → Ordem do menu, resolvida no backend
 * (`UserResource`): aqui a lista só é respeitada, e é por isso que o menu
 * lateral e a Home nunca divergem. Sem lista (payload antigo em cache), mostra
 * tudo na ordem de referência — o backend continua sendo quem barra de verdade.
 */
export function abasPermitidas(abas) {
    if (!Array.isArray(abas)) return ABAS_ADMIN;

    const porChave = new Map(ABAS_ADMIN.map((a) => [a.aba, a]));

    return abas.map((aba) => porChave.get(aba)).filter(Boolean);
}

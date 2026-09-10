import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([{ id: 1, nome: 'Ciências Agrárias' }])),
    loadSubareas: vi.fn(() => Promise.resolve([{ id: 5, nome: 'Agronomia' }])),
}));

const LINHAS = [
    {
        id: 1, titulo: 'Projeto X', area_id: 1, area: 'Ciências Agrárias', subarea: 'Agronomia',
        categoria: 'fetec_jr', categoria_label: 'FETEC Jr', realizadas: 2, em_avaliacao: 1, faltantes: 1,
        orientador: { id: 9, nome: 'Marta Antiga', email: 'marta@escola.test' },
        coorientador: { id: 3, nome: 'Caio Coorientador', email: 'caio@x.test', cpf: '52998224725', telefone: null },
        alunos: [
            { id: 11, nome: 'Ana Aluna', serie: '9º ano do Ensino Fundamental II' },
            { id: 12, nome: 'Bruno Aluno', serie: null },
        ],
    },
    {
        id: 2, titulo: 'Projeto Y', area_id: 2, area: 'Ciências Exatas', subarea: null,
        categoria: 'fetecms', categoria_label: 'FETECMS', realizadas: 0, em_avaliacao: 0, faltantes: 3,
        alunos: [],
    },
];

const META = {
    pagina_atual: 1, ultima_pagina: 1, total: 2, por_pagina: 50,
    areas: [{ id: 1, nome: 'Ciências Agrárias' }, { id: 2, nome: 'Ciências Exatas' }],
    categorias: [
        { value: 'fetec_jr', label: 'FETEC Jr' },
        { value: 'fetecms', label: 'FETECMS' },
        { value: 'fetecms_fundect', label: 'FETECMS FUNDECT' },
    ],
    ordenar: 'titulo', direcao: 'asc',
    min_por_projeto: 3,
    resumo_geral: { zero: 1, uma: 0, duas: 1, tres_ou_mais: 0, total: 2, completos: 0 },
    resumo_areas: [
        { area_id: 1, area: 'Ciências Agrárias', zero: 0, uma: 0, duas: 1, tres_ou_mais: 0, total: 1, completos: 0 },
        { area_id: 2, area: 'Ciências Exatas', zero: 1, uma: 0, duas: 0, tres_ou_mais: 0, total: 1, completos: 0 },
    ],
};

const getAvaliacaoProjetos = vi.fn(() => Promise.resolve({ data: LINHAS, meta: META }));
const exportarProjetosAvaliacaoCsv = vi.fn(() => Promise.resolve());
const designarProjeto = vi.fn(() => Promise.resolve({ data: { designadas: 1 }, meta: { message: '1 designação criada.' } }));
const buscarOrientadores = vi.fn(() => Promise.resolve([
    { id: 42, nome: 'João Novo', email: 'joao@escola.test' },
]));
const corrigirProjeto = vi.fn(() => Promise.resolve({ data: {}, meta: { message: 'Projeto atualizado (categoria).' } }));
// Com `comissao`, devolve só os membros da comissão especial.
const getOpcoesAvaliadores = vi.fn((comissao) => Promise.resolve(comissao
    ? [{ id: 20, nome: 'Zilda Rocha', area: 'Ciências Exatas' }, { id: 30, nome: 'Célia Prado', area: 'Ciências Humanas' }]
    : [
        { id: 10, nome: 'Ana Lima', area: 'Ciências Agrárias' },
        { id: 20, nome: 'Zilda Rocha', area: 'Ciências Exatas' },
    ]));

vi.mock('../lib/admin.js', () => ({
    getAvaliacaoProjetos: (...a) => getAvaliacaoProjetos(...a),
    exportarProjetosAvaliacaoCsv: (...a) => exportarProjetosAvaliacaoCsv(...a),
    getOpcoesAvaliadores: (...a) => getOpcoesAvaliadores(...a),
    designarProjeto: (...a) => designarProjeto(...a),
    corrigirProjeto: (...a) => corrigirProjeto(...a),
    buscarOrientadores: (...a) => buscarOrientadores(...a),
}));

import AvaliacaoProjetos from './AvaliacaoProjetos.jsx';

describe('AvaliacaoProjetos — tabela única', () => {
    beforeEach(() => {
        getAvaliacaoProjetos.mockClear();
        exportarProjetosAvaliacaoCsv.mockClear();
        designarProjeto.mockClear();
        corrigirProjeto.mockClear();
    });

    it('mostra todos os projetos numa tabela só, sem acordeão por área', async () => {
        render(<AvaliacaoProjetos />);

        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projeto Y')).toBeInTheDocument();
        expect(screen.getByText('2 projetos.')).toBeInTheDocument();
        expect(screen.queryByText('Expandir todas')).not.toBeInTheDocument();

        const tabela = within(screen.getByRole('table'));
        expect(tabela.getByText('Ciências Agrárias')).toBeInTheDocument();
        expect(tabela.getByText('FETEC Jr')).toBeInTheDocument();
    });

    it('busca pelo título (com debounce)', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.change(screen.getByLabelText('Buscar projeto'), { target: { value: 'bioplástico' } });

        await waitFor(() => {
            expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'bioplástico', page: 1 }));
        }, { timeout: 3000 });
    });

    it('filtra por área e por categoria', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '2' } });
        await waitFor(() => expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ areaId: '2' })));

        fireEvent.change(screen.getByLabelText('Filtrar por categoria'), { target: { value: 'fetecms' } });
        await waitFor(() => expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ categoria: 'fetecms' })));
    });

    it('ordena por coluna, alternando asc e desc', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Ordenar por Faltantes'));
        await waitFor(() => {
            expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ ordenar: 'faltantes', direcao: 'asc' }));
        });

        fireEvent.click(screen.getByLabelText('Ordenar por Faltantes'));
        await waitFor(() => {
            expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ ordenar: 'faltantes', direcao: 'desc' }));
        });
    });

    it('exporta o CSV com os filtros em vigor', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '1' } });
        fireEvent.click(screen.getByText('Exportar CSV'));

        await waitFor(() => {
            expect(exportarProjetosAvaliacaoCsv).toHaveBeenLastCalledWith(expect.objectContaining({ areaId: '1' }));
        });
    });

    it('designa o projeto a um avaliador escolhido por busca', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Designar Projeto X'));
        expect(await screen.findByText('Designar avaliação')).toBeInTheDocument();

        const buscaAvaliador = screen.getByPlaceholderText('Digite o nome do avaliador…');
        fireEvent.change(buscaAvaliador, { target: { value: 'zil' } });
        // O combobox seleciona no mouseDown da opção.
        fireEvent.mouseDown(screen.getByText('Zilda Rocha', { exact: false }));
        expect(buscaAvaliador.value).toBe('Zilda Rocha');

        // O botão do modal, não os "Designar" das linhas da tabela.
        fireEvent.click(within(screen.getByRole('dialog')).getByText('Designar'));

        await waitFor(() => expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'avaliador', alvo_id: 20 }));
        expect(await screen.findByText('1 designação criada.')).toBeInTheDocument();
    });

    it('mostra um card de resumo por área, com as quatro faixas', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        const resumo = within(screen.getByRole('region', { name: 'Resumo por área do conhecimento' }));
        expect(resumo.getByText('Ciências Agrárias')).toBeInTheDocument();
        expect(resumo.getByText('Ciências Exatas')).toBeInTheDocument();
        // Os dois cards têm um projeto cada, nenhum com o mínimo ainda.
        expect(resumo.getAllByText('1 projeto · 0 com o mínimo de 3')).toHaveLength(2);
        // Faixas 0, 1, 2 e 3+ em cada card.
        expect(resumo.getAllByText('0')).not.toHaveLength(0);
        expect(resumo.getAllByText('3+')).toHaveLength(2);
    });

    it('os cards acompanham o filtro da tabela', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        getAvaliacaoProjetos.mockResolvedValueOnce({
            data: [LINHAS[1]],
            meta: {
                ...META,
                total: 1,
                resumo_geral: { zero: 1, uma: 0, duas: 1, tres_ou_mais: 0, total: 2, completos: 0 },
    resumo_areas: [{ area_id: 2, area: 'Ciências Exatas', zero: 1, uma: 0, duas: 0, tres_ou_mais: 0, total: 1, completos: 0 }],
            },
        });

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '2' } });

        await waitFor(() => {
            const resumo = within(screen.getByRole('region', { name: 'Resumo por área do conhecimento' }));
            expect(resumo.queryByText('Ciências Agrárias')).not.toBeInTheDocument();
            expect(resumo.getByText('Ciências Exatas')).toBeInTheDocument();
        });
    });

    it('designa o projeto para a comissão especial inteira', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Designar Projeto X'));
        fireEvent.change(await screen.findByLabelText('Designar para'), { target: { value: 'comissao' } });

        expect(await screen.findByText(/membros da/)).toBeInTheDocument();
        expect(getOpcoesAvaliadores).toHaveBeenCalledWith(true);

        fireEvent.click(within(screen.getByRole('dialog')).getByText('Designar'));

        await waitFor(() => expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'comissao', avaliador_ids: [] }));
    });

    it('designa o projeto para membros selecionados da comissão', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Designar Projeto X'));
        fireEvent.change(await screen.findByLabelText('Designar para'), { target: { value: 'comissao_selecionada' } });

        const dialogo = within(screen.getByRole('dialog'));
        // Sem ninguém marcado, o botão não designa.
        expect(await dialogo.findByText('Célia Prado')).toBeInTheDocument();
        expect(dialogo.getByText('Designar').closest('button')).toBeDisabled();

        fireEvent.click(dialogo.getByText('Célia Prado'));
        fireEvent.click(dialogo.getByText('Designar'));

        await waitFor(() => expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'comissao', avaliador_ids: [30] }));
    });
});

describe('AvaliacaoProjetos — correção manual', () => {
    beforeEach(() => { corrigirProjeto.mockClear(); });

    it('cada linha tem Editar ao lado de Designar', async () => {
        render(<AvaliacaoProjetos />);
        expect(await screen.findByLabelText('Editar Projeto X')).toBeInTheDocument();
        expect(screen.getByLabelText('Designar Projeto X')).toBeInTheDocument();
    });

    it('só salva com justificativa e manda os quatro campos', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByLabelText('Editar Projeto X'));

        expect(screen.getByText('Editar projeto')).toBeInTheDocument();

        const dialogo = within(screen.getByRole('dialog'));
        expect(dialogo.getByText('Salvar alterações')).toBeDisabled();

        fireEvent.change(dialogo.getByLabelText(/Categoria/), { target: { value: 'fetecms' } });
        fireEvent.change(dialogo.getByLabelText(/Link do vídeo/), { target: { value: 'https://youtu.be/novo' } });
        fireEvent.change(dialogo.getByLabelText(/Justificativa/), { target: { value: 'Corrigido pela coordenação.' } });

        fireEvent.click(dialogo.getByText('Salvar alterações'));

        await waitFor(() => expect(corrigirProjeto).toHaveBeenCalledWith(1, expect.objectContaining({
            categoria: 'fetecms',
            area_id: 1,
            link_video: 'https://youtu.be/novo',
            justificativa: 'Corrigido pela coordenação.',
        })));
    });

    it('troca o orientador buscando entre as contas existentes', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByLabelText('Editar Projeto X'));

        const dialogo = within(screen.getByRole('dialog'));
        expect(dialogo.getByText('Marta Antiga')).toBeInTheDocument();

        fireEvent.click(dialogo.getByText('Trocar o orientador'));
        fireEvent.change(dialogo.getByLabelText('Buscar orientador por nome ou e-mail'), {
            target: { value: 'joão' },
        });

        fireEvent.click(await dialogo.findByText('João Novo'));
        expect(dialogo.getByText(/perde o acesso a ele/)).toBeInTheDocument();

        fireEvent.change(dialogo.getByLabelText(/Justificativa/), { target: { value: 'Conta errada na inscrição.' } });
        fireEvent.click(dialogo.getByText('Salvar alterações'));

        await waitFor(() => expect(corrigirProjeto).toHaveBeenCalledWith(1, expect.objectContaining({
            user_id: 42,
        })));
    });

    it('edita os dados do coorientador', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByLabelText('Editar Projeto X'));

        const dialogo = within(screen.getByRole('dialog'));
        expect(dialogo.getByLabelText('Nome do coorientador')).toHaveValue('Caio Coorientador');

        fireEvent.change(dialogo.getByLabelText('Nome do coorientador'), { target: { value: 'Caio Corrigido' } });
        fireEvent.change(dialogo.getByLabelText(/Justificativa/), { target: { value: 'Nome digitado errado.' } });
        fireEvent.click(dialogo.getByText('Salvar alterações'));

        await waitFor(() => expect(corrigirProjeto).toHaveBeenCalledWith(1, expect.objectContaining({
            coorientador: expect.objectContaining({ nome: 'Caio Corrigido' }),
        })));
    });

    it('remove o coorientador mandando null', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByLabelText('Editar Projeto X'));

        const dialogo = within(screen.getByRole('dialog'));
        fireEvent.click(dialogo.getByText('Remover coorientador'));
        expect(dialogo.getByText('Este projeto não tem coorientador.')).toBeInTheDocument();

        fireEvent.change(dialogo.getByLabelText(/Justificativa/), { target: { value: 'Não participou do projeto.' } });
        fireEvent.click(dialogo.getByText('Salvar alterações'));

        await waitFor(() => expect(corrigirProjeto).toHaveBeenCalledWith(1, expect.objectContaining({
            coorientador: null,
        })));
    });

    /** A série é o que diz se a categoria está certa — por isso ela fica logo abaixo. */
    it('mostra a série de cada aluno abaixo da categoria, só para leitura', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByLabelText('Editar Projeto X'));

        const dialogo = within(screen.getByRole('dialog'));
        expect(dialogo.getByText('Ana Aluna')).toBeInTheDocument();
        expect(dialogo.getByText('9º ano do Ensino Fundamental II')).toBeInTheDocument();
        // Aluno sem série cadastrada é dito, não escondido.
        expect(dialogo.getByText('Série não informada')).toBeInTheDocument();

        // Vem depois da categoria e antes da área.
        const categoria = dialogo.getByLabelText(/Categoria/);
        const equipe = dialogo.getByText('Equipe');
        expect(categoria.compareDocumentPosition(equipe) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('explica o projeto sem alunos cadastrados', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByLabelText('Editar Projeto Y'));

        const dialogo = within(screen.getByRole('dialog'));
        expect(dialogo.getByText('Nenhum aluno cadastrado neste projeto.')).toBeInTheDocument();
    });
});

describe('AvaliacaoProjetos — card geral', () => {
    it('soma todas as áreas num card destacado no topo', async () => {
        render(<AvaliacaoProjetos />);
        expect(await screen.findByText('Todos os projetos')).toBeInTheDocument();
        expect(screen.getByText('sem avaliação')).toBeInTheDocument();
        expect(screen.getByText('3 ou mais')).toBeInTheDocument();
        // Vem antes dos cards por área.
        const geral = screen.getByText('Todos os projetos');
        const area = screen.getAllByText('Ciências Agrárias')[0];
        expect(geral.compareDocumentPosition(area) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    // Sprint 113: designar não some em silêncio com quem já avaliou o projeto.
    it('avisa quais avaliadores não puderam receber por já terem avaliado', async () => {
        designarProjeto.mockResolvedValue({
            data: { designadas: 2, ja_avaliaram: ['Ana Souza', 'Bruno Lima'], ja_tem: [], retomadas: [] },
            meta: {
                message: '2 designações criadas. Ana Souza e Bruno Lima não puderam receber porque já avaliou este projeto.',
                ja_avaliaram: ['Ana Souza', 'Bruno Lima'],
            },
        });

        render(<AvaliacaoProjetos />);

        fireEvent.click(await screen.findByLabelText('Designar Projeto X'));
        await screen.findByText('Designar avaliação');
        fireEvent.change(screen.getByPlaceholderText('Digite o nome do avaliador…'), { target: { value: 'zil' } });
        fireEvent.mouseDown(screen.getByText('Zilda Rocha', { exact: false }));
        fireEvent.click(within(screen.getByRole('dialog')).getByText('Designar'));

        expect(await screen.findByText(/2 avaliadores não receberam este projeto porque já o avaliaram/))
            .toBeInTheDocument();
        expect(screen.getByText(/Ana Souza, Bruno Lima/)).toBeInTheDocument();
    });

    it('sem ninguém barrado, nenhum aviso aparece', async () => {
        designarProjeto.mockResolvedValue({
            data: { designadas: 1, ja_avaliaram: [], ja_tem: [], retomadas: [] },
            meta: { message: '1 designação criada.', ja_avaliaram: [] },
        });

        render(<AvaliacaoProjetos />);

        fireEvent.click(await screen.findByLabelText('Designar Projeto X'));
        await screen.findByText('Designar avaliação');
        fireEvent.change(screen.getByPlaceholderText('Digite o nome do avaliador…'), { target: { value: 'zil' } });
        fireEvent.mouseDown(screen.getByText('Zilda Rocha', { exact: false }));
        fireEvent.click(within(screen.getByRole('dialog')).getByText('Designar'));

        expect(await screen.findByText('1 designação criada.')).toBeInTheDocument();
        expect(screen.queryByText(/não receberam este projeto/)).not.toBeInTheDocument();
    });
});

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const OPCOES = {
    fases: [{ valor: 'online', rotulo: 'Fase online' }, { valor: 'presencial', rotulo: 'Fase presencial' }],
    publicos: [
        { valor: 'avaliadores_online', rotulo: 'Avaliadores Online', fases: ['online'], modos: ['fixa', 'por_projeto'] },
        { valor: 'avaliadores_presencial', rotulo: 'Avaliadores Presencial', fases: ['presencial'], modos: ['fixa', 'por_projeto'] },
        { valor: 'orientadores', rotulo: 'Orientadores', fases: ['online', 'presencial'], modos: ['fixa'] },
        { valor: 'voluntarios', rotulo: 'Voluntários Cadastrados', fases: ['presencial'], modos: ['fixa', 'por_turno'] },
        { valor: 'admins', rotulo: 'Admins Cadastrados', fases: null, modos: ['fixa'] },
    ],
    datas: { online: { inicio: '2026-08-01', fim: '2026-09-15' }, presencial: { inicio: null, fim: null } },
};

const CATEGORIAS = [
    { chave: 'avaliadores_online', publico: 'avaliadores_online', fase: 'online', rotulo: 'Avaliadores Online', modos: ['fixa', 'por_projeto'] },
    { chave: 'orientadores_online', publico: 'orientadores', fase: 'online', rotulo: 'Orientadores — fase online', modos: ['fixa'] },
];
const CONFIG = {
    fases: ['online'], publicos: ['avaliadores_online', 'orientadores'],
    categorias: {
        avaliadores_online: { modo: 'por_projeto', minutos: 150, teto_minutos: 7200, data_inicio: '2026-08-01', data_fim: '2026-09-15' },
        orientadores_online: { modo: 'fixa', minutos: null, teto_minutos: null, data_inicio: '2026-08-01', data_fim: '2026-09-15' },
    },
};

const salvar = vi.fn();
const gerar = vi.fn();
const getLista = vi.fn();
const getPessoas = vi.fn();
const salvarNomes = vi.fn();
vi.mock('../lib/certificados.js', async (importOriginal) => ({
    ...(await importOriginal()),
    getOpcoesCertificados: () => Promise.resolve(OPCOES),
    getListaCertificados: (...a) => getLista(...a),
    salvarListaCertificados: (...a) => salvar(...a),
    getResumoCertificados: () => Promise.resolve([
        { ...CATEGORIAS[1], carga: { ...CONFIG.categorias.orientadores_online, minutos: 600 }, carga_texto: '10h fixas', pessoas: 2, certificados: 3, sem_cpf: 1, sem_email: 0, nomes_corrigidos: 0, teto_aplicado: 0 },
    ]),
    getPessoasCategoria: (...a) => getPessoas(...a),
    salvarNomesCertificados: (...a) => salvarNomes(...a),
    gerarListaCertificados: (...a) => gerar(...a),
    getProjetosDoAvaliador: () => Promise.resolve({ online: [], presencial: [] }),
}));

import AssistenteCertificados from './AssistenteCertificados.jsx';

function Lista() {
    return <p>tela da lista</p>;
}

const tela = (rota = '/admin/certificados/nova') => render(
    <MemoryRouter initialEntries={[rota]}>
        <Routes>
            <Route path="/admin/certificados/nova" element={<AssistenteCertificados />} />
            <Route path="/admin/certificados/listas/:id/assistente" element={<AssistenteCertificados />} />
            <Route path="/admin/certificados/listas/:id" element={<Lista />} />
        </Routes>
    </MemoryRouter>,
);

const detalhe = (extra = {}) => ({ id: 7, nome: 'Fase online', status: 'rascunho', passo: 0, config: CONFIG, categorias: CATEGORIAS, nomes: {}, ...extra });

describe('AssistenteCertificados', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        salvar.mockImplementation((_id, dados) => Promise.resolve(detalhe({ passo: dados.passo, nome: dados.nome || 'Fase online' })));
    });

    it('a primeira fase cria a lista e o público mostra só o das fases escolhidas', async () => {
        tela();

        fireEvent.click(await screen.findByRole('button', { name: 'Avançar' }));
        expect(await screen.findByText('Escolha ao menos uma fase.')).toBeInTheDocument();
        expect(salvar).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Nome da lista'), { target: { value: 'Fase online' } });
        fireEvent.click(screen.getByLabelText(/Fase online/));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        await waitFor(() => expect(salvar).toHaveBeenCalledWith(null, expect.objectContaining({
            nome: 'Fase online', passo: 1, config: expect.objectContaining({ fases: ['online'] }),
        })));

        const publico = await screen.findByRole('region', { name: 'Público-alvo' });
        expect(within(publico).getByText('Avaliadores Online')).toBeInTheDocument();
        expect(within(publico).getByText('Admins Cadastrados')).toBeInTheDocument();
        expect(within(publico).queryByText('Avaliadores Presencial')).not.toBeInTheDocument();
        expect(within(publico).queryByText('Voluntários Cadastrados')).not.toBeInTheDocument();
    });

    it('a carga cobra cada categoria e mostra o limite só na carga por projeto', async () => {
        getLista.mockResolvedValue(detalhe({ passo: 2 }));
        tela('/admin/certificados/listas/7/assistente');

        const carga = await screen.findByRole('region', { name: 'Carga horária' });
        expect(within(carga).getByRole('group', { name: 'Horas por projeto avaliado' })).toBeInTheDocument();
        expect(within(carga).getByLabelText('Limite de horas (opcional) — horas')).toHaveValue(120);
        expect(within(carga).getAllByText(/Limite de horas/)).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        expect(await screen.findByText(/Informe a carga horária de: Orientadores — fase online/)).toBeInTheDocument();

        fireEvent.change(within(carga).getByLabelText('Carga horária — horas'), { target: { value: '10' } });
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        await waitFor(() => expect(salvar).toHaveBeenCalledWith(7, expect.objectContaining({
            passo: 3,
            config: expect.objectContaining({ categorias: expect.objectContaining({ orientadores_online: expect.objectContaining({ minutos: 600 }) }) }),
        })));
        expect(await screen.findByText('1 sem CPF')).toBeInTheDocument();
    });

    it('conferir nomes corrige só na lista, salva e grava o assistente ao fechar', async () => {
        getLista.mockResolvedValue(detalhe({ passo: 3 }));
        getPessoas.mockResolvedValue([
            { pessoa_chave: 'user:9', nome: 'Olivia Orientadora', nome_original: 'Olivia Orientadora', cpf: null, email: 'o@x.test', titulo: 'Biofiltro', minutos: 600, horas: 10, teto_aplicado: false, projeto_id: 1, avaliador_id: null },
        ]);
        salvarNomes.mockResolvedValue(detalhe());
        tela('/admin/certificados/listas/7/assistente');

        fireEvent.click(await screen.findByRole('button', { name: /Conferir nomes/ }));
        const dialogo = await screen.findByRole('dialog', { name: /Conferir nomes/ });
        expect(await within(dialogo).findByText('Olivia Orientadora')).toBeInTheDocument();

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Editar nome de Olivia Orientadora' }));
        fireEvent.change(within(dialogo).getByLabelText('Novo nome de Olivia Orientadora'), { target: { value: 'Olívia Orientadora' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Confirmar nome' }));
        expect(within(dialogo).getByText('não salvo')).toBeInTheDocument();

        fireEvent.click(within(dialogo).getByRole('button', { name: /Salvar/ }));
        await waitFor(() => expect(salvarNomes).toHaveBeenCalledWith(7, { 'user:9': 'Olívia Orientadora' }, { 'user:9': 'Olivia Orientadora' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(salvar).toHaveBeenCalledWith(7, expect.objectContaining({ passo: 3 }));
    });

    it('gerar leva à tela da lista', async () => {
        getLista.mockResolvedValue(detalhe({ passo: 3 }));
        gerar.mockResolvedValue(detalhe({ status: 'gerada' }));
        tela('/admin/certificados/listas/7/assistente');

        // O botão só habilita com o resumo na tela.
        expect(await screen.findByText('1 sem CPF')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Gerar certificados/ }));
        expect(await screen.findByText('tela da lista')).toBeInTheDocument();
        expect(gerar).toHaveBeenCalledWith(7);
    });
});

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const setUser = vi.fn();
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({
        user: { name: 'Ana', role: 'avaliador', avaliador_profile: { area: 'Ciências Exatas e da Terra', subarea: null } },
        setUser: (...a) => setUser(...a),
        logout: vi.fn(),
    }),
    homeFor: () => '/avaliador',
    extractErrors: (e) => e?.response?.data?.errors ?? {},
}));

const getPerfilAvaliador = vi.fn();
const salvarClassificacaoAvaliador = vi.fn();
vi.mock('../lib/avaliador.js', () => ({
    getPerfilAvaliador: (...a) => getPerfilAvaliador(...a),
    salvarClassificacaoAvaliador: (...a) => salvarClassificacaoAvaliador(...a),
}));

const criarSubarea = vi.fn();
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([
        { id: 1, nome: 'Ciências Exatas e da Terra' },
        { id: 2, nome: 'Ciências Biológicas' },
    ])),
    loadSubareas: vi.fn((areaId) => Promise.resolve(
        String(areaId) === '2' ? [{ id: 5, nome: 'Botânica', area_id: 2 }] : [{ id: 9, nome: 'Astronomia', area_id: 1 }],
    )),
    criarSubarea: (...a) => criarSubarea(...a),
}));

import AvaliadorPerfil from './AvaliadorPerfil.jsx';

const PERFIL = {
    nome: 'Ana', email: 'ana@fetecms.test', titulacao: 'Mestrado (em andamento)',
    area_id: 1, area: 'Ciências Exatas e da Terra', subarea_id: null, subarea: null,
    limite_avaliacoes: null, max_por_avaliador: 3,
    estatisticas: {
        avaliacoes_concluidas: 3,
        certificado_minutos: 450, certificado_label: '7h30', por_avaliacao_label: '2h30',
        posicao: 2, total_no_ranking: 12, empate: false,
    },
    pode_trocar_area: true,
    liberada_em_label: '10/09/2026 08:00',
    projetos_designados: 3,
    minutos_por_avaliacao: 150,
};

const renderPerfil = () => render(<MemoryRouter><AvaliadorPerfil /></MemoryRouter>);

/** Troca a área no select — esperando o catálogo chegar (carga assíncrona). */
async function escolherArea(nome, valor) {
    await screen.findByRole('option', { name: nome });
    fireEvent.change(screen.getByLabelText(/Área do conhecimento/), { target: { value: valor } });
}

describe('AvaliadorPerfil — estatísticas', () => {
    beforeEach(() => {
        getPerfilAvaliador.mockReset();
        salvarClassificacaoAvaliador.mockReset();
        setUser.mockReset();
        getPerfilAvaliador.mockResolvedValue(PERFIL);
    });

    it('mostra avaliações concluídas, carga horária do certificado e posição no ranking', async () => {
        renderPerfil();

        expect(await screen.findByText('Projetos avaliados')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();

        expect(screen.getByText('Certificado')).toBeInTheDocument();
        expect(screen.getByText('7h30')).toBeInTheDocument();
        expect(screen.getByText('2h30 por avaliação concluída')).toBeInTheDocument();

        expect(screen.getByText('No ranking de avaliadores')).toBeInTheDocument();
        expect(screen.getByText('2º')).toBeInTheDocument();
        expect(screen.getByText('entre 12 avaliadores')).toBeInTheDocument();
    });

    it('avisa quando a posição é dividida com outros avaliadores', async () => {
        getPerfilAvaliador.mockResolvedValue({
            ...PERFIL,
            estatisticas: { ...PERFIL.estatisticas, posicao: 1, empate: true },
        });
        renderPerfil();

        expect(await screen.findByText('entre 12 avaliadores · posição dividida')).toBeInTheDocument();
    });

    it('quem ainda não avaliou fica sem posição, com o convite para começar', async () => {
        getPerfilAvaliador.mockResolvedValue({
            ...PERFIL, projetos_designados: 1,
            estatisticas: {
                ...PERFIL.estatisticas,
                avaliacoes_concluidas: 0, certificado_minutos: 0, certificado_label: '0h',
                posicao: null, empate: false,
            },
        });
        renderPerfil();

        expect(await screen.findByText('0h')).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.getByText('Conclua sua primeira avaliação para entrar no ranking')).toBeInTheDocument();
        // Singular no detalhe do primeiro card.
        expect(screen.getByText('1 projeto designado a você')).toBeInTheDocument();
    });

    it('mostra os dados de identificação do avaliador', async () => {
        renderPerfil();

        expect(await screen.findByText('ana@fetecms.test')).toBeInTheDocument();
        expect(screen.getByText('Mestrado (em andamento)')).toBeInTheDocument();
        expect(screen.getByText('3 (padrão do edital)')).toBeInTheDocument();
    });

    it('avisa quando não consegue carregar', async () => {
        getPerfilAvaliador.mockRejectedValue(new Error('falhou'));
        renderPerfil();

        expect(await screen.findByText('Não foi possível carregar seu perfil.')).toBeInTheDocument();
    });
});

describe('AvaliadorPerfil — área de atuação', () => {
    beforeEach(() => {
        getPerfilAvaliador.mockReset();
        salvarClassificacaoAvaliador.mockReset();
        setUser.mockReset();
        criarSubarea.mockReset();
        getPerfilAvaliador.mockResolvedValue(PERFIL);
    });

    it('deixa trocar a área fora do período de avaliação', async () => {
        salvarClassificacaoAvaliador.mockResolvedValue({
            ...PERFIL, area_id: 2, area: 'Ciências Biológicas', subarea_id: null, subarea: null,
        });
        renderPerfil();

        await screen.findByLabelText(/Área do conhecimento/);
        const salvar = screen.getByRole('button', { name: /Salvar área/i });
        expect(salvar).toBeDisabled(); // nada mudou ainda

        await escolherArea('Ciências Biológicas', '2');
        expect(salvar).toBeEnabled();

        fireEvent.click(salvar);
        await waitFor(() => expect(salvarClassificacaoAvaliador).toHaveBeenCalledWith({ area_id: 2, subarea_id: null }));
        expect(await screen.findByText('Área de atuação atualizada.')).toBeInTheDocument();
        // O usuário em memória acompanha, para o cabeçalho do painel não ficar velho.
        expect(setUser).toHaveBeenCalled();
    });

    it('avisa que trocar de área não refaz as designações já feitas', async () => {
        renderPerfil();

        expect(await screen.findByText(/3 projetos designados\./)).toBeInTheDocument();
    });

    it('limpa a subárea ao trocar de área', async () => {
        getPerfilAvaliador.mockResolvedValue({ ...PERFIL, subarea_id: 9, subarea: 'Astronomia' });
        renderPerfil();

        const combo = await screen.findByDisplayValue('Astronomia');
        expect(combo).toBeInTheDocument();

        await escolherArea('Ciências Biológicas', '2');
        await waitFor(() => expect(screen.queryByDisplayValue('Astronomia')).not.toBeInTheDocument());
    });

    it('mostra o erro de validação vindo do servidor', async () => {
        salvarClassificacaoAvaliador.mockRejectedValue({
            response: { data: { message: 'Dados inválidos.', errors: { area_id: ['A área selecionada é inválida.'] } } },
        });
        renderPerfil();

        await escolherArea('Ciências Biológicas', '2');
        fireEvent.click(screen.getByRole('button', { name: /Salvar área/i }));

        expect(await screen.findByText('A área selecionada é inválida.')).toBeInTheDocument();
    });

    it('durante o período de avaliação mostra a área em leitura, sem formulário', async () => {
        getPerfilAvaliador.mockResolvedValue({
            ...PERFIL, pode_trocar_area: false, subarea_id: 9, subarea: 'Astronomia',
        });
        renderPerfil();

        expect(await screen.findByText(/O período de avaliação já começou/)).toBeInTheDocument();
        expect(screen.getByText('Astronomia')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Salvar área/i })).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Área do conhecimento/)).not.toBeInTheDocument();
    });
});

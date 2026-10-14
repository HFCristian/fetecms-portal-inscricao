import { render, screen, within, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getPainelPremiacao = vi.fn();
const getPainelComite = vi.fn();
vi.mock('../lib/recomendacoes.js', () => ({ getPainelPremiacao: (...a) => getPainelPremiacao(...a) }));
vi.mock('../lib/avaliador.js', () => ({ getPainelComite: (...a) => getPainelComite(...a) }));

import PainelPremiacao from './PainelPremiacao.jsx';

const PAINEL = {
    resumo: {
        credenciais: 3, vagas_total: 5, vagas_preenchidas: 3, faltam: 2, credenciais_com_vaga: 1,
        esgotadas: 1, sem_limite: 1, premiacoes: 5, projetos_premiados: 3, aprovadas_aguardando: 1,
    },
    credenciais: [
        { id: 1, tipo: 'credencial', tipo_label: 'Credencial', nome: 'MOSTRATEC 2027', orgao: 'Fundação Liberato', vagas: 3, usadas: 1, faltam: 2, esgotada: false, aprovadas_aguardando: 1,
            premiados: [{ id: 7, titulo: 'Bioplástico', area: 'Ciências Agrárias' }] },
        { id: 2, tipo: 'premio', tipo_label: 'Prêmio', nome: 'Destaque', orgao: null, vagas: 2, usadas: 2, faltam: 0, esgotada: true, aprovadas_aguardando: 0,
            premiados: [{ id: 7, titulo: 'Bioplástico', area: 'Ciências Agrárias' }, { id: 8, titulo: 'Abelhas', area: 'Ciências Biológicas' }] },
        { id: 3, tipo: 'credencial', tipo_label: 'Credencial', nome: 'Bolsa IC Jr', orgao: 'FUNDECT', vagas: null, usadas: 0, faltam: null, esgotada: false, aprovadas_aguardando: 0, premiados: [] },
    ],
    ranking_por_area: [
        { area: 'Ciências Agrárias', premiacoes: 2, projetos: [
            { posicao: 1, id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', escola: 'EE Alfa', total: 2,
                premiacoes: [{ nome: 'MOSTRATEC 2027', tipo: 'credencial', tipo_label: 'Credencial' }, { nome: 'Destaque', tipo: 'premio', tipo_label: 'Prêmio' }] },
        ] },
        { area: 'Ciências Biológicas', premiacoes: 1, projetos: [
            { posicao: 1, id: 8, titulo: 'Abelhas', categoria: 'FETEC Jr', escola: 'EE Beta', total: 1,
                premiacoes: [{ nome: 'Destaque', tipo: 'premio', tipo_label: 'Prêmio' }] },
        ] },
    ],
    atualizado_em: '2026-10-13T20:30:00-04:00',
};

describe('PainelPremiacao', () => {
    beforeEach(() => {
        getPainelPremiacao.mockReset().mockResolvedValue(PAINEL);
        getPainelComite.mockReset().mockResolvedValue(PAINEL);
    });

    it('mostra quanto falta, credencial a credencial', async () => {
        render(<PainelPremiacao origem="admin" />);

        const resumo = await screen.findByRole('region', { name: 'Resumo' });
        expect(within(resumo).getByText('Vagas que faltam').parentElement).toHaveTextContent('2');
        expect(within(resumo).getByText('3 de 5')).toBeInTheDocument();
        expect(within(resumo).getByText(/1 sem limite de vagas/)).toBeInTheDocument();

        const credenciais = screen.getByRole('region', { name: 'Credenciais e prêmios' });
        expect(within(credenciais).getByText('Falta(m) 2 de 3')).toBeInTheDocument();
        expect(within(credenciais).getByText('Esgotada')).toBeInTheDocument();
        expect(within(credenciais).getByText('0 atribuída(s) · sem limite')).toBeInTheDocument();
        expect(within(credenciais).getByText(/1 recomendação\(ões\) aprovada\(s\) aguardando/)).toBeInTheDocument();
        expect(within(credenciais).getByText('Ainda não atribuída.')).toBeInTheDocument();
    });

    it('mostra o ranking por área com a posição', async () => {
        render(<PainelPremiacao origem="admin" />);

        const agrarias = await screen.findByRole('region', { name: 'Ranking de Ciências Agrárias' });
        expect(within(agrarias).getByText('1º')).toBeInTheDocument();
        expect(within(agrarias).getByText('Bioplástico')).toBeInTheDocument();
        expect(within(agrarias).getByText('2 premiação(ões)', { selector: 'span.ml-2' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Ranking de Ciências Biológicas' })).toBeInTheDocument();
    });

    it('o admin volta para a Premiação e lê a API do admin', async () => {
        render(<PainelPremiacao origem="admin" />);
        await screen.findByRole('region', { name: 'Resumo' });

        expect(getPainelPremiacao).toHaveBeenCalled();
        expect(getPainelComite).not.toHaveBeenCalled();
        expect(screen.getByRole('link', { name: /Premiação/ })).toHaveAttribute('href', '/admin/comite/premiacao');
    });

    it('a comissão lê a própria API e volta para a aba dela', async () => {
        render(<PainelPremiacao origem="avaliador" />);
        await screen.findByRole('region', { name: 'Resumo' });

        expect(getPainelComite).toHaveBeenCalled();
        expect(getPainelPremiacao).not.toHaveBeenCalled();
        expect(screen.getByRole('link', { name: /Comitê especial/ })).toHaveAttribute('href', '/avaliador/comite');
    });

    it('sem premiação nenhuma diz isso', async () => {
        getPainelPremiacao.mockResolvedValue({ ...PAINEL, credenciais: [], ranking_por_area: [] });
        render(<PainelPremiacao origem="admin" />);

        expect(await screen.findByText('Nenhum projeto premiado ainda.')).toBeInTheDocument();
        expect(screen.getByText('Nenhuma credencial ou prêmio ativo nesta edição.')).toBeInTheDocument();
    });

    it('falha ao carregar mostra o motivo', async () => {
        getPainelPremiacao.mockRejectedValue(new Error('x'));
        render(<PainelPremiacao origem="admin" />);

        await waitFor(() => expect(screen.getByText('Não foi possível carregar o painel da premiação.')).toBeInTheDocument());
    });
});

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => navigate,
}));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getVisaoGeral = vi.fn();
const getDetalheCard = vi.fn();
const definirAtualizacao = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getVisaoGeral: (...a) => getVisaoGeral(...a),
    getDetalheCard: (...a) => getDetalheCard(...a),
    definirAtualizacao: (...a) => definirAtualizacao(...a),
}));

import CerimonialVisaoGeral from './CerimonialVisaoGeral.jsx';

const DADOS = {
    pessoas: {
        presentes: 12, total: 40, faltam: 28,
        por_papel: { alunos: 8, orientadores: 3, coorientadores: 1 },
        por_papel_total: { alunos: 24, orientadores: 10, coorientadores: 6 },
    },
    projetos: { completos: 2, parciais: 3, presentes: 5, total: 10, faltam: 5 },
    premiados: { presentes: 2, completos: 1, total: 4, faltam: 2 },
    medalhas: { separar: 6, total: 16, por_papel: { alunos: 4, orientadores: 2, coorientadores: 0 } },
    credenciais: { separar: 3, total: 5 },
    atualizado_em: '19:32:10',
};

const META = { config: { aberto: true, lista: { demo: false }, atualizacao_segundos: null } };

describe('CerimonialVisaoGeral', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getVisaoGeral.mockResolvedValue({ data: DADOS, meta: META });
        getDetalheCard.mockResolvedValue({ presentes: [], faltantes: [] });
        definirAtualizacao.mockResolvedValue({ atualizacao_segundos: 10 });
    });

    it('mostra os cinco cards com presentes e faltantes', async () => {
        render(<CerimonialVisaoGeral />);

        expect(await screen.findByText('Pessoas com check-in')).toBeInTheDocument();
        expect(screen.getByText('Projetos na sala')).toBeInTheDocument();
        expect(screen.getByText('Premiados')).toBeInTheDocument();
        expect(screen.getByText('Medalhas a separar')).toBeInTheDocument();
        expect(screen.getByText('Credenciais a separar')).toBeInTheDocument();

        expect(screen.getByText('Faltam 28.')).toBeInTheDocument();
        // Parcial e completo são números diferentes, e os dois importam.
        expect(screen.getByText(/2 com a equipe completa · 3 parcialmente presente\(s\)/)).toBeInTheDocument();
    });

    it('todo card abre a lista nominal de quem chegou e de quem falta', async () => {
        getDetalheCard.mockResolvedValue({
            presentes: [{ nome: 'Ana Paula', papel: 'A', papel_label: 'Aluno(a)', projeto_id: 7, projeto_titulo: 'Bioplástico', checkin_em: '19:10' }],
            faltantes: [{ nome: 'Bruno Lima', papel: 'A', papel_label: 'Aluno(a)', projeto_id: 7, projeto_titulo: 'Bioplástico', checkin_em: null }],
        });
        render(<CerimonialVisaoGeral />);

        const botoes = await screen.findAllByRole('button', { name: /Ver quem chegou e quem falta/ });
        expect(botoes).toHaveLength(5);

        fireEvent.click(botoes[0]);
        await waitFor(() => expect(getDetalheCard).toHaveBeenCalledWith('pessoas', false));
        // A aba que abre é a de quem falta: é a pergunta do dia.
        expect(await screen.findByText('Bruno Lima')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Já chegaram/ }));
        expect(await screen.findByText('Ana Paula')).toBeInTheDocument();
    });

    it('o card de premiados leva aos cartões', async () => {
        render(<CerimonialVisaoGeral />);

        fireEvent.click(await screen.findByRole('button', { name: /Ver os cartões/ }));
        expect(navigate).toHaveBeenCalledWith('/admin/cerimonial/premiados');
    });

    it('o intervalo de atualização é guardado na edição', async () => {
        render(<CerimonialVisaoGeral />);

        fireEvent.change(await screen.findByLabelText('Atualização automática'), { target: { value: '10' } });

        await waitFor(() => expect(definirAtualizacao).toHaveBeenCalledWith(10));
    });

    it('sem lista final vigente não há cerimônia a acompanhar', async () => {
        getVisaoGeral.mockResolvedValue({
            data: DADOS,
            meta: { config: { aberto: true, lista: null, atualizacao_segundos: null } },
        });
        render(<CerimonialVisaoGeral />);

        expect(await screen.findByText(/Nenhuma lista final oficial está\s+vigente/)).toBeInTheDocument();
    });
});

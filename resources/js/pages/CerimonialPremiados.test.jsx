import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '7' }),
}));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getPremiados = vi.fn();
// A tela reaproveita o ícone da ficha, que importa do mesmo módulo: o mock
// precisa responder pelas funções dela também.
vi.mock('../lib/cerimonial.js', () => ({
    getPremiados: (...a) => getPremiados(...a),
    getFichaCerimonial: vi.fn(),
    registrarCheckin: vi.fn(),
    desfazerCheckin: vi.fn(),
}));

import CerimonialPremiados from './CerimonialPremiados.jsx';

const COMPLETO = {
    id: 7, titulo: 'Bioplástico de mandioca', categoria: 'FETECMS', area: 'Agrárias',
    escola: 'EE Alfa / Campo Grande - MS',
    premiacoes: [{ id: 1, nome: 'MOSTRATEC 2027', tipo: 'credencial', tipo_label: 'Credencial' }],
    pessoas: [
        { chave: 'A1', nome: 'Ana Paula', papel: 'A', papel_label: 'Aluno(a)', presente: true, checkin_em: '19:10' },
        { chave: 'O3', nome: 'Marta Orientadora', papel: 'O', papel_label: 'Orientador(a)', presente: true, checkin_em: '19:11' },
    ],
    presentes: 2, total: 2, completo: true,
};

const INCOMPLETO = {
    id: 8, titulo: 'Sensor de enchentes', categoria: 'FETEC Jr', area: 'Engenharias',
    escola: 'EM Beta / Dourados - MS',
    premiacoes: [{ id: 2, nome: 'Destaque da feira', tipo: 'premio', tipo_label: 'Prêmio' }],
    pessoas: [
        { chave: 'A4', nome: 'Carla Souza', papel: 'A', papel_label: 'Aluno(a)', presente: false, checkin_em: null },
        { chave: 'O5', nome: 'Paulo Orientador', papel: 'O', papel_label: 'Orientador(a)', presente: true, checkin_em: '19:20' },
    ],
    presentes: 1, total: 2, completo: false,
};

describe('CerimonialPremiados', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getPremiados.mockResolvedValue([COMPLETO, INCOMPLETO]);
    });

    it('desenha um cartão por projeto premiado, com cada participante', async () => {
        render(<CerimonialPremiados />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getByText('Sensor de enchentes')).toBeInTheDocument();
        expect(screen.getByText('Ana Paula')).toBeInTheDocument();
        expect(screen.getByText('Carla Souza')).toBeInTheDocument();
        // O papel fica ao lado do nome, como a organização o lê no palco.
        expect(screen.getAllByText('Orientador(a)')).toHaveLength(2);
    });

    it('marca a equipe completa e mostra credencial e prêmio', async () => {
        render(<CerimonialPremiados />);

        expect(await screen.findByText(/2 de 2 · completa/)).toBeInTheDocument();
        expect(screen.getByText('1 de 2')).toBeInTheDocument();
        expect(screen.getByText('MOSTRATEC 2027')).toBeInTheDocument();
        expect(screen.getByText('Destaque da feira')).toBeInTheDocument();
    });

    it('o filtro de equipes incompletas é o que interessa antes de chamar ao palco', async () => {
        render(<CerimonialPremiados />);

        await screen.findByText('Bioplástico de mandioca');
        fireEvent.click(screen.getByLabelText(/Só equipes incompletas/i, { selector: 'input' }));

        expect(screen.queryByText('Bioplástico de mandioca')).not.toBeInTheDocument();
        expect(screen.getByText('Sensor de enchentes')).toBeInTheDocument();
    });

    it('sem premiados cadastrados aponta onde cadastrá-los', async () => {
        getPremiados.mockResolvedValue([]);
        render(<CerimonialPremiados />);

        expect(await screen.findByText(/Credenciais e Prêmios/)).toBeInTheDocument();
    });
});

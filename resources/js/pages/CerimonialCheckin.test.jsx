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
// O leitor tem teste próprio; aqui interessa o caminho da busca.
vi.mock('../components/LeitorCerimonial.jsx', () => ({
    default: ({ onRegistrado, evento }) => (
        <button
            type="button"
            onClick={() => onRegistrado({ projeto_id: 7, participante: { chave: 'A1' }, ja_presente: false })}
        >
            leitor-falso-{evento}
        </button>
    ),
}));

const getCerimonialConfig = vi.fn();
const buscarParticipantes = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getCerimonialConfig: (...a) => getCerimonialConfig(...a),
    buscarParticipantes: (...a) => buscarParticipantes(...a),
}));

import CerimonialCheckin from './CerimonialCheckin.jsx';

const FETECMS = { id: 1, nome: 'Premiação FETECMS', categorias: ['fetecms'], publico: 'FETECMS', checkins: 0 };
const JR = { id: 2, nome: 'Premiação Jr', categorias: ['fetec_jr', 'fetecms_fundect'], publico: 'FETEC Jr e FETECMS FUNDECT', checkins: 0 };

const CONFIG = {
    aberto: true, iniciado: true, encerrado: false,
    inicio_label: '01/10/2026 08:00', fim_label: '03/10/2026 18:00',
    modo_teste: false, lista: { id: 1, nome: 'Oficial', demo: false },
    pode_gerir: true,
    eventos: [FETECMS],
};

const ACHADO = {
    projeto_id: 7, projeto_titulo: 'Bioplástico de mandioca', categoria: 'FETECMS',
    area: 'Agrárias', escola: 'EE Alfa / Campo Grande - MS',
    chave: 'A1', nome: 'Ana Paula', papel: 'A', papel_label: 'Aluno(a)', presente: false,
};

describe('CerimonialCheckin', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
        getCerimonialConfig.mockResolvedValue(CONFIG);
        buscarParticipantes.mockResolvedValue([ACHADO]);
    });

    it('a busca por nome abre a ficha do projeto da pessoa', async () => {
        render(<CerimonialCheckin />);

        fireEvent.change(await screen.findByLabelText('Procurar por nome ou CPF'), {
            target: { value: 'ana' },
        });

        await waitFor(() => expect(buscarParticipantes).toHaveBeenCalledWith('ana', false, 1));
        fireEvent.click(await screen.findByRole('button', { name: 'Abrir ficha' }));

        expect(navigate).toHaveBeenCalledWith('/admin/cerimonial/projetos/7?evento=1');
    });

    it('um termo curto demais não vai ao servidor', async () => {
        render(<CerimonialCheckin />);

        fireEvent.change(await screen.findByLabelText('Procurar por nome ou CPF'), {
            target: { value: 'a' },
        });

        await new Promise((r) => setTimeout(r, 400));
        expect(buscarParticipantes).not.toHaveBeenCalled();
    });

    it('quem já fez check-in é apontado na própria lista de busca', async () => {
        buscarParticipantes.mockResolvedValue([{ ...ACHADO, presente: true }]);
        render(<CerimonialCheckin />);

        fireEvent.change(await screen.findByLabelText('Procurar por nome ou CPF'), {
            target: { value: 'ana' },
        });

        expect(await screen.findByText('Já fez check-in')).toBeInTheDocument();
    });

    it('o crachá lido registra sem abrir a ficha, e a busca aberta já mostra a pessoa como presente', async () => {
        render(<CerimonialCheckin />);

        fireEvent.change(await screen.findByLabelText('Procurar por nome ou CPF'), {
            target: { value: 'ana' },
        });
        await screen.findByText('Ana Paula');
        expect(screen.queryByText('Já fez check-in')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'leitor-falso-1' }));

        expect(await screen.findByText('Já fez check-in')).toBeInTheDocument();
        expect(navigate).not.toHaveBeenCalled();
    });

    it('sem lista final vigente não há quem receber', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, lista: null });
        render(<CerimonialCheckin />);

        expect(await screen.findByText(/não há finalistas para receber/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Procurar por nome ou CPF')).not.toBeInTheDocument();
    });

    it('com um evento só ele já vem escolhido e aparece no título', async () => {
        render(<CerimonialCheckin />);

        expect(await screen.findByRole('heading', { name: 'Check-in · Premiação FETECMS' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'leitor-falso-1' })).toBeInTheDocument();
    });

    it('com dois eventos a porta escolhe — e a escolha fica lembrada no aparelho', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, eventos: [FETECMS, JR] });
        render(<CerimonialCheckin />);

        expect(await screen.findByText(/Escolha acima o evento/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /leitor-falso/ })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Procurar por nome ou CPF')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: /Premiação Jr/ }));

        expect(await screen.findByRole('button', { name: 'leitor-falso-2' })).toBeInTheDocument();
        expect(localStorage.getItem('fetec:cerimonial:evento:porta')).toBe('2');
    });

    it('o evento lembrado volta ao recarregar', async () => {
        localStorage.setItem('fetec:cerimonial:evento:porta', '2');
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, eventos: [FETECMS, JR] });
        render(<CerimonialCheckin />);

        expect(await screen.findByRole('button', { name: 'leitor-falso-2' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /Premiação Jr/ })).toHaveAttribute('aria-checked', 'true');
    });

    it('sem evento cadastrado avisa e leva ao cadastro', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, eventos: [] });
        render(<CerimonialCheckin />);

        expect(await screen.findByText(/Nenhum evento de cerimônia foi cadastrado/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Cadastrar os eventos' })).toHaveAttribute('href', '/admin/cerimonial/eventos');
        expect(screen.queryByRole('button', { name: /leitor-falso/ })).not.toBeInTheDocument();
    });
});

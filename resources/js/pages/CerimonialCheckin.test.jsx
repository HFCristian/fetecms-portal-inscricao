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
    default: ({ onAbrir }) => (
        <button type="button" onClick={() => onAbrir({ projeto_id: 7 })}>leitor-falso</button>
    ),
}));

const getCerimonialConfig = vi.fn();
const buscarParticipantes = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getCerimonialConfig: (...a) => getCerimonialConfig(...a),
    buscarParticipantes: (...a) => buscarParticipantes(...a),
}));

import CerimonialCheckin from './CerimonialCheckin.jsx';

const CONFIG = {
    aberto: true, iniciado: true, encerrado: false,
    inicio_label: '01/10/2026 08:00', fim_label: '03/10/2026 18:00',
    modo_teste: false, lista: { id: 1, nome: 'Oficial', demo: false },
};

const ACHADO = {
    projeto_id: 7, projeto_titulo: 'Bioplástico de mandioca', categoria: 'FETECMS',
    area: 'Agrárias', escola: 'EE Alfa / Campo Grande - MS',
    chave: 'A1', nome: 'Ana Paula', papel: 'A', papel_label: 'Aluno(a)', presente: false,
};

describe('CerimonialCheckin', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getCerimonialConfig.mockResolvedValue(CONFIG);
        buscarParticipantes.mockResolvedValue([ACHADO]);
    });

    it('a busca por nome abre a ficha do projeto da pessoa', async () => {
        render(<CerimonialCheckin />);

        fireEvent.change(await screen.findByLabelText('Procurar por nome ou CPF'), {
            target: { value: 'ana' },
        });

        await waitFor(() => expect(buscarParticipantes).toHaveBeenCalledWith('ana', false));
        fireEvent.click(await screen.findByRole('button', { name: 'Abrir ficha' }));

        expect(navigate).toHaveBeenCalledWith('/admin/cerimonial/projetos/7');
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

    it('o crachá lido leva à mesma ficha', async () => {
        render(<CerimonialCheckin />);

        fireEvent.click(await screen.findByRole('button', { name: 'leitor-falso' }));
        expect(navigate).toHaveBeenCalledWith('/admin/cerimonial/projetos/7');
    });

    it('sem lista final vigente não há quem receber', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, lista: null });
        render(<CerimonialCheckin />);

        expect(await screen.findByText(/não há finalistas para receber/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Procurar por nome ou CPF')).not.toBeInTheDocument();
    });
});

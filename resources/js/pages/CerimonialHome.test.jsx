import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const setTeste = vi.fn();
let modoTeste = false;
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [modoTeste, setTeste] }));

const getCerimonialConfig = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getCerimonialConfig: (...a) => getCerimonialConfig(...a),
}));

import CerimonialHome from './CerimonialHome.jsx';

const CONFIG = {
    aberto: true, iniciado: true, encerrado: false,
    inicio_label: '01/10/2026 08:00', fim_label: '03/10/2026 18:00',
    pode_testar: false, modo_teste: false, pode_gerir: true,
    atualizacao_segundos: 30,
    lista: { id: 1, nome: 'Oficial', versao: 1, demo: false },
};

describe('CerimonialHome', () => {
    beforeEach(() => {
        modoTeste = false;
        setTeste.mockClear();
        getCerimonialConfig.mockResolvedValue(CONFIG);
    });

    it('mostra as três seções para a organização', async () => {
        render(<CerimonialHome />);

        expect(await screen.findByText('Check-in')).toBeInTheDocument();
        expect(screen.getByText('Visão Geral')).toBeInTheDocument();
        expect(screen.getByText('Contas temporárias')).toBeInTheDocument();
    });

    it('conta temporária só enxerga o check-in', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, pode_gerir: false });
        render(<CerimonialHome />);

        expect(await screen.findByText('Check-in')).toBeInTheDocument();
        // Quem atende a porta não precisa saber quantas medalhas há na mesa.
        expect(screen.queryByText('Visão Geral')).not.toBeInTheDocument();
        expect(screen.queryByText('Contas temporárias')).not.toBeInTheDocument();
    });

    it('sem lista final vigente avisa que não há quem receber', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, lista: null });
        render(<CerimonialHome />);

        expect(await screen.findByText(/Nenhuma lista final oficial foi publicada/)).toBeInTheDocument();
    });

    it('fora da janela do evento diz quando abre', async () => {
        getCerimonialConfig.mockResolvedValue({ ...CONFIG, aberto: false, iniciado: false });
        render(<CerimonialHome />);

        expect(await screen.findByText(/O cerimonial abre em 01\/10\/2026 08:00/)).toBeInTheDocument();
    });

    it('o modo de teste só aparece para a conta demo', async () => {
        render(<CerimonialHome />);
        await waitFor(() => expect(getCerimonialConfig).toHaveBeenCalled());
        expect(screen.queryByText(/Modo demo/)).not.toBeInTheDocument();

        getCerimonialConfig.mockResolvedValue({ ...CONFIG, pode_testar: true });
        render(<CerimonialHome />);
        expect(await screen.findByText(/Modo demo \(teste do cerimonial\)/)).toBeInTheDocument();
    });
});

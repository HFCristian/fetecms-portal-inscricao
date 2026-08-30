import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const setTeste = vi.fn();
let modoTeste = false;
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [modoTeste, setTeste] }));

const resposta = (config) => ({
    data: [],
    meta: { resumo: { finalistas: 10, credenciados: 4, pendentes: 6 }, config },
});

const getFinalistas = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({ getFinalistas: (...a) => getFinalistas(...a) }));

import CredenciamentoHome from './CredenciamentoHome.jsx';

const CONFIG = {
    aberto: true, iniciado: true, encerrado: false,
    inicio_label: '01/10/2026 08:00', fim_label: '03/10/2026 18:00',
    pode_testar: false, modo_teste: false, itens: [],
    lista: { id: 1, nome: 'Oficial', versao: 1 },
};

describe('CredenciamentoHome', () => {
    beforeEach(() => {
        modoTeste = false;
        setTeste.mockClear();
        getFinalistas.mockResolvedValue(resposta(CONFIG));
    });

    it('mostra as duas seções com os números do balcão', async () => {
        render(<CredenciamentoHome />);

        expect(await screen.findByText('Credenciar')).toBeInTheDocument();
        expect(screen.getByText('Credenciados')).toBeInTheDocument();
        expect(screen.getByText('6')).toBeInTheDocument();
        expect(screen.getByText('4')).toBeInTheDocument();
    });

    it('avisa quando não há lista final oficial', async () => {
        getFinalistas.mockResolvedValue(resposta({ ...CONFIG, lista: null }));
        render(<CredenciamentoHome />);

        expect(await screen.findByText(/Nenhuma lista final oficial/)).toBeInTheDocument();
    });

    it('explica quando o credenciamento ainda não abriu', async () => {
        getFinalistas.mockResolvedValue(resposta({ ...CONFIG, aberto: false, iniciado: false }));
        render(<CredenciamentoHome />);

        expect(await screen.findByText(/O credenciamento abre em 01\/10\/2026 08:00/)).toBeInTheDocument();
    });

    it('só o admin demo vê o modo de teste', async () => {
        render(<CredenciamentoHome />);
        await screen.findByText('Credenciar');
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();

        getFinalistas.mockResolvedValue(resposta({ ...CONFIG, aberto: false, pode_testar: true }));
        render(<CredenciamentoHome />);

        const toggle = await screen.findByRole('switch');
        fireEvent.click(toggle);
        await waitFor(() => expect(setTeste).toHaveBeenCalledWith(true));
    });
});

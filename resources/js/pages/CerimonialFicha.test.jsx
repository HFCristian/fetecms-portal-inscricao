import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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

const getFichaCerimonial = vi.fn();
const registrarCheckin = vi.fn();
const desfazerCheckin = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getFichaCerimonial: (...a) => getFichaCerimonial(...a),
    registrarCheckin: (...a) => registrarCheckin(...a),
    desfazerCheckin: (...a) => desfazerCheckin(...a),
}));

import CerimonialFicha from './CerimonialFicha.jsx';

const FICHA = {
    projeto: { id: 7, titulo: 'Bioplástico de mandioca', categoria: 'FETECMS', area: 'Agrárias', escola: 'EE Alfa / Campo Grande - MS' },
    pessoas: [
        { chave: 'A1', papel: 'A', papel_label: 'Aluno(a)', nome: 'Ana Paula', presente: false, checkin_em: null, registrado_por: null },
        { chave: 'A2', papel: 'A', papel_label: 'Aluno(a)', nome: 'Bruno Lima', presente: true, checkin_em: '02/10/2026 19:10', registrado_por: 'Ana Admin' },
        { chave: 'O3', papel: 'O', papel_label: 'Orientador(a)', nome: 'Marta Orientadora', presente: false, checkin_em: null, registrado_por: null },
    ],
    presentes: 1,
    total: 3,
    premiacoes: [{ id: 1, nome: 'MOSTRATEC 2027', tipo: 'credencial', tipo_label: 'Credencial' }],
    credenciado: true,
};

const config = (over = {}) => ({ aberto: true, lista: { demo: false }, ...over });

describe('CerimonialFicha', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getFichaCerimonial.mockResolvedValue({ data: FICHA, meta: { config: config() } });
        registrarCheckin.mockResolvedValue({ ...FICHA, presentes: 3 });
        desfazerCheckin.mockResolvedValue({ ...FICHA, presentes: 0 });
    });

    it('mostra a equipe com quem já chegou', async () => {
        render(<CerimonialFicha />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getByText('1 de 3 presente(s)')).toBeInTheDocument();
        expect(screen.getByText(/Check-in em 02\/10\/2026 19:10/)).toBeInTheDocument();
        expect(screen.getByText('MOSTRATEC 2027')).toBeInTheDocument();
    });

    it('marca várias pessoas de uma vez — a equipe chega junta', async () => {
        render(<CerimonialFicha />);

        fireEvent.click(await screen.findByLabelText('Marcar check-in de Ana Paula'));
        fireEvent.click(screen.getByLabelText('Marcar check-in de Marta Orientadora'));
        fireEvent.click(screen.getByRole('button', { name: /Registrar check-in \(2\)/ }));

        await waitFor(() => expect(registrarCheckin).toHaveBeenCalledWith('7', ['A1', 'O3'], false));
    });

    it('quem já chegou não oferece caixa de marcar, e sim desfazer', async () => {
        render(<CerimonialFicha />);

        await screen.findByText('Bioplástico de mandioca');
        expect(screen.queryByLabelText('Marcar check-in de Bruno Lima')).not.toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Desfazer' })).toHaveLength(1);
    });

    it('desfazer só libera com justificativa', async () => {
        render(<CerimonialFicha />);

        fireEvent.click(await screen.findByRole('button', { name: 'Desfazer' }));
        const botao = screen.getByRole('button', { name: 'Desfazer check-in' });
        expect(botao).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Justificativa para desfazer o check-in'), {
            target: { value: 'Crachá lido por engano.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Desfazer check-in' }));

        await waitFor(() => expect(desfazerCheckin).toHaveBeenCalledWith(
            '7', 'A2', 'Crachá lido por engano.', false,
        ));
    });

    it('projeto sem credenciamento avisa, mas não impede o check-in', async () => {
        getFichaCerimonial.mockResolvedValue({
            data: { ...FICHA, credenciado: false },
            meta: { config: config() },
        });
        render(<CerimonialFicha />);

        expect(await screen.findByText(/ainda não passou pelo credenciamento/)).toBeInTheDocument();
        expect(screen.getByLabelText('Marcar check-in de Ana Paula')).not.toBeDisabled();
    });

    it('fora da janela do evento a ficha abre só para consulta', async () => {
        getFichaCerimonial.mockResolvedValue({
            data: FICHA,
            meta: { config: config({ aberto: false }) },
        });
        render(<CerimonialFicha />);

        expect(await screen.findByText(/abre só para consulta/)).toBeInTheDocument();
        expect(screen.getByLabelText('Marcar check-in de Ana Paula')).toBeDisabled();
        expect(screen.queryByRole('button', { name: /Registrar check-in/ })).not.toBeInTheDocument();
    });
});

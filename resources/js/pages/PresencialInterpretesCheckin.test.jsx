import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));
vi.mock('../lib/leitorCamera.js', () => ({ suporteCamera: () => ({ ok: false, motivo: '' }) }));

const getCheckinInterpretes = vi.fn();
const checkinInterpretePorCodigo = vi.fn();
const buscarInterpretesCheckin = vi.fn();
const registrarChegadaInterprete = vi.fn();
vi.mock('../lib/interpretes.js', async (importOriginal) => ({
    ...(await importOriginal()),
    getCheckinInterpretes: (...a) => getCheckinInterpretes(...a),
    checkinInterpretePorCodigo: (...a) => checkinInterpretePorCodigo(...a),
    buscarInterpretesCheckin: (...a) => buscarInterpretesCheckin(...a),
    registrarChegadaInterprete: (...a) => registrarChegadaInterprete(...a),
}));

import PresencialInterpretesCheckin from './PresencialInterpretesCheckin.jsx';

const CONFIG = {
    ocorrencias: [
        { chave: '2026-10-20|A', rotulo: '20/10 · Turno A (matutino) · 08:00–12:00', situacao: 'encerrado' },
        { chave: '2026-10-20|B', rotulo: '20/10 · Turno B (vespertino) · 13:00–17:00', situacao: 'em_andamento' },
    ],
    em_foco: '2026-10-20|B',
    motivo: null,
};

describe('PresencialInterpretesCheckin', () => {
    beforeEach(() => {
        getCheckinInterpretes.mockReset().mockResolvedValue(CONFIG);
        checkinInterpretePorCodigo.mockReset();
        buscarInterpretesCheckin.mockReset().mockResolvedValue([]);
        registrarChegadaInterprete.mockReset();
    });

    it('abre na ocorrência em andamento e registra pelo crachá', async () => {
        checkinInterpretePorCodigo.mockResolvedValue({
            ja_presente: false, checkin_em: '13:05',
            participante: { nome: 'Helena Souza', papel_label: 'Intérprete' },
            projeto_titulo: 'Libras · Estande 012',
        });

        render(<PresencialInterpretesCheckin />);

        expect(await screen.findByLabelText('Dia e turno')).toHaveValue('2026-10-20|B');
        const campo = await screen.findByLabelText('Código do crachá');
        fireEvent.change(campo, { target: { value: '2026-0-529-I7' } });
        fireEvent.keyDown(campo, { key: 'Enter' });

        await waitFor(() => expect(checkinInterpretePorCodigo).toHaveBeenCalledWith('2026-0-529-I7', { dia: '2026-10-20', turno: 'B' }));
        expect(await screen.findByText('Helena Souza')).toBeInTheDocument();
        expect(screen.getByText('Check-in feito')).toBeInTheDocument();
    });

    it('sem crachá, acha pelo nome e registra a chegada', async () => {
        buscarInterpretesCheckin.mockResolvedValue([{ id: 7, nome: 'Helena Souza', tipos_labels: ['Libras'], presente: false }]);
        registrarChegadaInterprete.mockResolvedValue({ ja_presente: false, checkin_em: '13:10' });

        render(<PresencialInterpretesCheckin />);
        await screen.findByLabelText('Dia e turno');

        fireEvent.change(screen.getByLabelText('Buscar intérprete por nome ou CPF'), { target: { value: 'hel' } });
        fireEvent.click(await screen.findByRole('button', { name: 'Registrar chegada de Helena Souza' }, { timeout: 2000 }));

        await waitFor(() => expect(registrarChegadaInterprete).toHaveBeenCalledWith(7, { dia: '2026-10-20', turno: 'B' }));
        expect(await screen.findByText('Chegada de Helena Souza registrada às 13:10.')).toBeInTheDocument();
    });

    it('sem o período do evento, explica o que falta', async () => {
        getCheckinInterpretes.mockResolvedValue({ ocorrencias: [], em_foco: null, motivo: 'Defina o período do evento.' });

        render(<PresencialInterpretesCheckin />);

        expect(await screen.findByText('Defina o período do evento.')).toBeInTheDocument();
        expect(screen.queryByLabelText('Código do crachá')).not.toBeInTheDocument();
    });
});

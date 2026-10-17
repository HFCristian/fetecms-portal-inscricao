import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));

const META = {
    turnos: [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }],
    por_folha: 16,
    maximo: 64,
};

const ANA = {
    grupo: 'participantes', id: '2026-31-529-A45', nome: 'Ana Aluna', papel_label: 'Aluno(a)',
    codigo: '2026-31-529-A45', turno: 'Turno A (matutino)', estande: 42, detalhe: 'Bioplástico',
};
const VERA = {
    grupo: 'avaliadores', id: 12, nome: 'Vera Avaliadora', papel_label: 'Avaliador(a)',
    codigo: '2026-0-390-V12', turno: null, estande: null, detalhe: 'Ciências Agrárias',
};

const buscarEtiquetasAvulsas = vi.fn((grupo) => Promise.resolve({
    data: grupo === 'avaliadores' ? [VERA] : [ANA],
    meta: META,
}));
const baixarEtiquetasAvulsas = vi.fn(() => Promise.resolve());
vi.mock('../lib/credenciamento.js', () => ({
    buscarEtiquetasAvulsas: (...a) => buscarEtiquetasAvulsas(...a),
    baixarEtiquetasAvulsas: (...a) => baixarEtiquetasAvulsas(...a),
}));

import CredenciamentoEtiquetas from './CredenciamentoEtiquetas.jsx';

describe('CredenciamentoEtiquetas', () => {
    beforeEach(() => {
        buscarEtiquetasAvulsas.mockClear();
        baixarEtiquetasAvulsas.mockReset();
        baixarEtiquetasAvulsas.mockResolvedValue();
    });

    it('busca na lista final e mostra o que a etiqueta leva', async () => {
        render(<CredenciamentoEtiquetas />);

        expect(await screen.findByText('Ana Aluna')).toBeInTheDocument();
        expect(screen.getByText('Turno A (matutino) · Estande 42 · 2026-31-529-A45')).toBeInTheDocument();
        expect(buscarEtiquetasAvulsas).toHaveBeenCalledWith('participantes', '', false);
    });

    it('junta pessoas de grupos diferentes e uma personalizada, a partir da posição escolhida', async () => {
        render(<CredenciamentoEtiquetas />);

        fireEvent.click(await screen.findByRole('button', { name: 'Adicionar etiqueta de Ana Aluna' }));

        fireEvent.click(screen.getByRole('tab', { name: /Avaliadores/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Adicionar etiqueta de Vera Avaliadora' }));

        fireEvent.click(screen.getByRole('tab', { name: /Personalizada/ }));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Convidado Especial' } });
        fireEvent.change(screen.getByLabelText('Papel'), { target: { value: 'Palestrante' } });
        fireEvent.change(screen.getByLabelText('Turno'), { target: { value: 'B' } });
        fireEvent.change(screen.getByLabelText('Estande'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: /Adicionar à folha/ }));

        fireEvent.click(screen.getByRole('radio', { name: 'Posição 5' }));
        fireEvent.click(screen.getByRole('button', { name: /Gerar PDF/ }));

        await waitFor(() => expect(baixarEtiquetasAvulsas).toHaveBeenCalledWith({
            inicio: 5,
            itens: [
                { grupo: 'participantes', id: '2026-31-529-A45' },
                { grupo: 'avaliadores', id: 12 },
                { grupo: 'personalizada', nome: 'Convidado Especial', papel: 'Palestrante', turno: 'B', estande: 7, codigo: null },
            ],
        }, false));
        expect(await screen.findByText(/PDF gerado com 3 etiquetas/)).toBeInTheDocument();
    });

    it('personalizada sem nome não entra na folha', async () => {
        render(<CredenciamentoEtiquetas />);
        await screen.findByText('Ana Aluna');

        fireEvent.click(screen.getByRole('tab', { name: /Personalizada/ }));
        expect(screen.getByRole('button', { name: /Adicionar à folha/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Gerar PDF/ })).toBeDisabled();
    });

    it('remove uma etiqueta da folha', async () => {
        render(<CredenciamentoEtiquetas />);

        fireEvent.click(await screen.findByRole('button', { name: 'Adicionar etiqueta de Ana Aluna' }));
        expect(screen.getByRole('button', { name: /Gerar PDF/ })).toBeEnabled();

        fireEvent.click(screen.getByRole('button', { name: 'Remover etiqueta de Ana Aluna' }));
        expect(screen.getByRole('button', { name: /Gerar PDF/ })).toBeDisabled();
    });

    it('a recusa do servidor diz de qual etiqueta é', async () => {
        baixarEtiquetasAvulsas.mockRejectedValue(Object.assign(
            new Error('Este participante não está mais na lista final vigente.'),
            { indice: 0 },
        ));
        render(<CredenciamentoEtiquetas />);

        fireEvent.click(await screen.findByRole('button', { name: 'Adicionar etiqueta de Ana Aluna' }));
        fireEvent.click(screen.getByRole('button', { name: /Gerar PDF/ }));

        expect(await screen.findByText('Ana Aluna: Este participante não está mais na lista final vigente.')).toBeInTheDocument();
    });
});

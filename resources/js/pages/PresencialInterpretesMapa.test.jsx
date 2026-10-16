import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: {} }),
}));

const getMapaInterpretes = vi.fn();
const designarInterprete = vi.fn();
vi.mock('../lib/interpretes.js', async (importOriginal) => ({
    ...(await importOriginal()),
    getMapaInterpretes: (...a) => getMapaInterpretes(...a),
    designarInterprete: (...a) => designarInterprete(...a),
}));

import PresencialInterpretesMapa from './PresencialInterpretesMapa.jsx';

const interprete = (vinculos = []) => ({
    id: 7, nome: 'Helena Souza', tipos_labels: ['Libras'], vinculos, locais: [], locais_label: '',
});

const mapa = (vinculos = []) => ({
    layout: { estandes: [{ numero: 1, x: 0, y: 0 }, { numero: 2, x: 1, y: 0 }, { numero: 3, x: 2, y: 0 }] },
    ruas: [],
    estandes_gerados: true,
    ocupacao: {
        1: { numero: 1, A: { projeto_id: 31, titulo: 'Bioplástico' }, B: null },
        2: { numero: 2, A: { projeto_id: 32, titulo: 'Sensor' }, B: null },
    },
    interpretes: [interprete(vinculos)],
});

describe('PresencialInterpretesMapa', () => {
    beforeEach(() => {
        getMapaInterpretes.mockReset().mockResolvedValue(mapa());
        designarInterprete.mockReset();
    });

    it('sem intérprete escolhido, clicar no estande pede a escolha', async () => {
        render(<PresencialInterpretesMapa />);

        fireEvent.click(await screen.findByRole('button', { name: /Estande 001/ }));

        expect(screen.getByText(/Escolha um intérprete na lista/)).toBeInTheDocument();
        expect(designarInterprete).not.toHaveBeenCalled();
    });

    it('escolhido o intérprete, clicar no estande o designa no turno', async () => {
        designarInterprete.mockResolvedValue(interprete([{
            id: 9, projeto_id: 31, projeto_titulo: 'Bioplástico', codigo: null, pessoa_tipo: null, origem: 'mapa', turno: 'A', numero: 1,
        }]));

        render(<PresencialInterpretesMapa />);

        fireEvent.click(await screen.findByRole('option', { name: /Helena Souza/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Estande 001 · Bioplástico' }));

        await waitFor(() => expect(designarInterprete).toHaveBeenCalledWith(7, { numero: 1, turno: 'A', designado: true }));
        expect(await screen.findByRole('button', { name: 'Estande 001 · Bioplástico · designado' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('clicar no estande já designado pelo mapa tira a designação', async () => {
        getMapaInterpretes.mockResolvedValue(mapa([{
            id: 9, projeto_id: 31, projeto_titulo: 'Bioplástico', codigo: null, pessoa_tipo: null, origem: 'mapa', turno: 'A', numero: 1,
        }]));
        designarInterprete.mockResolvedValue(interprete([]));

        render(<PresencialInterpretesMapa />);
        fireEvent.click(await screen.findByRole('option', { name: /Helena Souza/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Estande 001 · Bioplástico · designado' }));

        await waitFor(() => expect(designarInterprete).toHaveBeenCalledWith(7, { numero: 1, turno: 'A', designado: false }));
    });

    it('vínculo do cadastro não se desfaz pelo mapa', async () => {
        getMapaInterpretes.mockResolvedValue(mapa([{
            id: 9, projeto_id: 32, projeto_titulo: 'Sensor', codigo: null, pessoa_tipo: 'aluno', pessoa_nome: 'Ana',
            pessoa_papel: 'Estudante', origem: 'cadastro', turno: 'A', numero: 2,
        }]));

        render(<PresencialInterpretesMapa />);
        fireEvent.click(await screen.findByRole('option', { name: /Helena Souza/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Estande 002 · Sensor' }));

        expect(screen.getByText(/já atende “Sensor” pelo cadastro/)).toBeInTheDocument();
        expect(designarInterprete).not.toHaveBeenCalled();
    });

    it('estande vazio no turno não recebe designação', async () => {
        render(<PresencialInterpretesMapa />);
        fireEvent.click(await screen.findByRole('option', { name: /Helena Souza/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Estande 003 · vazio' }));

        expect(screen.getByText(/está vazio no turno A/)).toBeInTheDocument();
        expect(designarInterprete).not.toHaveBeenCalled();
    });
});

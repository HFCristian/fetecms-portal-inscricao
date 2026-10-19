import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getFotografiasEstandes = vi.fn();
const reservarFotografiaEstande = vi.fn();
const cancelarFotografiaEstande = vi.fn();
const enviarFotografiaEstande = vi.fn();
const excluirFotografiaEstande = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getFotografiasEstandes: (...a) => getFotografiasEstandes(...a),
    reservarFotografiaEstande: (...a) => reservarFotografiaEstande(...a),
    cancelarFotografiaEstande: (...a) => cancelarFotografiaEstande(...a),
    enviarFotografiaEstande: (...a) => enviarFotografiaEstande(...a),
    excluirFotografiaEstande: (...a) => excluirFotografiaEstande(...a),
}));

import CerimonialFotografias from './CerimonialFotografias.jsx';

const linha = (over = {}) => ({
    numero: 42, estande: '042', turno: 'A', turno_label: 'Turno A (matutino)', projeto_id: 7,
    titulo: 'Bioplástico', codigo: 'FET.AGR-001', area: 'Agrárias', categoria: 'FETECMS',
    situacao: 'sem_registro', em_registro: null, foto: null, ...over,
});

const mapa = (estandes) => ({
    turno: 'A', turno_label: 'Turno A (matutino)',
    turnos: [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }],
    layout: [{ numero: 42, x: 0, y: 0 }, { numero: 43, x: 1, y: 0 }, { numero: 44, x: 2, y: 0 }],
    estandes,
    fora_da_planta: [],
    resumo: {
        registrado: Object.values(estandes).filter((l) => l.situacao === 'registrado').length,
        em_registro: Object.values(estandes).filter((l) => l.situacao === 'em_registro').length,
        sem_registro: Object.values(estandes).filter((l) => l.situacao === 'sem_registro').length,
    },
    total: Object.keys(estandes).length,
    minutos_reserva: 15,
    max_mb: 100,
});

const FOTO = {
    id: 3, url: '/api/v1/admin/cerimonial/fotografias/3/arquivo?v=1', nome_original: 'estande.jpg',
    tamanho: 2048, registrado_por: 'Ana', registrado_em_label: '20/10/2026 09:40', pode_excluir: true,
};

describe('CerimonialFotografias', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        globalThis.URL.createObjectURL = vi.fn(() => 'blob:previa');
        globalThis.URL.revokeObjectURL = vi.fn();
        getFotografiasEstandes.mockResolvedValue(mapa({
            42: linha(),
            43: linha({ numero: 43, estande: '043', projeto_id: 8, titulo: 'Horta', situacao: 'registrado', foto: FOTO }),
            44: linha({ numero: 44, estande: '044', projeto_id: 9, titulo: 'Drone', situacao: 'em_registro', em_registro: { por: 'Bruno', meu: false, desde_label: '09:30' } }),
        }));
        reservarFotografiaEstande.mockResolvedValue(linha({ situacao: 'em_registro', em_registro: { por: 'Eu', meu: true, desde_label: '10:00' } }));
        cancelarFotografiaEstande.mockResolvedValue({});
        enviarFotografiaEstande.mockResolvedValue({ data: linha({ situacao: 'registrado', foto: FOTO }), meta: { message: 'Fotografia do estande registrada.' } });
        excluirFotografiaEstande.mockResolvedValue({});
    });

    it('pinta o mapa com registrado, em registro e sem registro, com as contagens', async () => {
        render(<CerimonialFotografias />);

        const legenda = within(await screen.findByRole('list', { name: 'Legenda' }));
        expect(legenda.getByText('Registrado').parentElement).toHaveTextContent('(1)');
        expect(legenda.getByText('Em registro').parentElement).toHaveTextContent('(1)');
        expect(legenda.getByText('Sem registro').parentElement).toHaveTextContent('(1)');
        expect(screen.getByRole('button', { name: 'Estande 042 · Bioplástico · Sem registro' })).toBeInTheDocument();
    });

    it('confirma o estande, escolhe o arquivo, mostra a prévia e só então registra', async () => {
        render(<CerimonialFotografias />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 042 · Bioplástico · Sem registro' }));
        const confirmar = within(screen.getByRole('dialog', { name: 'Registrar fotografia do estande?' }));
        expect(confirmar.getByText(/Você vai fotografar/)).toHaveTextContent('estande 042');
        fireEvent.click(confirmar.getByRole('button', { name: 'Sim, estou no estande 042' }));

        await waitFor(() => expect(reservarFotografiaEstande).toHaveBeenCalledWith(7));
        const escolher = within(await screen.findByRole('dialog', { name: 'Fotografia do estande' }));
        expect(escolher.getByRole('button', { name: /Tirar foto com a câmera/ })).toBeInTheDocument();
        expect(escolher.getByLabelText('Câmera do aparelho')).toHaveAttribute('capture', 'environment');

        const arquivo = new File(['x'], 'estande.jpg', { type: 'image/jpeg' });
        fireEvent.change(escolher.getByLabelText('Arquivo da fotografia'), { target: { files: [arquivo] } });

        const previa = within(await screen.findByRole('dialog', { name: 'Confira a fotografia' }));
        expect(previa.getByAltText('Prévia da fotografia do estande 042')).toHaveAttribute('src', 'blob:previa');
        expect(enviarFotografiaEstande).not.toHaveBeenCalled();

        fireEvent.click(previa.getByRole('button', { name: 'Confirmar e registrar' }));
        await waitFor(() => expect(enviarFotografiaEstande).toHaveBeenCalledWith(7, arquivo, expect.any(Function)));
        expect(await screen.findByText(/Fotografia do estande registrada/)).toBeInTheDocument();
    });

    it('recusa arquivo acima de 100 MB antes de enviar', async () => {
        render(<CerimonialFotografias />);
        fireEvent.click(await screen.findByRole('button', { name: 'Estande 042 · Bioplástico · Sem registro' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sim, estou no estande 042' }));
        const escolher = within(await screen.findByRole('dialog', { name: 'Fotografia do estande' }));

        const grande = new File(['x'], 'grande.jpg', { type: 'image/jpeg' });
        Object.defineProperty(grande, 'size', { value: 101 * 1024 * 1024 });
        fireEvent.change(escolher.getByLabelText('Arquivo da fotografia'), { target: { files: [grande] } });

        expect(await escolher.findByText(/o limite é 100 MB/)).toBeInTheDocument();
        expect(screen.queryByRole('dialog', { name: 'Confira a fotografia' })).not.toBeInTheDocument();
    });

    it('cancelar depois de confirmar devolve o estande', async () => {
        render(<CerimonialFotografias />);
        fireEvent.click(await screen.findByRole('button', { name: 'Estande 042 · Bioplástico · Sem registro' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sim, estou no estande 042' }));
        const escolher = within(await screen.findByRole('dialog', { name: 'Fotografia do estande' }));

        fireEvent.click(escolher.getByRole('button', { name: 'Cancelar' }));

        await waitFor(() => expect(cancelarFotografiaEstande).toHaveBeenCalledWith(7));
    });

    it('o estande em registro por outra pessoa só avisa quem está fotografando', async () => {
        render(<CerimonialFotografias />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 044 · Drone · Em registro' }));

        expect(await screen.findByText(/Bruno está registrando a fotografia desde as 09:30/)).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('a foto registrada abre para ver, substituir ou excluir', async () => {
        render(<CerimonialFotografias />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 043 · Horta · Registrado' }));
        const detalhe = within(screen.getByRole('dialog', { name: 'Estande 043' }));
        expect(detalhe.getByAltText('Fotografia do estande 043')).toHaveAttribute('src', FOTO.url);
        expect(detalhe.getByText(/Registrada por Ana em 20\/10\/2026 09:40/)).toBeInTheDocument();

        fireEvent.click(detalhe.getByRole('button', { name: /Excluir/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Excluir' }));

        await waitFor(() => expect(excluirFotografiaEstande).toHaveBeenCalledWith(3));
        expect(await screen.findByText(/fotografia excluída/)).toBeInTheDocument();
    });

    it('trocar o turno recarrega o mapa daquele turno', async () => {
        render(<CerimonialFotografias />);
        await screen.findByRole('list', { name: 'Legenda' });

        fireEvent.click(screen.getByRole('radio', { name: 'Turno B (vespertino)' }));

        await waitFor(() => expect(getFotografiasEstandes).toHaveBeenLastCalledWith('B'));
    });
});

import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const navigate = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => ({ ...(await importOriginal()), useNavigate: () => navigate }));

const getListasCertificados = vi.fn();
vi.mock('../lib/certificados.js', () => ({ getListasCertificados: (...a) => getListasCertificados(...a) }));

import AdminCertificados from './AdminCertificados.jsx';

const GERADA = {
    id: 3, nome: 'Certificados online', status: 'gerada', passo: 3, fases: ['online'], publicos: ['Avaliadores Online', 'Orientadores'],
    total_pessoas: 42, total_certificados: 57, gerada_em: '2026-10-24T14:00:00-04:00', gerada_por: 'Olga Organizadora',
    criada_por: 'Olga Organizadora', atualizada_em: '2026-10-24T14:00:00-04:00', desatualizada: true,
};
const RASCUNHO = { ...GERADA, id: 4, nome: 'Presencial', status: 'rascunho', fases: ['presencial'], publicos: [], gerada_em: null, gerada_por: null, desatualizada: false };

const tela = () => render(<MemoryRouter><AdminCertificados /></MemoryRouter>);

describe('AdminCertificados', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getListasCertificados.mockResolvedValue([GERADA, RASCUNHO]);
    });

    it('o botão grande abre o assistente', async () => {
        tela();
        fireEvent.click(screen.getByRole('button', { name: /Gerar certificados/ }));
        expect(navigate).toHaveBeenCalledWith('/admin/certificados/nova');
    });

    it('lista as geradas com público, pessoas e quem gerou, e o rascunho para continuar', async () => {
        tela();

        expect(await screen.findByText('Certificados online')).toBeInTheDocument();
        expect(screen.getByText('Avaliadores Online, Orientadores')).toBeInTheDocument();
        expect(screen.getByText('42')).toBeInTheDocument();
        expect(screen.getByText('Olga Organizadora')).toBeInTheDocument();
        expect(screen.getByText('Alterada')).toBeInTheDocument();
        expect(screen.getByText('Rascunho')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Editar Certificados online' }));
        expect(navigate).toHaveBeenCalledWith('/admin/certificados/listas/3/assistente');
        fireEvent.click(screen.getByRole('button', { name: /Abrir/ }));
        expect(navigate).toHaveBeenCalledWith('/admin/certificados/listas/3');
        expect(screen.getByRole('button', { name: 'Editar Presencial' })).toHaveTextContent('Continuar');
    });
});

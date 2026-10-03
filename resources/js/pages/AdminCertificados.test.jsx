import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const OPCOES = {
    grupos: [
        { valor: 'estudantes', rotulo: 'Estudantes' },
        { valor: 'orientadores', rotulo: 'Orientadores' },
        { valor: 'coorientadores', rotulo: 'Coorientadores' },
        { valor: 'organizacao', rotulo: 'Organização (administradores)' },
    ],
    fases: [
        { valor: 'online', rotulo: 'Fase online' },
        { valor: 'presencial', rotulo: 'Fase presencial' },
        { valor: 'todas', rotulo: 'As duas fases' },
    ],
    tem_lista_final: true,
};

const getAvaliadoresCertificado = vi.fn();
const baixarAvaliadoresCertificado = vi.fn(() => Promise.resolve());
const baixarParticipantesCertificado = vi.fn(() => Promise.resolve());
const baixarDeclaracaoAvaliador = vi.fn(() => Promise.resolve());
vi.mock('../lib/certificados.js', () => ({
    getOpcoesCertificados: () => Promise.resolve(OPCOES),
    getAvaliadoresCertificado: (...a) => getAvaliadoresCertificado(...a),
    getProjetosDoAvaliador: () => Promise.resolve({
        online: [{ projeto_id: 1, titulo: 'Biofiltro', area: 'Agrárias', concluida_em: '01/10/2026' }],
        presencial: [],
    }),
    baixarAvaliadoresCertificado: (...a) => baixarAvaliadoresCertificado(...a),
    baixarParticipantesCertificado: (...a) => baixarParticipantesCertificado(...a),
    baixarAvaliacoesNominais: vi.fn(),
    baixarDeclaracaoAvaliador: (...a) => baixarDeclaracaoAvaliador(...a),
}));

import AdminCertificados from './AdminCertificados.jsx';

describe('AdminCertificados', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getAvaliadoresCertificado.mockResolvedValue([
            { id: 4, nome: 'Ana Avaliadora', cpf: '529.982.247-25', email: 'ana@x.test', area: 'Agrárias', online: 2, presencial: 1 },
        ]);
    });

    it('lista os avaliadores com as fases separadas e baixa a planilha da fase online', async () => {
        render(<AdminCertificados />);

        expect(await screen.findByText('Ana Avaliadora')).toBeInTheDocument();
        expect(getAvaliadoresCertificado).toHaveBeenCalledWith('online', '');

        fireEvent.click(screen.getAllByRole('button', { name: /Excel/ })[0]);
        await waitFor(() => expect(baixarAvaliadoresCertificado).toHaveBeenCalledWith('online', 'xlsx'));
    });

    it('abre os projetos avaliados e baixa o relatório nominal', async () => {
        render(<AdminCertificados />);

        fireEvent.click(await screen.findByRole('button', { name: 'Projetos avaliados' }));
        expect(await screen.findByText(/Biofiltro/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Relatório nominal/ }));
        expect(baixarDeclaracaoAvaliador).toHaveBeenCalledWith(4);
    });

    it('exporta os participantes dos grupos marcados', async () => {
        render(<AdminCertificados />);

        fireEvent.click(await screen.findByLabelText('Organização (administradores)'));
        fireEvent.click(screen.getByLabelText('todos os projetos submetidos'));
        fireEvent.click(screen.getAllByRole('button', { name: /CSV/ })[1]);

        await waitFor(() => expect(baixarParticipantesCertificado).toHaveBeenCalledWith(
            ['estudantes', 'orientadores', 'coorientadores', 'organizacao'], 'submetidos', 'csv',
        ));
    });
});

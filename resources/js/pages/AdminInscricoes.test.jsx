import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'Falhou.', fields: {} }) }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getInscricoesConfig = vi.fn();
const definirPrazoInscricoes = vi.fn();
const getAvisoOpcoes = vi.fn();
const getAvisoAtivoAdmin = vi.fn();
const previaAviso = vi.fn();
const publicarAviso = vi.fn();
const encerrarAviso = vi.fn();
const getAvisos = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getInscricoesConfig: (...a) => getInscricoesConfig(...a),
    definirPrazoInscricoes: (...a) => definirPrazoInscricoes(...a),
    getAvisoOpcoes: (...a) => getAvisoOpcoes(...a),
    getAvisoAtivoAdmin: (...a) => getAvisoAtivoAdmin(...a),
    previaAviso: (...a) => previaAviso(...a),
    publicarAviso: (...a) => publicarAviso(...a),
    encerrarAviso: (...a) => encerrarAviso(...a),
    getAvisos: (...a) => getAvisos(...a),
}));

const OPCOES = {
    variaveis: [
        { chave: 'prazo_inscricoes', rotulo: 'Prazo das inscrições', descricao: 'A data-limite.' },
        { chave: 'tempo_restante', rotulo: 'Tempo restante', descricao: 'Quanto falta.' },
        { chave: 'inicio_avaliacoes', rotulo: 'Início das avaliações', descricao: 'A liberação.' },
    ],
    modelo: { titulo: 'As inscrições estão se encerrando', mensagem: 'Faltam {{tempo_restante}}.' },
    inscricoes: { encerradas: false, prazo_label: '30/09/2026 23:59' },
    destinatarios: 42,
};

import AdminInscricoes from './AdminInscricoes.jsx';

const semPrazo = { encerradas: false, prazo_input: null, prazo_label: null, minutos_restantes: null };
const comPrazo = { encerradas: false, prazo_input: '2026-09-30T23:59', prazo_label: '30/09/2026 23:59', minutos_restantes: 120 };
const encerradas = { ...comPrazo, encerradas: true, minutos_restantes: null };

describe('AdminInscricoes', () => {
    beforeEach(() => {
        getInscricoesConfig.mockReset();
        definirPrazoInscricoes.mockReset();
        getAvisoOpcoes.mockReset();
        getAvisoAtivoAdmin.mockReset();
        previaAviso.mockReset();
        publicarAviso.mockReset();
        encerrarAviso.mockReset();
        getInscricoesConfig.mockResolvedValue(semPrazo);
        getAvisoOpcoes.mockResolvedValue(OPCOES);
        getAvisoAtivoAdmin.mockResolvedValue(null);
        getAvisos.mockReset();
        getAvisos.mockResolvedValue({ data: [], meta: { pagina: 1, ultima_pagina: 1, total: 0 } });
    });

    it('mostra "sem prazo definido" quando as inscrições não têm data-limite', async () => {
        render(<AdminInscricoes />);

        expect(await screen.findByText('Sem prazo definido')).toBeInTheDocument();
        // Sem data preenchida não há o que salvar nem o que remover.
        expect(screen.getByText('Salvar prazo').closest('button')).toBeDisabled();
        expect(screen.queryByText('Remover')).not.toBeInTheDocument();
    });

    it('mostra a data marcada enquanto o prazo não venceu', async () => {
        getInscricoesConfig.mockResolvedValue(comPrazo);
        render(<AdminInscricoes />);

        expect(await screen.findByText('Encerra em 30/09/2026 23:59')).toBeInTheDocument();
        expect(screen.getByLabelText('Data-limite de submissão')).toHaveValue('2026-09-30T23:59');
    });

    it('avisa quando o prazo já venceu', async () => {
        getInscricoesConfig.mockResolvedValue(encerradas);
        render(<AdminInscricoes />);

        expect(await screen.findByText('Inscrições encerradas')).toBeInTheDocument();
    });

    it('salva a data-limite digitada', async () => {
        definirPrazoInscricoes.mockResolvedValue(comPrazo);
        render(<AdminInscricoes />);

        const campo = await screen.findByLabelText('Data-limite de submissão');
        fireEvent.change(campo, { target: { value: '2026-09-30T23:59' } });
        fireEvent.click(screen.getByText('Salvar prazo'));

        await waitFor(() => expect(definirPrazoInscricoes).toHaveBeenCalledWith('2026-09-30T23:59'));
        expect(await screen.findByText('Prazo de inscrição salvo.')).toBeInTheDocument();
        expect(screen.getByText('Encerra em 30/09/2026 23:59')).toBeInTheDocument();
    });

    it('remove o prazo e volta a deixar as inscrições abertas', async () => {
        getInscricoesConfig.mockResolvedValue(comPrazo);
        definirPrazoInscricoes.mockResolvedValue(semPrazo);
        render(<AdminInscricoes />);

        fireEvent.click(await screen.findByText('Remover'));

        await waitFor(() => expect(definirPrazoInscricoes).toHaveBeenCalledWith(null));
        expect(await screen.findByText('Sem prazo definido')).toBeInTheDocument();
    });

    it('avisa quando não consegue salvar', async () => {
        definirPrazoInscricoes.mockRejectedValue(new Error('falhou'));
        render(<AdminInscricoes />);

        const campo = await screen.findByLabelText('Data-limite de submissão');
        fireEvent.change(campo, { target: { value: '2026-09-30T23:59' } });
        fireEvent.click(screen.getByText('Salvar prazo'));

        expect(await screen.findByText('Não foi possível salvar. Tente novamente.')).toBeInTheDocument();
    });
});

describe('AdminInscricoes — aviso na tela', () => {
    beforeEach(() => {
        getInscricoesConfig.mockReset();
        definirPrazoInscricoes.mockReset();
        getAvisoOpcoes.mockReset();
        getAvisoAtivoAdmin.mockReset();
        previaAviso.mockReset();
        publicarAviso.mockReset();
        encerrarAviso.mockReset();
        getInscricoesConfig.mockResolvedValue(semPrazo);
        getAvisoOpcoes.mockResolvedValue(OPCOES);
        getAvisoAtivoAdmin.mockResolvedValue(null);
        getAvisos.mockReset();
        getAvisos.mockResolvedValue({ data: [], meta: { pagina: 1, ultima_pagina: 1, total: 0 } });
    });

    it('abre com o modelo pronto e diz quantos recebem', async () => {
        render(<AdminInscricoes />);

        expect(await screen.findByLabelText('Título')).toHaveValue('As inscrições estão se encerrando');
        expect(screen.getByLabelText('Mensagem')).toHaveValue('Faltam {{tempo_restante}}.');
        expect(screen.getByText('Nenhum aviso no ar')).toBeInTheDocument();
        expect(screen.getByText(/42/)).toBeInTheDocument();
    });

    it('insere a variável na posição do cursor', async () => {
        render(<AdminInscricoes />);

        const campo = await screen.findByLabelText('Mensagem');
        fireEvent.change(campo, { target: { value: 'Encerra em .' } });
        campo.setSelectionRange(11, 11); // logo antes do ponto final

        fireEvent.click(screen.getByText('{{prazo_inscricoes}}'));

        expect(campo).toHaveValue('Encerra em {{prazo_inscricoes}}.');
    });

    it('mostra a prévia com as variáveis resolvidas', async () => {
        previaAviso.mockResolvedValue({ titulo: 'Atenção', mensagem: 'Faltam 45 minutos.', destinatarios: 42 });
        render(<AdminInscricoes />);

        fireEvent.click(await screen.findByText('Ver prévia'));

        expect(await screen.findByText('Faltam 45 minutos.')).toBeInTheDocument();
        await waitFor(() => expect(previaAviso).toHaveBeenCalled());
    });

    it('publica o aviso e passa a mostrá-lo como "no ar"', async () => {
        publicarAviso.mockResolvedValue({
            data: { id: 3, titulo: 'Atenção', mensagem: 'Faltam 45 minutos.', publicado_em: '22/08/2026 19:00' },
            meta: { message: 'Aviso publicado. Ele aparece para os orientadores conectados.' },
        });
        render(<AdminInscricoes />);

        fireEvent.click(await screen.findByText('Publicar aviso'));
        // Confirmação antes de ir para a tela de todo mundo.
        fireEvent.click(await screen.findByText('Publicar'));

        await waitFor(() => expect(publicarAviso).toHaveBeenCalledWith({
            titulo: 'As inscrições estão se encerrando',
            mensagem: 'Faltam {{tempo_restante}}.',
        }));
        expect(await screen.findByText('No ar agora')).toBeInTheDocument();
        expect(screen.getByText('Um aviso no ar')).toBeInTheDocument();
    });

    it('encerra o aviso que está no ar', async () => {
        getAvisoAtivoAdmin.mockResolvedValue({ id: 3, titulo: 'Atenção', mensagem: 'Faltam 45 minutos.', publicado_em: '22/08/2026 19:00' });
        encerrarAviso.mockResolvedValue({ meta: { message: 'Aviso encerrado.' } });
        render(<AdminInscricoes />);

        fireEvent.click(await screen.findByText('Encerrar aviso'));
        fireEvent.click(await screen.findByText('Encerrar'));

        await waitFor(() => expect(encerrarAviso).toHaveBeenCalledWith(3));
        expect(await screen.findByText('Nenhum aviso no ar')).toBeInTheDocument();
        expect(screen.queryByText('No ar agora')).not.toBeInTheDocument();
    });

    it('não deixa publicar com título ou mensagem em branco', async () => {
        render(<AdminInscricoes />);

        fireEvent.change(await screen.findByLabelText('Título'), { target: { value: '   ' } });

        expect(screen.getByText('Publicar aviso').closest('button')).toBeDisabled();
        expect(screen.getByText('Ver prévia').closest('button')).toBeDisabled();
    });
});

describe('AdminInscricoes — histórico de avisos', () => {
    beforeEach(() => {
        getInscricoesConfig.mockReset();
        getAvisoOpcoes.mockReset();
        getAvisoAtivoAdmin.mockReset();
        publicarAviso.mockReset();
        getAvisos.mockReset();
        getInscricoesConfig.mockResolvedValue(semPrazo);
        getAvisoOpcoes.mockResolvedValue(OPCOES);
        getAvisoAtivoAdmin.mockResolvedValue(null);
        getAvisos.mockResolvedValue({
            data: [
                { id: 9, titulo: 'Segundo aviso', autor_nome: 'Admin', publicado_em: '22/08/2026 20:00', ativo: true, vistos: 5, fechados: 2, nao_vistos: 37 },
                { id: 8, titulo: 'Primeiro aviso', autor_nome: 'Admin', publicado_em: '21/08/2026 09:00', ativo: false, vistos: 40, fechados: 30, nao_vistos: 2 },
            ],
            meta: { pagina: 1, ultima_pagina: 1, total: 2 },
        });
    });

    it('lista os avisos publicados com quem viu e quem fechou', async () => {
        render(<AdminInscricoes />);

        expect(await screen.findByText('Segundo aviso')).toBeInTheDocument();
        expect(screen.getByText('Primeiro aviso')).toBeInTheDocument();
        expect(screen.getAllByText('Viram').length).toBe(2);
        expect(screen.getByText('40')).toBeInTheDocument();
        expect(screen.getByText('30')).toBeInTheDocument();
    });

    it('leva ao relatório de cada aviso', async () => {
        render(<AdminInscricoes />);

        const link = (await screen.findByText('Segundo aviso')).closest('a');
        expect(link).toHaveAttribute('href', '/admin/inscricoes/avisos/9');
    });

    it('recarrega o histórico depois de publicar', async () => {
        publicarAviso.mockResolvedValue({
            data: { id: 10, titulo: 'Novo', mensagem: 'Texto', publicado_em: '22/08/2026 21:00' },
            meta: { message: 'Aviso publicado.' },
        });
        render(<AdminInscricoes />);

        await screen.findByText('Segundo aviso');
        expect(getAvisos).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByText('Publicar aviso'));
        fireEvent.click(await screen.findByText('Publicar'));

        await waitFor(() => expect(getAvisos).toHaveBeenCalledTimes(2));
    });
});

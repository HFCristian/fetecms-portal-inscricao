import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'Falhou.', fields: {} }) }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getAvisoOpcoes = vi.fn();
const getAvisosVigentes = vi.fn();
const previaAviso = vi.fn();
const publicarAviso = vi.fn();
const encerrarAviso = vi.fn();
const getAvisos = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getAvisoOpcoes: (...a) => getAvisoOpcoes(...a),
    getAvisosVigentes: (...a) => getAvisosVigentes(...a),
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
    publico_padrao: ['orientadores'],
    publicos: [
        { value: 'orientadores', label: 'Todos os orientadores', descricao: 'Toda conta de orientador ativa.' },
        { value: 'avaliadores_comissao', label: 'Avaliadores da comissão especial', descricao: 'Marcado pelo admin.' },
    ],
};

/** Um aviso no ar, como o backend o devolve para a tela do admin. */
const noAr = (over = {}) => ({
    id: 3, titulo: 'Atenção', mensagem: 'Faltam 45 minutos.', publicado_em: '22/08/2026 19:00',
    publicos: [{ value: 'orientadores', label: 'Todos os orientadores' }],
    expira_em_label: null, ativo: true, ...over,
});

import AdminAvisos from './AdminAvisos.jsx';

describe('AdminAvisos — aviso na tela', () => {
    beforeEach(() => {
        getAvisoOpcoes.mockReset();
        getAvisosVigentes.mockReset();
        previaAviso.mockReset();
        publicarAviso.mockReset();
        encerrarAviso.mockReset();
        getAvisoOpcoes.mockResolvedValue(OPCOES);
        getAvisosVigentes.mockResolvedValue([]);
        getAvisos.mockReset();
        getAvisos.mockResolvedValue({ data: [], meta: { pagina: 1, ultima_pagina: 1, total: 0 } });
    });

    it('abre com o modelo pronto e diz quantos recebem', async () => {
        render(<AdminAvisos />);

        expect(await screen.findByLabelText('Título')).toHaveValue('As inscrições estão se encerrando');
        expect(screen.getByLabelText('Mensagem')).toHaveValue('Faltam {{tempo_restante}}.');
        expect(screen.getByText('Nenhum aviso no ar')).toBeInTheDocument();
        expect(screen.getByText(/42/)).toBeInTheDocument();
    });

    it('insere a variável na posição do cursor', async () => {
        render(<AdminAvisos />);

        const campo = await screen.findByLabelText('Mensagem');
        fireEvent.change(campo, { target: { value: 'Encerra em .' } });
        campo.setSelectionRange(11, 11); // logo antes do ponto final

        fireEvent.click(screen.getByText('{{prazo_inscricoes}}'));

        expect(campo).toHaveValue('Encerra em {{prazo_inscricoes}}.');
    });

    it('mostra a prévia com as variáveis resolvidas', async () => {
        previaAviso.mockResolvedValue({ titulo: 'Atenção', mensagem: 'Faltam 45 minutos.', destinatarios: 42 });
        render(<AdminAvisos />);

        fireEvent.click(await screen.findByText('Ver prévia'));

        expect(await screen.findByText('Faltam 45 minutos.')).toBeInTheDocument();
        await waitFor(() => expect(previaAviso).toHaveBeenCalled());
    });

    it('publica o aviso e passa a mostrá-lo como "no ar"', async () => {
        publicarAviso.mockResolvedValue({
            data: noAr(),
            meta: { message: 'Aviso publicado. Ele aparece para quem está no público escolhido.' },
        });
        getAvisosVigentes.mockResolvedValueOnce([]).mockResolvedValue([noAr()]);
        render(<AdminAvisos />);

        fireEvent.click(await screen.findByText('Publicar aviso'));
        // Confirmação antes de ir para a tela de todo mundo.
        fireEvent.click(await screen.findByText('Publicar'));

        await waitFor(() => expect(publicarAviso).toHaveBeenCalledWith({
            titulo: 'As inscrições estão se encerrando',
            mensagem: 'Faltam {{tempo_restante}}.',
            publicos: ['orientadores'],
            expira_em: null,
        }));
        expect(await screen.findByText('No ar agora')).toBeInTheDocument();
        expect(screen.getByText('Um aviso no ar')).toBeInTheDocument();
    });

    it('encerra o aviso que está no ar', async () => {
        getAvisosVigentes.mockResolvedValue([noAr()]);
        encerrarAviso.mockResolvedValue({ meta: { message: 'Aviso encerrado.' } });
        render(<AdminAvisos />);

        fireEvent.click(await screen.findByText('Encerrar aviso'));
        fireEvent.click(await screen.findByText('Encerrar'));

        await waitFor(() => expect(encerrarAviso).toHaveBeenCalledWith(3));
        expect(await screen.findByText('Nenhum aviso no ar')).toBeInTheDocument();
        expect(screen.queryByText('No ar agora')).not.toBeInTheDocument();
    });

    it('escolhe os públicos e a data de expiração', async () => {
        previaAviso.mockResolvedValue({ titulo: 'Atenção', mensagem: 'Texto.', destinatarios: 7 });
        publicarAviso.mockResolvedValue({ data: noAr(), meta: { message: 'Aviso publicado.' } });
        render(<AdminAvisos />);

        // Abre com o público padrão marcado.
        const padrao = await screen.findByRole('checkbox', { name: /Todos os orientadores/ });
        expect(padrao).toBeChecked();

        fireEvent.click(screen.getByRole('checkbox', { name: /comissão especial/ }));
        fireEvent.change(screen.getByLabelText('Expira em (opcional)'), { target: { value: '2026-10-01T18:00' } });
        fireEvent.click(screen.getByText('Publicar aviso'));
        fireEvent.click(await screen.findByText('Publicar'));

        await waitFor(() => expect(publicarAviso).toHaveBeenCalledWith(expect.objectContaining({
            publicos: ['orientadores', 'avaliadores_comissao'],
            expira_em: '2026-10-01T18:00',
        })));
    });

    it('não deixa publicar sem nenhum público', async () => {
        render(<AdminAvisos />);

        fireEvent.click(await screen.findByRole('checkbox', { name: /Todos os orientadores/ }));

        expect(screen.getByText('Escolha ao menos um público.')).toBeInTheDocument();
        expect(screen.getByText('Publicar aviso').closest('button')).toBeDisabled();
    });

    it('não deixa publicar com título ou mensagem em branco', async () => {
        render(<AdminAvisos />);

        fireEvent.change(await screen.findByLabelText('Título'), { target: { value: '   ' } });

        expect(screen.getByText('Publicar aviso').closest('button')).toBeDisabled();
        expect(screen.getByText('Ver prévia').closest('button')).toBeDisabled();
    });
});

describe('AdminAvisos — histórico de avisos', () => {
    beforeEach(() => {
        getAvisoOpcoes.mockReset();
        getAvisosVigentes.mockReset();
        publicarAviso.mockReset();
        getAvisos.mockReset();
        getAvisoOpcoes.mockResolvedValue(OPCOES);
        getAvisosVigentes.mockResolvedValue([]);
        getAvisos.mockResolvedValue({
            data: [
                { id: 9, titulo: 'Segundo aviso', autor_nome: 'Admin', publicado_em: '22/08/2026 20:00', ativo: true, vistos: 5, fechados: 2, nao_vistos: 37 },
                { id: 8, titulo: 'Primeiro aviso', autor_nome: 'Admin', publicado_em: '21/08/2026 09:00', ativo: false, vistos: 40, fechados: 30, nao_vistos: 2 },
            ],
            meta: { pagina: 1, ultima_pagina: 1, total: 2 },
        });
    });

    it('lista os avisos publicados com quem viu e quem fechou', async () => {
        render(<AdminAvisos />);

        expect(await screen.findByText('Segundo aviso')).toBeInTheDocument();
        expect(screen.getByText('Primeiro aviso')).toBeInTheDocument();
        expect(screen.getAllByText('Viram').length).toBe(2);
        expect(screen.getByText('40')).toBeInTheDocument();
        expect(screen.getByText('30')).toBeInTheDocument();
    });

    it('leva ao relatório de cada aviso', async () => {
        render(<AdminAvisos />);

        const link = (await screen.findByText('Segundo aviso')).closest('a');
        expect(link).toHaveAttribute('href', '/admin/comunicacao/avisos/9');
    });

    it('recarrega o histórico depois de publicar', async () => {
        publicarAviso.mockResolvedValue({
            data: noAr({ id: 10, titulo: 'Novo', mensagem: 'Texto', publicado_em: '22/08/2026 21:00' }),
            meta: { message: 'Aviso publicado.' },
        });
        render(<AdminAvisos />);

        await screen.findByText('Segundo aviso');
        expect(getAvisos).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByText('Publicar aviso'));
        fireEvent.click(await screen.findByText('Publicar'));

        await waitFor(() => expect(getAvisos).toHaveBeenCalledTimes(2));
    });
});

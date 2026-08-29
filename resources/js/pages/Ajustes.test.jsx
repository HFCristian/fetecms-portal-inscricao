import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getAjustes = vi.fn();
const getAjustesProjeto = vi.fn();
const decidirAjuste = vi.fn();

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));
vi.mock('../lib/ajustes.js', () => ({
    getAjustes: (...a) => getAjustes(...a),
    getAjustesProjeto: (...a) => getAjustesProjeto(...a),
    decidirAjuste: (...a) => decidirAjuste(...a),
}));

import Ajustes from './Ajustes.jsx';

const PROJETO = {
    id: 7, titulo: 'Bioplástico de mandioca', area: 'Ciências Exatas e da Terra', subarea: null,
    sugestoes: 1, pendentes: 1, aceitas: 0, recomendacoes: 1,
};

const DETALHE = {
    id: 7, titulo: 'Bioplástico de mandioca', area: 'Ciências Exatas e da Terra', subarea: null,
    sugestoes: [{
        avaliacao_id: 3, avaliador: 'Avaliador 1', tipo: 'area', tipo_label: 'Área do conhecimento',
        atual: 'Ciências Exatas e da Terra', sugerido: 'Ciências Agrárias', sugerido_id: 2,
        aceito: false, decidido_em: null,
    }],
    recomendacoes: [{
        avaliacao_id: 3, avaliador: 'Avaliador 1', tipo: 'video',
        titulo: 'Sobre o vídeo', texto: 'Melhore o áudio.',
    }],
};

const ABERTA = { aberta: true, iniciada: true, encerrada: false, de_label: '01/09/2026 08:00', ate_label: '10/09/2026 23:59', is_demo: false, modo_teste: false };

describe('Ajustes — orientador', () => {
    beforeEach(() => {
        getAjustes.mockReset();
        getAjustesProjeto.mockReset();
        decidirAjuste.mockReset();
        getAjustes.mockResolvedValue({ janela: ABERTA, projetos: [PROJETO] });
        getAjustesProjeto.mockResolvedValue(DETALHE);
    });

    it('lista os projetos e abre as sugestões do escolhido', async () => {
        render(<Ajustes />);

        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));

        expect(await screen.findByText('Ciências Agrárias')).toBeInTheDocument();
        expect(screen.getByText('Área do conhecimento · Avaliador 1')).toBeInTheDocument();
        // As recomendações escritas aparecem só para leitura.
        expect(screen.getByText('Melhore o áudio.')).toBeInTheDocument();
        expect(screen.getByText('Aceitar')).toBeInTheDocument();
    });

    it('aceita a sugestão e ela continua na tela, agora marcada', async () => {
        decidirAjuste.mockResolvedValue({
            data: {
                ...DETALHE,
                area: 'Ciências Agrárias',
                sugestoes: [{ ...DETALHE.sugestoes[0], aceito: true, decidido_em: '2026-09-02T10:00:00-04:00' }],
            },
            meta: { message: 'Sugestão aceita — a classificação do projeto foi atualizada.' },
        });

        render(<Ajustes />);
        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));
        fireEvent.click(await screen.findByText('Aceitar'));

        await waitFor(() => expect(decidirAjuste).toHaveBeenCalledWith(
            7, { avaliacao_id: 3, tipo: 'area', aceito: true }, false,
        ));

        expect(await screen.findByText('Em vigor')).toBeInTheDocument();
        // Continua listada, agora com a opção de desfazer.
        expect(screen.getByText('Desfazer')).toBeInTheDocument();
    });

    it('fora do período explica que a aba está fechada', async () => {
        getAjustes.mockResolvedValue({
            janela: { ...ABERTA, aberta: false, iniciada: false, encerrada: false },
            projetos: [],
        });
        render(<Ajustes />);

        expect(await screen.findByText('O período de ajustes ainda não começou')).toBeInTheDocument();
        expect(screen.queryByText('Bioplástico de mandioca')).not.toBeInTheDocument();
    });

    it('orientador demo tem o modo de teste para ver a aba fora do período', async () => {
        getAjustes.mockResolvedValue({
            janela: { ...ABERTA, aberta: false, iniciada: false, is_demo: true },
            projetos: [],
        });
        render(<Ajustes />);

        expect(await screen.findByText('Modo de teste')).toBeInTheDocument();

        getAjustes.mockResolvedValue({ janela: { ...ABERTA, is_demo: true, modo_teste: true }, projetos: [PROJETO] });
        fireEvent.click(screen.getByRole('switch'));

        await waitFor(() => expect(getAjustes).toHaveBeenLastCalledWith(true));
        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
    });
});

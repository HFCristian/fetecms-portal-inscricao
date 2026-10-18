import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const baixarPlanilhaLote = vi.fn();
const previaLotePresencial = vi.fn();
const enviarLotePresencial = vi.fn();
vi.mock('../lib/avaliacaoPresencial.js', () => ({
    baixarPlanilhaLote: (...a) => baixarPlanilhaLote(...a),
    previaLotePresencial: (...a) => previaLotePresencial(...a),
    enviarLotePresencial: (...a) => enviarLotePresencial(...a),
}));

import LoteAvaliacaoPresencial from './LoteAvaliacaoPresencial.jsx';

const PREVIA = {
    linhas: [
        { linha: 2, projeto_id: 7, titulo: 'Abelhas nativas', codigo: 'FET.AGR-001', estande: 41, respostas: { apresentacao: 8 }, comentario: 'Bom.', nota: 8.3, erros: [] },
        { linha: 3, projeto_id: 8, titulo: 'Horta vertical', codigo: null, estande: 42, respostas: {}, comentario: null, nota: null,
            erros: ['6.1: "7" não está na escala — use 0, 2, 4, 6, 8 ou 10.', 'Faltam respostas: 7.1.'] },
    ],
    resumo: { validas: 1, com_erro: 1, em_branco: 2 },
    envio: { aberto: true, comite: true, mensagem: null },
};

const subir = () => {
    const nome = new File(['x'], 'avaliacoes.xlsx');
    fireEvent.change(screen.getByLabelText('Planilha preenchida'), { target: { files: [nome] } });
    return nome;
};

describe('LoteAvaliacaoPresencial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        previaLotePresencial.mockResolvedValue(PREVIA);
    });

    it('baixa a planilha dos designados', async () => {
        baixarPlanilhaLote.mockResolvedValue();
        render(<LoteAvaliacaoPresencial teste />);

        fireEvent.click(screen.getByRole('button', { name: /Baixar planilha/ }));
        await waitFor(() => expect(baixarPlanilhaLote).toHaveBeenCalledWith(true));
    });

    it('a prévia mostra cada erro por extenso e envia só as linhas prontas', async () => {
        enviarLotePresencial.mockResolvedValue({
            data: { linhas: [{ linha: 2, projeto_id: 7, titulo: 'Abelhas nativas', situacao: 'enviada', nota: 8.3, mensagem: 'Avaliação enviada.' }] },
            meta: { message: '1 avaliação(ões) enviada(s).' },
        });
        const onEnviado = vi.fn();
        render(<LoteAvaliacaoPresencial onEnviado={onEnviado} />);

        const arquivo = subir();
        await waitFor(() => expect(previaLotePresencial).toHaveBeenCalledWith(arquivo, false));
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações pela planilha' });

        expect(dialogo).toHaveTextContent('1 linha(s) pronta(s) para enviar · 1 com erro · 2 em branco');
        const erros = within(dialogo).getByRole('region', { name: 'Linhas com erro' });
        expect(erros).toHaveTextContent('Linha 3 · Horta vertical · Estande 042');
        expect(within(erros).getByText('6.1: "7" não está na escala — use 0, 2, 4, 6, 8 ou 10.')).toBeInTheDocument();
        expect(within(erros).getByText('Faltam respostas: 7.1.')).toBeInTheDocument();
        expect(within(dialogo).getByRole('region', { name: 'Linhas prontas' })).toHaveTextContent('FET.AGR-001 · Abelhas nativas · Estande 041');

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Enviar 1 avaliação(ões)' }));
        await waitFor(() => expect(enviarLotePresencial).toHaveBeenCalledWith(
            [{ linha: 2, projeto_id: 7, respostas: { apresentacao: 8 }, comentario: 'Bom.' }], false,
        ));
        expect(await within(dialogo).findByText('Avaliação enviada.')).toBeInTheDocument();
        expect(within(dialogo).getByText(/Enviada · 8,300/)).toBeInTheDocument();
        expect(onEnviado).toHaveBeenCalledWith('1 avaliação(ões) enviada(s).');
    });

    it('cabeçalho errado: o motivo aparece sem abrir a prévia', async () => {
        previaLotePresencial.mockRejectedValue({ fields: { arquivo: 'Faltam colunas na planilha: 6.1.' } });
        render(<LoteAvaliacaoPresencial />);

        subir();
        expect(await screen.findByText('Faltam colunas na planilha: 6.1.')).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('comitê fora do horário: avisa e guarda como rascunho', async () => {
        previaLotePresencial.mockResolvedValue({
            ...PREVIA,
            envio: { aberto: false, comite: true, mensagem: 'Fora do horário de envio: as linhas válidas ficam salvas como rascunho.' },
        });
        render(<LoteAvaliacaoPresencial />);

        subir();
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações pela planilha' });
        expect(dialogo).toHaveTextContent('as linhas válidas ficam salvas como rascunho');
        expect(within(dialogo).getByRole('button', { name: 'Salvar 1 como rascunho' })).toBeEnabled();
    });

    it('avaliador comum fora do turno não consegue enviar', async () => {
        previaLotePresencial.mockResolvedValue({
            ...PREVIA,
            envio: { aberto: false, comite: false, mensagem: 'Fora do horário de avaliação dos turnos: nenhuma avaliação é enviada agora.' },
        });
        render(<LoteAvaliacaoPresencial />);

        subir();
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações pela planilha' });
        expect(within(dialogo).getByRole('button', { name: 'Enviar 1 avaliação(ões)' })).toBeDisabled();
    });
});

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const baixarProjetosPesquisa = vi.fn();
vi.mock('../lib/admin.js', () => ({
    baixarProjetosPesquisa: (...a) => baixarProjetosPesquisa(...a),
}));

import ExportarProjetosPesquisa from './ExportarProjetosPesquisa.jsx';

const itens = [
    { projeto_id: 1, codigo: 'FET.AGR-001', titulo: 'Milho resistente', categoria: 'FETECMS', area: 'Ciências Agrárias' },
    { projeto_id: 2, codigo: 'FET.BIO-001', titulo: 'Peixes do Pantanal', categoria: 'FETECMS', area: 'Ciências Biológicas' },
    { projeto_id: 3, codigo: 'JR.AGR-001', titulo: 'Horta escolar', categoria: 'FETEC Jr', area: 'Ciências Agrárias' },
    { projeto_id: 4, codigo: 'PIC.BIO-001', titulo: 'Abelhas nativas', categoria: 'FETECMS FUNDECT', area: 'Ciências Biológicas' },
];

function abrirSelecao() {
    render(<ExportarProjetosPesquisa listaId={7} itens={itens} />);
    fireEvent.click(screen.getByRole('button', { name: /Escolher projetos/ }));
}

const botaoSelecionados = () => screen.getByRole('button', { name: /Baixar selecionados/ });

describe('ExportarProjetosPesquisa', () => {
    beforeEach(() => {
        baixarProjetosPesquisa.mockReset().mockResolvedValue();
    });

    it('baixa a lista inteira sem mandar ids', async () => {
        render(<ExportarProjetosPesquisa listaId={7} itens={itens} />);

        fireEvent.click(screen.getByRole('button', { name: /Baixar todos \(4\)/ }));

        await waitFor(() => expect(baixarProjetosPesquisa).toHaveBeenCalledWith(7, null));
    });

    it('marca por categoria', async () => {
        abrirSelecao();
        expect(botaoSelecionados()).toBeDisabled();

        fireEvent.click(screen.getByRole('checkbox', { name: /^FETECMS \(2\)/ }));
        expect(botaoSelecionados()).toHaveTextContent('Baixar selecionados (2)');

        fireEvent.click(botaoSelecionados());
        await waitFor(() => expect(baixarProjetosPesquisa).toHaveBeenCalledWith(7, [1, 2]));
    });

    it('marca por área, somando à categoria, e desmarca projeto a projeto', async () => {
        abrirSelecao();

        fireEvent.click(screen.getByRole('checkbox', { name: /^FETEC Jr \(1\)/ }));
        // A área de Biológicas atravessa duas categorias.
        fireEvent.click(screen.getByRole('checkbox', { name: /^Ciências Biológicas \(2\)/ }));
        expect(botaoSelecionados()).toHaveTextContent('(3)');

        fireEvent.click(screen.getByRole('checkbox', { name: /Peixes do Pantanal/ }));
        // A categoria FETECMS ficou pela metade: nem marcada nem desmarcada.
        const fetecms = screen.getByRole('checkbox', { name: /^FETECMS \(2\)/ });
        expect(fetecms).not.toBeChecked();
        expect(fetecms.indeterminate).toBe(false);

        fireEvent.click(botaoSelecionados());
        await waitFor(() => expect(baixarProjetosPesquisa).toHaveBeenCalledWith(7, [3, 4]));
    });

    it('grupo pela metade fica parcial', () => {
        abrirSelecao();

        fireEvent.click(screen.getByRole('checkbox', { name: /Milho resistente/ }));

        expect(screen.getByRole('checkbox', { name: /^FETECMS \(2\)/ }).indeterminate).toBe(true);
        expect(screen.getByRole('checkbox', { name: /^Ciências Agrárias \(2\)/ }).indeterminate).toBe(true);
    });

    it('mostra o motivo quando o servidor recusa', async () => {
        baixarProjetosPesquisa.mockRejectedValue(new Error('Nenhum dos projetos escolhidos tem o arquivo do projeto de pesquisa disponível.'));
        render(<ExportarProjetosPesquisa listaId={7} itens={itens} />);

        fireEvent.click(screen.getByRole('button', { name: /Baixar todos \(4\)/ }));

        expect(await screen.findByText(/Nenhum dos projetos escolhidos tem o arquivo/)).toBeInTheDocument();
    });

    it('lista vazia não mostra nada', () => {
        const { container } = render(<ExportarProjetosPesquisa listaId={7} itens={[]} />);
        expect(container).toBeEmptyDOMElement();
    });
});

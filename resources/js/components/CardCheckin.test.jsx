import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import CardCheckin, { DURACAO_CARD_MS } from './CardCheckin.jsx';

const NOVO = {
    id: 1, tipo: 'novo', nome: 'Ana Paula', papel_label: 'Aluno(a)',
    projeto_titulo: 'Bioplástico de mandioca', checkin_em: '14:32',
};

describe('CardCheckin', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('mostra o nome de quem entrou e some sozinho em 2 segundos', () => {
        const onFechar = vi.fn();
        render(<CardCheckin leitura={NOVO} onFechar={onFechar} />);

        expect(screen.getByText('Ana Paula')).toBeInTheDocument();
        expect(screen.getByText('Check-in feito')).toBeInTheDocument();
        expect(DURACAO_CARD_MS).toBe(2000);

        act(() => vi.advanceTimersByTime(1999));
        expect(onFechar).not.toHaveBeenCalled();
        act(() => vi.advanceTimersByTime(1));
        expect(onFechar).toHaveBeenCalledTimes(1);
    });

    it('a barra que enche o botão Ok dura o mesmo tempo do card', () => {
        render(<CardCheckin leitura={NOVO} onFechar={vi.fn()} />);

        const barra = screen.getByTestId('progresso-card');
        expect(screen.getByRole('button', { name: 'Ok' })).toContainElement(barra);
        expect(barra).toHaveClass('fetec-preencher');
        expect(barra.style.animationDuration).toBe('2000ms');
    });

    it('Ok fecha antes do tempo', () => {
        const onFechar = vi.fn();
        render(<CardCheckin leitura={NOVO} onFechar={onFechar} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ok' }));
        expect(onFechar).toHaveBeenCalledTimes(1);
    });

    it('clicar no Ok não tira o foco do campo do leitor', () => {
        render(<CardCheckin leitura={NOVO} onFechar={vi.fn()} />);

        const evento = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        screen.getByRole('button', { name: 'Ok' }).dispatchEvent(evento);
        expect(evento.defaultPrevented).toBe(true);
    });

    it('quem já tinha entrado aparece com a hora, em outro tom', () => {
        render(<CardCheckin leitura={{ ...NOVO, tipo: 'repetido' }} onFechar={vi.fn()} />);

        expect(screen.getByText('Já tinha feito check-in')).toBeInTheDocument();
        expect(screen.getByText('Entrou às 14:32.')).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveClass('bg-amber-100');
    });

    it('uma leitura nova reinicia o relógio', () => {
        const onFechar = vi.fn();
        const { rerender } = render(<CardCheckin leitura={NOVO} onFechar={onFechar} />);

        act(() => vi.advanceTimersByTime(1500));
        rerender(<CardCheckin leitura={{ ...NOVO, id: 2, nome: 'Bruno Lima' }} onFechar={onFechar} />);
        act(() => vi.advanceTimersByTime(1500));

        expect(onFechar).not.toHaveBeenCalled();
        expect(screen.getByText('Bruno Lima')).toBeInTheDocument();
        act(() => vi.advanceTimersByTime(500));
        expect(onFechar).toHaveBeenCalledTimes(1);
    });

    it('crachá recusado fica na tela até alguém clicar em Ok', () => {
        const onFechar = vi.fn();
        render(
            <CardCheckin
                leitura={{ id: 3, tipo: 'erro', mensagem: 'Este código não é de um projeto da lista final vigente.' }}
                onFechar={onFechar}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Crachá não aceito');
        expect(screen.getByText('Este código não é de um projeto da lista final vigente.')).toBeInTheDocument();
        expect(screen.queryByTestId('progresso-card')).not.toBeInTheDocument();

        act(() => vi.advanceTimersByTime(10000));
        expect(onFechar).not.toHaveBeenCalled();
    });

    it('lista os outros papéis da mesma pessoa marcados junto', () => {
        render(
            <CardCheckin
                leitura={{
                    ...NOVO,
                    vinculos: [
                        { projeto_id: 9, chave: 'C4', projeto_titulo: 'Robô seguidor de linha', papel_label: 'Coorientador(a)', ja_presente: false },
                        { projeto_id: 11, chave: 'O2', projeto_titulo: 'Horta vertical', papel_label: 'Orientador(a)', ja_presente: true },
                    ],
                }}
                onFechar={vi.fn()}
            />,
        );

        expect(screen.getByText('Entrada também registrada como Coorientador(a) de “Robô seguidor de linha”.')).toBeInTheDocument();
        expect(screen.getByText('Já constava também como Orientador(a) de “Horta vertical”.')).toBeInTheDocument();
    });
});

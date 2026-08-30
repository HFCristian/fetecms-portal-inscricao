import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// O campo de endereço real depende do Google Places; aqui vale texto puro.
vi.mock('./EnderecoAutocomplete.jsx', () => ({
    default: ({ id, valor, onChange, placeholder }) => (
        <input
            id={id}
            aria-label={id}
            placeholder={placeholder}
            value={valor?.nome ?? ''}
            onChange={(e) => onChange({ nome: e.target.value, lat: null, lng: null })}
        />
    ),
}));

import LocalizadorWizard from './LocalizadorWizard.jsx';

const TRANSPORTES = [
    { value: 'carro', label: 'Carro' },
    { value: 'van', label: 'Van' },
    { value: 'a_pe', label: 'A pé' },
];

/** Anda o assistente até o último passo com um preenchimento mínimo. */
function preencher({ pessoas = 2, destino = 'UFMS' } = {}) {
    fireEvent.change(screen.getByLabelText('Quantidade de pessoas'), { target: { value: String(pessoas) } });
    fireEvent.click(screen.getByText('Continuar'));               // → nomes
    fireEvent.click(screen.getByText('Continuar'));               // → transporte
    fireEvent.change(screen.getByLabelText('Meio de transporte'), { target: { value: 'van' } });
    fireEvent.click(screen.getByText('Continuar'));               // → partida
    fireEvent.click(screen.getByText('Continuar'));               // → destino
    fireEvent.change(screen.getByLabelText('comite-destino'), { target: { value: destino } });
    fireEvent.change(screen.getByLabelText('Minutos com o localizador ligado'), { target: { value: '90' } });
    fireEvent.click(screen.getByText('Continuar'));               // → localização
}

describe('LocalizadorWizard', () => {
    beforeEach(() => {
        // Geolocalização do aparelho, autorizada.
        navigator.geolocation = {
            getCurrentPosition: (ok) => ok({ coords: { latitude: -20.47, longitude: -54.62 } }),
        };
    });

    it('percorre os seis passos na ordem combinada', () => {
        render(<LocalizadorWizard transportes={TRANSPORTES} onIniciar={vi.fn()} onFechar={vi.fn()} />);

        expect(screen.getByText('Quantas pessoas estão com você?')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Continuar'));
        expect(screen.getByText('Quem está com você? (opcional)')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Continuar'));
        expect(screen.getByText('Como vocês estão indo?')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Continuar'));
        expect(screen.getByText('De onde vocês estão saindo? (opcional)')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Continuar'));
        expect(screen.getByText('Destino final')).toBeInTheDocument();
    });

    it('o campo de nomes acompanha a quantidade de pessoas', () => {
        render(<LocalizadorWizard transportes={TRANSPORTES} onIniciar={vi.fn()} onFechar={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Quantidade de pessoas'), { target: { value: '3' } });
        fireEvent.click(screen.getByText('Continuar'));

        expect(screen.getByLabelText('Nome da pessoa 1')).toBeInTheDocument();
        expect(screen.getByLabelText('Nome da pessoa 3')).toBeInTheDocument();
        expect(screen.queryByLabelText('Nome da pessoa 4')).not.toBeInTheDocument();
    });

    it('exige o destino para avançar e a permissão para iniciar', () => {
        render(<LocalizadorWizard transportes={TRANSPORTES} onIniciar={vi.fn()} onFechar={vi.fn()} />);

        fireEvent.click(screen.getByText('3. Transporte'));
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.click(screen.getByText('Continuar'));
        // Sem destino, não passa do passo 5.
        expect(screen.getByText('Continuar')).toBeDisabled();

        fireEvent.change(screen.getByLabelText('comite-destino'), { target: { value: 'UFMS' } });
        fireEvent.click(screen.getByText('Continuar'));

        // Sem permissão do aparelho, não inicia.
        expect(screen.getByRole('button', { name: /Iniciar localizador/ })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: /Permitir localização/ }));
        expect(screen.getByRole('button', { name: /Iniciar localizador/ })).toBeEnabled();
    });

    it('monta o payload com tudo o que foi coletado', async () => {
        const onIniciar = vi.fn();
        render(<LocalizadorWizard transportes={TRANSPORTES} onIniciar={onIniciar} onFechar={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Quantidade de pessoas'), { target: { value: '2' } });
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.change(screen.getByLabelText('Nome da pessoa 1'), { target: { value: 'Bia' } });
        fireEvent.change(screen.getByLabelText('Área da pessoa 1'), { target: { value: 'Agrárias' } });
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.change(screen.getByLabelText('Meio de transporte'), { target: { value: 'van' } });
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.change(screen.getByLabelText('comite-origem'), { target: { value: 'Aeroporto' } });
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.change(screen.getByLabelText('comite-destino'), { target: { value: 'UFMS' } });
        fireEvent.change(screen.getByLabelText('Minutos com o localizador ligado'), { target: { value: '90' } });
        fireEvent.click(screen.getByText('Continuar'));

        fireEvent.click(screen.getByRole('button', { name: /Permitir localização/ }));
        fireEvent.click(screen.getByRole('button', { name: /Iniciar localizador/ }));

        await waitFor(() => expect(onIniciar).toHaveBeenCalled());
        const [payload, posicao] = onIniciar.mock.calls[0];

        expect(payload).toMatchObject({
            pessoas: 2,
            transporte: 'van',
            origem_nome: 'Aeroporto',
            destino_nome: 'UFMS',
            minutos: 90,
        });
        expect(payload.acompanhantes[0]).toEqual({ nome: 'Bia', area: 'Agrárias' });
        // A segunda pessoa ficou em branco — o servidor descarta.
        expect(payload.acompanhantes[1]).toEqual({ nome: '', area: '' });
        expect(posicao).toEqual({ lat: -20.47, lng: -54.62 });
    });

    it('avisa quando o aparelho recusa a localização', () => {
        navigator.geolocation = { getCurrentPosition: (_ok, erro) => erro({ code: 1 }) };
        render(<LocalizadorWizard transportes={TRANSPORTES} onIniciar={vi.fn()} onFechar={vi.fn()} />);

        preencher();
        fireEvent.click(screen.getByRole('button', { name: /Permitir localização/ }));

        expect(screen.getByText(/Não foi possível obter a localização/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Iniciar localizador/ })).toBeDisabled();
    });
});

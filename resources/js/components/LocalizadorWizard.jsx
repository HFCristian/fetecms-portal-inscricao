import { useState } from 'react';
import { Alert, Button, Field, Input, Select } from '../components/ui.jsx';
import EnderecoAutocomplete from './EnderecoAutocomplete.jsx';

const PASSOS = ['Pessoas', 'Nomes', 'Transporte', 'Partida', 'Destino', 'Localização'];

const MAX_PESSOAS = 99;

/** Ajusta a lista de acompanhantes ao número informado, preservando o que já foi digitado. */
function redimensionar(lista, quantidade) {
    const nova = lista.slice(0, quantidade);
    while (nova.length < quantidade) nova.push({ nome: '', area: '' });
    return nova;
}

/**
 * "Habilitar localização": o assistente que liga o localizador do comitê.
 *
 * Seis passos, na ordem em que a organização pediu: **quantas pessoas** estão
 * junto, **os nomes** (opcionais, com a área de cada uma), o **meio de
 * transporte**, o **ponto de partida**, o **destino + por quanto tempo** o
 * localizador fica ligado e, por fim, a **permissão de localização** do
 * aparelho — que é o único passo que não dá para adiar: sem ela não há
 * localizador.
 */
export default function LocalizadorWizard({ transportes = [], minMinutos = 5, maxMinutos = 720, salvando, erro, onIniciar, onFechar }) {
    const [passo, setPasso] = useState(0);
    const [pessoas, setPessoas] = useState(1);
    const [acompanhantes, setAcompanhantes] = useState([{ nome: '', area: '' }]);
    const [transporte, setTransporte] = useState(transportes[0]?.value ?? 'carro');
    const [origem, setOrigem] = useState({ nome: '', lat: null, lng: null });
    const [destino, setDestino] = useState({ nome: '', lat: null, lng: null });
    const [minutos, setMinutos] = useState(60);
    const [permissao, setPermissao] = useState(null); // null | 'ok' | 'negada'
    const [posicao, setPosicao] = useState(null);

    function trocarQuantidade(valor) {
        const n = Math.max(1, Math.min(MAX_PESSOAS, Number(valor) || 1));
        setPessoas(n);
        setAcompanhantes((a) => redimensionar(a, n));
    }

    function editarAcompanhante(indice, campo, valor) {
        setAcompanhantes((a) => a.map((p, i) => (i === indice ? { ...p, [campo]: valor } : p)));
    }

    /** Passo 6: pede a permissão do aparelho e guarda a primeira posição. */
    function pedirPermissao() {
        if (!navigator.geolocation) {
            setPermissao('negada');
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (p) => {
                setPosicao({ lat: p.coords.latitude, lng: p.coords.longitude });
                setPermissao('ok');
            },
            () => setPermissao('negada'),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    }

    const podeAvancar = passo !== 4 || destino.nome.trim() !== '';

    function iniciar() {
        onIniciar({
            pessoas,
            // O nome é opcional: o que estiver em branco é descartado no servidor.
            acompanhantes: acompanhantes.map((p) => ({ nome: p.nome.trim(), area: p.area.trim() })),
            transporte,
            origem_nome: origem.nome.trim() === '' ? null : origem.nome.trim(),
            origem_lat: origem.lat,
            origem_lng: origem.lng,
            destino_nome: destino.nome.trim(),
            destino_lat: destino.lat,
            destino_lng: destino.lng,
            minutos: Number(minutos),
        }, posicao);
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 max-h-[90vh] overflow-auto">
                <h3 className="font-display text-xl font-semibold text-on-surface mb-1">Habilitar localização</h3>
                <p className="text-sm text-on-surface-variant mb-4">
                    O portal passa a mostrar onde o seu grupo está e quanto falta para chegar. O
                    localizador desliga sozinho no tempo que você escolher.
                </p>

                <ol className="flex flex-wrap items-center gap-1 mb-4 text-xs">
                    {PASSOS.map((nome, i) => (
                        <li key={nome} className="flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() => setPasso(i)}
                                className={`px-2 py-1 rounded-full font-semibold ${i === passo
                                    ? 'bg-primary-container text-on-primary'
                                    : 'text-on-surface-variant hover:bg-surface-variant'}`}
                            >
                                {i + 1}. {nome}
                            </button>
                            {i < PASSOS.length - 1 && <span className="text-on-surface-variant">›</span>}
                        </li>
                    ))}
                </ol>

                {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

                {passo === 0 && (
                    <Field label="Quantas pessoas estão com você?" required>
                        <Input
                            type="number"
                            min={1}
                            max={MAX_PESSOAS}
                            value={pessoas}
                            onChange={(e) => trocarQuantidade(e.target.value)}
                            aria-label="Quantidade de pessoas"
                        />
                    </Field>
                )}

                {passo === 1 && (
                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">
                            Quem está com você? (opcional)
                        </legend>
                        <p className="text-xs text-on-surface-variant mb-3">
                            O nome e a área de cada pessoa aparecem para a organização ao clicar no
                            seu ponto no mapa. Pode deixar em branco.
                        </p>
                        <div className="space-y-2">
                            {acompanhantes.map((p, i) => (
                                <div key={i} className="flex gap-2">
                                    <div className="flex-1">
                                        <Input
                                            value={p.nome}
                                            placeholder={`Nome da pessoa ${i + 1}`}
                                            aria-label={`Nome da pessoa ${i + 1}`}
                                            onChange={(e) => editarAcompanhante(i, 'nome', e.target.value)}
                                        />
                                    </div>
                                    <div className="flex-1">
                                        <Input
                                            value={p.area}
                                            placeholder="Área (opcional)"
                                            aria-label={`Área da pessoa ${i + 1}`}
                                            onChange={(e) => editarAcompanhante(i, 'area', e.target.value)}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </fieldset>
                )}

                {passo === 2 && (
                    <Field label="Como vocês estão indo?" required>
                        <Select value={transporte} onChange={(e) => setTransporte(e.target.value)} aria-label="Meio de transporte">
                            {transportes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </Select>
                    </Field>
                )}

                {passo === 3 && (
                    <Field label="De onde vocês estão saindo? (opcional)">
                        <EnderecoAutocomplete
                            id="comite-origem"
                            valor={origem}
                            onChange={setOrigem}
                            placeholder="Aeroporto, hotel, escola…"
                        />
                    </Field>
                )}

                {passo === 4 && (
                    <div className="space-y-4">
                        <Field label="Destino final" required>
                            <EnderecoAutocomplete
                                id="comite-destino"
                                valor={destino}
                                onChange={setDestino}
                                placeholder="Para onde vocês vão"
                            />
                        </Field>
                        <Field label="Por quanto tempo o localizador fica ligado?" required>
                            <Input
                                type="number"
                                min={minMinutos}
                                max={maxMinutos}
                                value={minutos}
                                onChange={(e) => setMinutos(e.target.value)}
                                aria-label="Minutos com o localizador ligado"
                            />
                            <p className="text-xs text-on-surface-variant mt-1">
                                Em minutos (de {minMinutos} a {maxMinutos}). Dá para ajustar depois de iniciar.
                            </p>
                        </Field>
                    </div>
                )}

                {passo === 5 && (
                    <div className="space-y-3">
                        <p className="text-sm text-on-surface">
                            Para acompanhar o trajeto, o portal precisa da localização deste
                            aparelho. A posição é atualizada a cada poucos segundos e o trajeto é
                            apagado quando o localizador é desligado.
                        </p>
                        <Button type="button" variant="outline" onClick={pedirPermissao}>
                            <span className="material-symbols-outlined text-[20px]">my_location</span>
                            Permitir localização
                        </Button>
                        {permissao === 'ok' && (
                            <Alert type="info">Localização liberada. Pode iniciar o localizador.</Alert>
                        )}
                        {permissao === 'negada' && (
                            <Alert>
                                Não foi possível obter a localização. Autorize o acesso nas
                                permissões do navegador e tente de novo.
                            </Alert>
                        )}
                    </div>
                )}

                <div className="flex justify-between gap-2 pt-5">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <div className="flex gap-2">
                        {passo > 0 && (
                            <Button type="button" variant="outline" onClick={() => setPasso(passo - 1)}>Voltar</Button>
                        )}
                        {passo < PASSOS.length - 1 ? (
                            <Button type="button" disabled={!podeAvancar} onClick={() => setPasso(passo + 1)}>
                                Continuar
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                loading={salvando}
                                disabled={permissao !== 'ok' || destino.nome.trim() === ''}
                                onClick={iniciar}
                            >
                                <span className="material-symbols-outlined text-[18px]">navigation</span>
                                Iniciar localizador
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

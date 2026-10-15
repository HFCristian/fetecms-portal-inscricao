import { useRef, useState } from 'react';
import { Button } from './ui.jsx';
import CardCheckin from './CardCheckin.jsx';
import LeitorCamera from './LeitorCamera.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { checkinPorCodigo } from '../lib/cerimonial.js';
import { suporteCamera } from '../lib/leitorCamera.js';

/**
 * O leitor de crachá da porta da cerimônia: **leu, entrou**.
 *
 * São os dois equipamentos de sempre, pelas mesmas razões do balcão de
 * credenciamento:
 *
 * - **leitor USB**: comporta-se como teclado — digita e dá Enter. Por isso o
 *   campo é um input comum com foco automático, que nunca o perde: o atendente
 *   só aponta e bipa. Digitar à mão também funciona, que é o que salva a
 *   etiqueta amassada;
 * - **câmera**: {@link LeitorCamera} — a `BarcodeDetector` do navegador onde
 *   ela lê os dois formatos do crachá e o ZXing em WebAssembly no resto (iPhone
 *   incluído). Ela **fica aberta** entre uma pessoa e outra. Onde não pode
 *   abrir, o botão não aparece e a tela diz por quê.
 *
 * Lido o crachá, a entrada **daquela pessoa** já fica registrada no evento
 * de cerimônia escolhido (`evento`) e o
 * {@link CardCheckin} mostra o nome por 2 segundos. Não há ficha a abrir: a
 * equipe passa crachá a crachá. Quem chega sem crachá segue pela busca por
 * nome, que abre a ficha do projeto.
 *
 * As leituras entram numa **fila**: o leitor USB bipa a pessoa seguinte antes
 * de o servidor responder sobre a anterior, e nenhuma das duas pode se perder.
 * Quem valida é o servidor — formato, lista que vale e se a pessoa é mesmo
 * daquela equipe.
 */
export default function LeitorCerimonial({ teste = false, evento = null, onRegistrado }) {
    const [codigo, setCodigo] = useState('');
    const [pendentes, setPendentes] = useState(0);
    const [camera, setCamera] = useState(false);
    const [leitura, setLeitura] = useState(null);
    const campo = useRef(null);
    const fila = useRef(Promise.resolve());
    const sequencia = useRef(0);

    const suporte = suporteCamera();

    function mostrar(dados) {
        sequencia.current += 1;
        setLeitura({ id: sequencia.current, ...dados });
    }

    async function registrar(limpo) {
        try {
            const dados = await checkinPorCodigo(limpo, teste, evento);
            mostrar({
                tipo: dados.ja_presente ? 'repetido' : 'novo',
                nome: dados.participante.nome,
                papel_label: dados.participante.papel_label,
                projeto_titulo: dados.projeto_titulo,
                checkin_em: dados.checkin_em,
                vinculos: dados.vinculos ?? [],
            });
            onRegistrado?.(dados);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            mostrar({
                tipo: 'erro',
                mensagem: fields?.codigo || message || 'Não foi possível ler o código.',
            });
        }
    }

    /** Põe a leitura na fila; a promessa resolve quando ela foi atendida. */
    function enviar(valor) {
        const limpo = (valor ?? '').trim();
        if (!limpo) return Promise.resolve();

        setPendentes((n) => n + 1);
        fila.current = fila.current
            .then(() => registrar(limpo))
            .finally(() => setPendentes((n) => n - 1));

        return fila.current;
    }

    function enviarCampo() {
        const valor = codigo;
        // O campo esvazia na hora: a próxima bipada não pode se juntar a esta.
        setCodigo('');
        campo.current?.focus();
        enviar(valor);
    }

    function fecharCard() {
        setLeitura(null);
        // Sem câmera, o foco volta ao campo do leitor USB. Com ela aberta, não:
        // no celular isso abriria o teclado por cima do vídeo.
        if (!camera) campo.current?.focus();
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4">
            <div className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-[16rem]">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="cerimonial-codigo">
                        Ler o crachá
                    </label>
                    <input
                        id="cerimonial-codigo"
                        ref={campo}
                        autoFocus
                        value={codigo}
                        onChange={(e) => setCodigo(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); enviarCampo(); } }}
                        placeholder="Bipe o código de barras ou digite (2026-31-123-A45)"
                        aria-label="Código do crachá"
                        className="w-full mt-1 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                    />
                </div>

                <Button type="button" loading={pendentes > 0} onClick={enviarCampo}>Registrar entrada</Button>

                {suporte.ok && !camera && (
                    <Button type="button" variant="outline" onClick={() => setCamera(true)}>
                        <span className="material-symbols-outlined text-[20px]">photo_camera</span>
                        Ler com a câmera
                    </Button>
                )}
                {camera && (
                    <Button type="button" variant="outline" onClick={() => setCamera(false)}>Fechar a câmera</Button>
                )}
            </div>

            <p className="text-xs text-on-surface-variant mt-2">
                Lido o crachá, a entrada já fica registrada — não há ficha a abrir.
                {!suporte.ok && ` ${suporte.motivo} Use o leitor de código de barras, digite o código da etiqueta ou procure pelo nome abaixo.`}
            </p>

            {camera && (
                <LeitorCamera
                    onLer={enviar}
                    onErro={(mensagem) => { mostrar({ tipo: 'erro', mensagem }); setCamera(false); }}
                />
            )}

            {leitura && <CardCheckin leitura={leitura} onFechar={fecharCard} />}
        </section>
    );
}

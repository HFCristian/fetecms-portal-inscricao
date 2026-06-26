import { tempoRelativo } from '../lib/tempo.js';

// Recibo de leitura ("visto") exibido sob as mensagens que EU enviei.
// - vista:   o outro lado já visualizou esta mensagem (✓✓) ou só foi enviada (✓);
// - ultima:  é a minha mensagem mais recente — só nela mostramos o texto
//            ('Visto · há X' / 'Enviada'), estilo WhatsApp, para não repetir;
// - vistoEm: quando o outro lado visualizou (para o tempo relativo).
export default function ReciboLeitura({ vista, ultima, vistoEm }) {
    return (
        <span className="inline-flex items-center gap-0.5 shrink-0" title={vista ? 'Visto' : 'Enviada'}>
            <span className="material-symbols-outlined text-[14px] leading-none">
                {vista ? 'done_all' : 'done'}
            </span>
            {ultima && <span>{vista ? `Visto${vistoEm ? ` · ${tempoRelativo(vistoEm)}` : ''}` : 'Enviada'}</span>}
        </span>
    );
}

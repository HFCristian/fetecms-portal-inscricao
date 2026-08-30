/**
 * Carregador do Google Maps JS API.
 *
 * A chave vem de `VITE_GOOGLE_MAPS_API_KEY` (build). **Sem chave o mapa não
 * carrega** — as telas do comitê checam `temChaveMaps()` e explicam o que
 * falta, em vez de mostrar um retângulo cinza sem explicação.
 *
 * O script é carregado uma vez só; chamadas concorrentes compartilham a mesma
 * promessa.
 */
const CHAVE = import.meta.env.VITE_GOOGLE_MAPS_API_KEY ?? '';

/** Bibliotecas usadas: `places` para o autocomplete do destino/partida. */
const BIBLIOTECAS = 'places';

let promessa = null;

export const temChaveMaps = () => CHAVE !== '';

/**
 * Devolve o objeto `google.maps` pronto para uso. Rejeita quando não há chave
 * configurada ou quando o script não carrega (offline, chave inválida).
 */
export function carregarMaps() {
    if (!temChaveMaps()) {
        return Promise.reject(new Error('SEM_CHAVE'));
    }

    if (window.google?.maps) {
        return Promise.resolve(window.google.maps);
    }

    if (promessa) return promessa;

    promessa = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const params = new URLSearchParams({
            key: CHAVE,
            libraries: BIBLIOTECAS,
            language: 'pt-BR',
            region: 'BR',
            loading: 'async',
        });
        script.src = `https://maps.googleapis.com/maps/api/js?${params}`;
        script.async = true;
        script.onerror = () => {
            promessa = null;
            reject(new Error('FALHA_CARREGAMENTO'));
        };
        script.onload = () => {
            if (window.google?.maps) resolve(window.google.maps);
            else reject(new Error('FALHA_CARREGAMENTO'));
        };
        document.head.appendChild(script);
    });

    return promessa;
}

/** Texto amigável para cada motivo de o mapa não abrir. */
export function motivoMapaIndisponivel(erro) {
    return erro?.message === 'SEM_CHAVE'
        ? 'Mapa indisponível: a chave do Google Maps (VITE_GOOGLE_MAPS_API_KEY) não está configurada neste ambiente.'
        : 'Não foi possível carregar o mapa. Verifique a conexão e a chave do Google Maps.';
}

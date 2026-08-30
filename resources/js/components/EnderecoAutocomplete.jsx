import { useEffect, useRef, useState } from 'react';
import { carregarMaps, temChaveMaps } from '../lib/googleMaps.js';

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-sm text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 ' +
    'transition-all outline-none';

/**
 * Campo de endereço com autocomplete do Google Places.
 *
 * Sem chave do Maps configurada (ou se o script não carregar), o campo continua
 * funcionando como **texto livre** — o endereço fica gravado pelo nome, só sem
 * as coordenadas. É melhor deixar o comitê registrar o destino do que travar o
 * assistente por causa de uma integração externa.
 *
 * `onChange({ nome, lat, lng })` — `lat`/`lng` são nulos no modo texto livre.
 */
export default function EnderecoAutocomplete({ id, valor, onChange, placeholder, disabled = false }) {
    const inputRef = useRef(null);
    const [autocompleteOk, setAutocompleteOk] = useState(false);

    useEffect(() => {
        if (!temChaveMaps() || !inputRef.current) return undefined;

        let cancelado = false;
        let listener = null;

        carregarMaps()
            .then((maps) => {
                if (cancelado || !inputRef.current) return;

                const auto = new maps.places.Autocomplete(inputRef.current, {
                    fields: ['formatted_address', 'name', 'geometry'],
                    componentRestrictions: { country: 'br' },
                });

                listener = auto.addListener('place_changed', () => {
                    const lugar = auto.getPlace();
                    const local = lugar?.geometry?.location;

                    onChange({
                        nome: lugar?.formatted_address || lugar?.name || inputRef.current.value,
                        lat: local ? local.lat() : null,
                        lng: local ? local.lng() : null,
                    });
                });

                setAutocompleteOk(true);
            })
            .catch(() => setAutocompleteOk(false));

        return () => {
            cancelado = true;
            if (listener) listener.remove();
        };
        // onChange é recriado a cada render do pai; prender o efeito ao campo evita
        // recriar o autocomplete (e perder a seleção) a cada tecla digitada.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    return (
        <>
            <input
                id={id}
                ref={inputRef}
                type="text"
                className={inputClass}
                value={valor?.nome ?? ''}
                disabled={disabled}
                placeholder={placeholder}
                autoComplete="off"
                onChange={(e) => onChange({ nome: e.target.value, lat: null, lng: null })}
            />
            {!autocompleteOk && (
                <p className="text-xs text-on-surface-variant mt-1">
                    Digite o endereço. A sugestão automática precisa da chave do Google Maps.
                </p>
            )}
        </>
    );
}

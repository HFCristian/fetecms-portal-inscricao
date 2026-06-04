import { useEffect, useState } from 'react';
import http from './http.js';

/** Carrega os catálogos estáveis uma vez (categorias, áreas, estados, instituições, edições). */
export function useCatalogos() {
    const [cat, setCat] = useState({
        categorias: [], areas: [], estados: [], instituicoes: [], edicoes: [],
    });

    useEffect(() => {
        Promise.all([
            http.get('/catalogos/categorias'),
            http.get('/catalogos/areas'),
            http.get('/catalogos/estados'),
            http.get('/catalogos/instituicoes'),
            http.get('/catalogos/edicoes'),
        ])
            .then(([c, a, e, i, ed]) => {
                const arr = (resp) => (Array.isArray(resp?.data?.data) ? resp.data.data : []);
                setCat({
                    categorias: arr(c),
                    areas: arr(a),
                    estados: arr(e),
                    instituicoes: arr(i),
                    edicoes: arr(ed),
                });
            })
            .catch(() => {});
    }, []);

    return cat;
}

export const loadSubareas = (areaId) =>
    http.get('/catalogos/subareas', { params: { area_id: areaId } }).then((r) => r.data.data);

export const loadCidades = (estadoId) =>
    http.get('/catalogos/cidades', { params: { estado_id: estadoId } }).then((r) => r.data.data);

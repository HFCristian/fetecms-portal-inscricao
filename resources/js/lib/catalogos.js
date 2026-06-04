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
            .then(([c, a, e, i, ed]) =>
                setCat({
                    categorias: c.data.data,
                    areas: a.data.data,
                    estados: e.data.data,
                    instituicoes: i.data.data,
                    edicoes: ed.data.data,
                }),
            )
            .catch(() => {});
    }, []);

    return cat;
}

export const loadSubareas = (areaId) =>
    http.get('/catalogos/subareas', { params: { area_id: areaId } }).then((r) => r.data.data);

export const loadCidades = (estadoId) =>
    http.get('/catalogos/cidades', { params: { estado_id: estadoId } }).then((r) => r.data.data);

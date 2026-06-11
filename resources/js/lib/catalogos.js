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

// Cria (ou reaproveita) uma subárea global na área e devolve { id, nome, area_id }.
// Usada pelo combobox em telas autenticadas (projeto/perfil).
export const criarSubarea = (areaId, nome) =>
    http.post('/catalogos/subareas', { area_id: areaId, nome }).then((r) => r.data.data);

export const loadCidades = (estadoId) =>
    http.get('/catalogos/cidades', { params: { estado_id: estadoId } }).then((r) => r.data.data);

export const buscarPalavrasChave = (search) =>
    http.get('/catalogos/palavras-chave', { params: search ? { search } : {} }).then((r) => r.data.data);

// Busca instituições no catálogo (server-side, até 50). Cada item: { id, nome, cidade, tipo }.
export const buscarInstituicoes = (search) =>
    http.get('/catalogos/instituicoes', { params: search ? { search } : {} }).then((r) => r.data.data);

// Cria (ou reaproveita) uma instituição global pelo nome. Telas autenticadas.
export const criarInstituicao = (nome, extras = {}) =>
    http.post('/catalogos/instituicoes', { nome, ...extras }).then((r) => r.data.data);

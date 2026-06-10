// Lista de países (ISO 3166-1 alpha-2). Os nomes são resolvidos em pt-BR via
// Intl.DisplayNames (sem hardcode), com o Brasil sempre no topo.
const CODIGOS = (
    'AF ZA AL DE AD AO AI AQ AG SA DZ AR AM AW AU AT AZ BS BH BD BB BE BZ BJ BM BO BA BW BR BN BG BF BI BT BY ' +
    'CV KH CM CA QA KZ TD CL CN CY CO KM CG CD KR KP CR CI HR CU CW DK DJ DM EG SV AE EC ER SK SI ES US EE ET ' +
    'FJ PH FI FR GA GM GH GE GR GD GL GP GU GT GG GN GQ GW GY GF HT HN HK HU YE BV IN ID IQ IR IE IS IL IT JM ' +
    'JP JE JO KI KW LA LS LV LB LR LY LI LT LU MO MK MG MY MW MV ML MT MA MQ MU MR MX MD MC MN ME MS MZ MM NA ' +
    'NR NP NI NE NG NO NC NZ OM NL PK PW PA PG PY PE PF PL PT PR KE GB CF CZ DO RO RW RU EH WS AS SM SH LC KN ' +
    'ST SN RS SC SL SG SX SY SO LK SZ SD SS SE CH SR TH TW TZ TJ TF TL TG TK TO TT TN TM TR TV UA UG UY UZ VU ' +
    'VA VE VN WF ZM ZW BZ'
).split(/\s+/).filter(Boolean);

let cache = null;

export function listaPaises() {
    if (cache) return cache;
    const dn = new Intl.DisplayNames(['pt-BR'], { type: 'region' });
    const nome = (code) => {
        try {
            const n = dn.of(code);
            return n && n !== code ? n : null;
        } catch {
            return null;
        }
    };
    const itens = [...new Set(CODIGOS)]
        .map((code) => ({ code, nome: nome(code) }))
        .filter((p) => p.nome)
        .sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));

    const br = itens.find((p) => p.code === 'BR');
    cache = br ? [br, ...itens.filter((p) => p.code !== 'BR')] : itens;
    return cache;
}

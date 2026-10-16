import LeitorCracha from './LeitorCracha.jsx';
import { checkinPorCodigo } from '../lib/cerimonial.js';

/**
 * O leitor de crachá da porta da cerimônia: **leu, entrou** ({@link LeitorCracha}).
 *
 * Lido o crachá, a entrada **daquela pessoa** já fica registrada no evento
 * de cerimônia escolhido (`evento`) e o card mostra o nome por 2 segundos. Não
 * há ficha a abrir: a equipe passa crachá a crachá. Quem chega sem crachá segue
 * pela busca por nome, que abre a ficha do projeto. Quem valida é o servidor —
 * formato, lista que vale e se a pessoa é mesmo daquela equipe.
 */
export default function LeitorCerimonial({ teste = false, evento = null, onRegistrado }) {
    async function registrar(codigo) {
        const dados = await checkinPorCodigo(codigo, teste, evento);

        return {
            dados,
            card: {
                tipo: dados.ja_presente ? 'repetido' : 'novo',
                nome: dados.participante.nome,
                papel_label: dados.participante.papel_label,
                projeto_titulo: dados.projeto_titulo,
                checkin_em: dados.checkin_em,
                vinculos: dados.vinculos ?? [],
            },
        };
    }

    return <LeitorCracha campoId="cerimonial-codigo" registrar={registrar} onRegistrado={onRegistrado} />;
}

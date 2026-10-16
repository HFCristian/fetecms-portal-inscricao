import { Alert, Field, Select } from './ui.jsx';

/**
 * O dia × turno do evento em que a chegada é registrada (as ocorrências vêm do
 * servidor; sem o período do evento, a tela diz o que falta).
 */
export default function SeletorOcorrencia({ config, chave, onTrocar }) {
    if (!config) return null;

    if ((config.ocorrencias ?? []).length === 0) {
        return <Alert type="warning">{config.motivo}</Alert>;
    }

    return (
        <Field label="Dia e turno">
            <Select aria-label="Dia e turno" value={chave ?? ''} onChange={(e) => onTrocar(e.target.value)}>
                {config.ocorrencias.map((o) => (
                    <option key={o.chave} value={o.chave}>
                        {o.rotulo}{o.situacao === 'em_andamento' ? ' (agora)' : ''}
                    </option>
                ))}
            </Select>
        </Field>
    );
}

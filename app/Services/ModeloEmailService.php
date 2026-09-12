<?php

namespace App\Services;

use App\Enums\ModeloEmail;
use App\Mail\MensagemTransacional;
use App\Models\ModeloEmailTexto;
use App\Models\User;
use App\Support\HtmlEmail;
use App\Support\MensagemEmail;

/**
 * Textos dos e-mails automáticos (Comunicação → Modelos de e-mail).
 *
 * O padrão mora no enum; a tabela guarda só o que o admin trocou. Quem dispara
 * o e-mail pede a mensagem aqui e não precisa saber de onde o texto veio.
 */
class ModeloEmailService
{
    /**
     * Assunto e corpo em vigor — o do admin, se houver, senão o de fábrica.
     *
     * `formato` diz como ler o corpo: `html` (escrito no editor rico, já
     * sanitizado) ou `texto` (puro, quebrado em parágrafos na renderização).
     * O texto de fábrica é sempre `texto`.
     *
     * @return array{assunto: string, corpo: string, formato: string, personalizado: bool, autor_nome: ?string, atualizado_em: ?string}
     */
    public function texto(ModeloEmail $modelo): array
    {
        $salvo = ModeloEmailTexto::where('chave', $modelo->value)->first();

        return [
            'assunto' => $salvo->assunto ?? $modelo->assuntoPadrao(),
            'corpo' => $salvo->corpo ?? $modelo->corpoPadrao(),
            'formato' => $salvo === null ? 'texto' : ($salvo->formato ?? 'texto'),
            'personalizado' => $salvo !== null,
            'autor_nome' => $salvo?->autor_nome,
            'atualizado_em' => $salvo?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A mensagem pronta para o Mail::send, com as variáveis já trocadas.
     *
     * @param  array<string, string>  $valores
     */
    public function mensagem(ModeloEmail $modelo, array $valores, ?string $destaque = null): MensagemTransacional
    {
        $texto = $this->texto($modelo);

        return new MensagemTransacional(
            assunto: MensagemEmail::personalizar($texto['assunto'], $valores),
            corpo: MensagemEmail::personalizar($texto['corpo'], $valores),
            destaque: $destaque,
            formato: $texto['formato'],
            modelo: $modelo,
        );
    }

    /**
     * A lista da tela: o catálogo do enum + o texto em vigor de cada um.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(): array
    {
        return array_map(function (array $item) {
            $modelo = ModeloEmail::from($item['chave']);

            return $item + $this->texto($modelo) + [
                'assunto_padrao' => $modelo->assuntoPadrao(),
                'corpo_padrao' => $modelo->corpoPadrao(),
            ];
        }, ModeloEmail::catalogo());
    }

    /** Um modelo, no mesmo formato da lista. */
    public function detalhe(ModeloEmail $modelo): array
    {
        return [
            'chave' => $modelo->value,
            'label' => $modelo->label(),
            'descricao' => $modelo->descricao(),
            'variaveis' => $modelo->variaveis(),
            'assunto_padrao' => $modelo->assuntoPadrao(),
            'corpo_padrao' => $modelo->corpoPadrao(),
        ] + $this->texto($modelo);
    }

    /**
     * Grava o texto do admin. Salvar exatamente o padrão apaga a customização —
     * o modelo volta a acompanhar qualquer mudança futura no texto de fábrica.
     *
     * O corpo em **HTML** (editor rico) é **sanitizado aqui**, na gravação: o
     * que chega ao banco já é o que pode ir para uma caixa de entrada, e quem
     * envia não precisa saber de onde o texto veio. A comparação com o padrão
     * só faz sentido no formato de fábrica (texto puro) — um corpo em HTML
     * nunca é "igual ao padrão", nem quando diz a mesma coisa.
     *
     * @param  array{assunto: string, corpo: string, formato?: string}  $dados
     */
    public function atualizar(ModeloEmail $modelo, array $dados, ?User $autor = null): array
    {
        $formato = ($dados['formato'] ?? 'texto') === 'html' ? 'html' : 'texto';
        $corpo = $formato === 'html'
            ? HtmlEmail::sanitizar($dados['corpo'])
            : trim($dados['corpo']);

        $igualAoPadrao = $formato === 'texto'
            && trim($dados['assunto']) === trim($modelo->assuntoPadrao())
            && $corpo === trim($modelo->corpoPadrao());

        if ($igualAoPadrao) {
            return $this->restaurar($modelo);
        }

        ModeloEmailTexto::updateOrCreate(
            ['chave' => $modelo->value],
            [
                'assunto' => trim($dados['assunto']),
                'corpo' => $corpo,
                'formato' => $formato,
                'user_id' => $autor?->id,
                'autor_nome' => $autor?->name,
            ],
        );

        return $this->detalhe($modelo);
    }

    /** Volta ao texto de fábrica. */
    public function restaurar(ModeloEmail $modelo): array
    {
        ModeloEmailTexto::where('chave', $modelo->value)->delete();

        return $this->detalhe($modelo);
    }
}

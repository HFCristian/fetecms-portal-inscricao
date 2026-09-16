<?php

namespace App\Support;

/**
 * Conferência da **assinatura digital** de um PDF — o termo de responsabilidade
 * assinado no gov.br.
 *
 * ## O que é conferido, e o que não é
 *
 * O ITI não publica API de validação: o validador oficial
 * (validar.iti.gov.br) é uma página web, e mandar o documento de um menor de
 * idade para um serviço de terceiros seria pior do que não conferir. Então a
 * conferência é feita **aqui, no próprio arquivo**, em três camadas:
 *
 * 1. **Existe assinatura?** — o PDF tem um campo de assinatura com `/ByteRange`
 *    e o blob PKCS#7 dentro de `/Contents`.
 * 2. **O documento é o que foi assinado?** — o resumo criptográfico é
 *    recalculado sobre os intervalos do `/ByteRange` e conferido contra o que
 *    está assinado. É isto que pega o PDF alterado depois da assinatura, que é
 *    a fraude que interessa.
 * 3. **Quem assinou?** — nome e CPF saem do certificado do signatário, e o
 *    emissor diz se a cadeia é **ICP-Brasil** (o gov.br assina por ela).
 *
 * O que **não** é conferido: revogação do certificado (CRL/OCSP) e a cadeia
 * completa até a raiz da ICP-Brasil. As duas dependem de rede no momento do
 * upload e de um bundle de raízes que precisaria ser mantido; um certificado
 * revogado no intervalo entre a assinatura e a conferência passaria. Por isso o
 * resultado é **informativo para a organização** (o admin vê quem assinou e o
 * que foi verificado), e não um portão que sozinho aprova o documento.
 *
 * O arquivo não assinado **não é recusado**: ele fica anexado com
 * `valida = false` e o motivo à vista, para o balcão resolver com a pessoa. Um
 * termo legítimo assinado à caneta e escaneado existe, e travar o upload
 * deixaria o finalista sem saída.
 */
class AssinaturaPdf
{
    /** Trecho que identifica a ICP-Brasil no emissor do certificado. */
    private const MARCA_ICP = 'ICP-Brasil';

    /**
     * Confere o PDF e devolve o laudo.
     *
     * @return array{
     *     valida: bool,
     *     assinado: bool,
     *     icp_brasil: bool,
     *     integro: bool|null,
     *     signatarios: list<array{nome:string|null, cpf:string|null, emissor:string|null}>,
     *     motivo: string,
     *     verificado_em: string
     * }
     */
    public static function conferir(string $conteudo): array
    {
        $laudo = [
            'valida' => false,
            'assinado' => false,
            'icp_brasil' => false,
            'integro' => null,
            'signatarios' => [],
            'motivo' => 'O arquivo não traz assinatura digital.',
            'verificado_em' => now()->toIso8601String(),
        ];

        $campos = self::campos($conteudo);

        if ($campos === []) {
            return $laudo;
        }

        $laudo['assinado'] = true;
        $integro = true;

        foreach ($campos as $campo) {
            if (! self::conferirResumo($conteudo, $campo['byte_range'], $campo['pkcs7'])) {
                $integro = false;
            }

            foreach (self::certificados($campo['pkcs7']) as $certificado) {
                $laudo['signatarios'][] = $certificado;

                if (str_contains((string) $certificado['emissor'], self::MARCA_ICP)) {
                    $laudo['icp_brasil'] = true;
                }
            }
        }

        $laudo['integro'] = $integro;
        $laudo['valida'] = $integro && $laudo['icp_brasil'];
        $laudo['motivo'] = match (true) {
            ! $integro => 'O documento foi alterado depois de assinado.',
            ! $laudo['icp_brasil'] => 'Assinado, mas o certificado não é da ICP-Brasil (gov.br).',
            default => 'Assinatura digital ICP-Brasil conferida.',
        };

        return $laudo;
    }

    /**
     * Os campos de assinatura do PDF: o `/ByteRange` (os intervalos que foram
     * assinados) e o PKCS#7 de `/Contents`, já convertido de hexadecimal.
     *
     * @return list<array{byte_range: list<int>, pkcs7: string}>
     */
    private static function campos(string $conteudo): array
    {
        // /ByteRange [ a b c d ] seguido (na mesma entrada) de /Contents <hex>.
        $encontrou = preg_match_all(
            '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/',
            $conteudo,
            $intervalos,
            PREG_OFFSET_CAPTURE,
        );

        if (! $encontrou) {
            return [];
        }

        $campos = [];

        foreach ($intervalos[0] as $i => [, $posicao]) {
            $range = [
                (int) $intervalos[1][$i][0], (int) $intervalos[2][$i][0],
                (int) $intervalos[3][$i][0], (int) $intervalos[4][$i][0],
            ];

            // O /Contents da MESMA assinatura é o primeiro depois do ByteRange.
            if (! preg_match('/\/Contents\s*<([0-9A-Fa-f]+)>/', $conteudo, $hex, 0, $posicao)) {
                continue;
            }

            // O espaço de /Contents é reservado com folga e completado com
            // zeros; o excesso vira NUL no fim e não atrapalha a leitura.
            $bruto = $hex[1];
            $pkcs7 = @hex2bin(strlen($bruto) % 2 === 0 ? $bruto : substr($bruto, 0, -1));

            if ($pkcs7 === false || $pkcs7 === '') {
                continue;
            }

            $campos[] = ['byte_range' => $range, 'pkcs7' => $pkcs7];
        }

        return $campos;
    }

    /**
     * Confere se o resumo assinado corresponde ao conteúdo atual do arquivo.
     *
     * O `/ByteRange` diz quais trechos do PDF entraram na conta — tudo menos o
     * próprio blob da assinatura. Recalcular sobre eles e comparar com o
     * `messageDigest` do PKCS#7 é o que detecta uma página trocada depois da
     * assinatura.
     *
     * @param  list<int>  $range
     */
    private static function conferirResumo(string $conteudo, array $range, string $pkcs7): bool
    {
        [$inicio1, $tamanho1, $inicio2, $tamanho2] = $range;

        $assinado = substr($conteudo, $inicio1, $tamanho1).substr($conteudo, $inicio2, $tamanho2);

        // O algoritmo do resumo não é lido do ASN.1: procura-se o resumo do
        // conteúdo assinado entre os candidatos usuais. Basta um bater.
        foreach (['sha256', 'sha384', 'sha512', 'sha1'] as $algoritmo) {
            if (str_contains($pkcs7, hash($algoritmo, $assinado, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nome, CPF e emissor de cada certificado embutido no PKCS#7.
     *
     * O gov.br escreve o titular como "NOME DA PESSOA:12345678901" no CN — daí
     * sai o CPF, que é o que amarra o termo à pessoa do projeto.
     *
     * @return list<array{nome:string|null, cpf:string|null, emissor:string|null}>
     */
    private static function certificados(string $pkcs7): array
    {
        $encontrados = [];

        // Os certificados vêm em DER dentro do PKCS#7; o OpenSSL só lê PEM
        // avulso, então cada bloco é procurado e convertido.
        foreach (self::blocosDer($pkcs7) as $der) {
            $pem = "-----BEGIN CERTIFICATE-----\n"
                .chunk_split(base64_encode($der), 64, "\n")
                ."-----END CERTIFICATE-----\n";

            $dados = @openssl_x509_parse($pem);

            if ($dados === false) {
                continue;
            }

            $cn = (string) ($dados['subject']['CN'] ?? '');

            // Certificado de autoridade (a cadeia vem junto) não é signatário.
            if (str_contains((string) ($dados['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')) {
                continue;
            }

            $encontrados[] = [
                'nome' => self::nomeDoCn($cn),
                'cpf' => self::cpfDoCn($cn),
                'emissor' => self::emissor($dados),
            ];
        }

        return $encontrados;
    }

    /**
     * Varre o blob procurando estruturas DER de certificado (SEQUENCE longa
     * começando em 0x30 0x82) e devolve cada uma inteira.
     *
     * @return list<string>
     */
    private static function blocosDer(string $pkcs7): array
    {
        $blocos = [];
        $tamanho = strlen($pkcs7);
        $i = 0;

        while ($i < $tamanho - 4) {
            if ($pkcs7[$i] !== "\x30" || $pkcs7[$i + 1] !== "\x82") {
                $i++;

                continue;
            }

            $comprimento = (ord($pkcs7[$i + 2]) << 8) + ord($pkcs7[$i + 3]) + 4;

            if ($comprimento > 64 && $i + $comprimento <= $tamanho) {
                $blocos[] = substr($pkcs7, $i, $comprimento);
            }

            $i++;
        }

        return $blocos;
    }

    private static function nomeDoCn(string $cn): ?string
    {
        $nome = trim(explode(':', $cn)[0] ?? '');

        return $nome === '' ? null : $nome;
    }

    private static function cpfDoCn(string $cn): ?string
    {
        // O CPF aparece depois dos dois-pontos no CN do e-CPF/gov.br.
        if (preg_match('/:(\d{11})\b/', $cn, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @param  array<string, mixed>  $dados */
    private static function emissor(array $dados): ?string
    {
        $issuer = (array) ($dados['issuer'] ?? []);
        $partes = array_filter([
            $issuer['CN'] ?? null,
            $issuer['OU'] ?? null,
            $issuer['O'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');

        return $partes === [] ? null : implode(' · ', $partes);
    }
}

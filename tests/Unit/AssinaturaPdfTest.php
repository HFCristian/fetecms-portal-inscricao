<?php

namespace Tests\Unit;

use App\Support\AssinaturaPdf;
use PHPUnit\Framework\TestCase;

/**
 * Conferência da assinatura digital do PDF (termo de responsabilidade do
 * gov.br): existe assinatura, o documento não mudou depois dela, e quem
 * assinou.
 */
class AssinaturaPdfTest extends TestCase
{
    /**
     * Monta um PDF com campo de assinatura: `/ByteRange` cobrindo tudo menos o
     * hexadecimal de `/Contents`, e dentro dele o certificado (DER) mais o
     * resumo do que foi assinado — que é o que o conferidor recalcula.
     */
    private function pdfAssinado(string $der, ?string $resumoFalso = null): string
    {
        // Números com 10 dígitos, como os assinadores de verdade fazem: o
        // tamanho do cabeçalho não pode mudar depois de calculado.
        $zeros = str_repeat('0', 10);
        $head = "%PDF-1.7\n1 0 obj\n<< /Type /Sig /ByteRange [{$zeros} {$zeros} {$zeros} {$zeros}] /Contents <";
        $tail = "> /Filter /Adobe.PPKLite >>\nendobj\n%%EOF\n";

        // Espaço reservado ao blob, com folga (como no PDF real).
        $tamanhoHex = 8192;

        $b = strlen($head);                 // o '<' entra no primeiro intervalo
        $c = $b + $tamanhoHex;              // o '>' abre o segundo
        $d = strlen($tail);

        $head = str_replace(
            "[{$zeros} {$zeros} {$zeros} {$zeros}]",
            sprintf('[%010d %010d %010d %010d]', 0, $b, $c, $d),
            $head,
        );

        $assinado = $head.$tail;
        $resumo = $resumoFalso ?? hash('sha256', $assinado, true);

        $blob = $der.$resumo;
        $hex = str_pad(bin2hex($blob), $tamanhoHex, '0');

        return $head.$hex.$tail;
    }

    /**
     * Certificado autoassinado com o CN no formato do gov.br (NOME:CPF).
     *
     * `CA:FALSE` é explícito de propósito: o conferidor descarta certificados
     * de autoridade (a cadeia vem junto no PKCS#7 e não é signatária), e o
     * padrão do openssl_csr_sign é justamente CA:TRUE.
     */
    private function certificadoDer(string $cn, string $emissor): string
    {
        $config = tempnam(sys_get_temp_dir(), 'ssl').'.cnf';
        file_put_contents($config, <<<'CNF'
            [ req ]
            distinguished_name = dn
            [ dn ]
            [ titular ]
            basicConstraints = CA:FALSE
            keyUsage = digitalSignature, nonRepudiation
            CNF);

        $opcoes = ['config' => $config, 'x509_extensions' => 'titular'];

        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $config]);
        $csr = openssl_csr_new(
            ['commonName' => $cn, 'organizationName' => $emissor],
            $chave,
            $opcoes,
        );
        $x509 = openssl_csr_sign($csr, null, $chave, 365, $opcoes);
        openssl_x509_export($x509, $pem);
        @unlink($config);

        $corpo = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        return base64_decode($corpo);
    }

    public function test_pdf_sem_assinatura_e_reconhecido_como_tal(): void
    {
        $laudo = AssinaturaPdf::conferir("%PDF-1.7\nsem assinatura\n%%EOF");

        $this->assertFalse($laudo['assinado']);
        $this->assertFalse($laudo['valida']);
        $this->assertNull($laudo['integro']);
        $this->assertSame('O arquivo não traz assinatura digital.', $laudo['motivo']);
        $this->assertSame([], $laudo['signatarios']);
    }

    public function test_assinatura_icp_brasil_integra_e_valida(): void
    {
        $der = $this->certificadoDer('FULANO DE TAL:12345678901', 'AC Exemplo ICP-Brasil');
        $laudo = AssinaturaPdf::conferir($this->pdfAssinado($der));

        $this->assertTrue($laudo['assinado']);
        $this->assertTrue($laudo['integro']);
        $this->assertTrue($laudo['icp_brasil']);
        $this->assertTrue($laudo['valida']);
        $this->assertSame('Assinatura digital ICP-Brasil conferida.', $laudo['motivo']);

        $this->assertSame('FULANO DE TAL', $laudo['signatarios'][0]['nome']);
        $this->assertSame('12345678901', $laudo['signatarios'][0]['cpf']);
    }

    public function test_documento_alterado_depois_de_assinado_e_apontado(): void
    {
        $der = $this->certificadoDer('FULANO DE TAL:12345678901', 'AC Exemplo ICP-Brasil');
        // O resumo guardado não corresponde ao conteúdo: é o que acontece
        // quando uma página é trocada depois da assinatura.
        $laudo = AssinaturaPdf::conferir($this->pdfAssinado($der, hash('sha256', 'outro documento', true)));

        $this->assertTrue($laudo['assinado']);
        $this->assertFalse($laudo['integro']);
        $this->assertFalse($laudo['valida']);
        $this->assertSame('O documento foi alterado depois de assinado.', $laudo['motivo']);
    }

    public function test_assinatura_fora_da_icp_brasil_nao_vale(): void
    {
        $der = $this->certificadoDer('FULANO DE TAL:12345678901', 'Assinador Qualquer');
        $laudo = AssinaturaPdf::conferir($this->pdfAssinado($der));

        $this->assertTrue($laudo['integro']);
        $this->assertFalse($laudo['icp_brasil']);
        $this->assertFalse($laudo['valida']);
        $this->assertSame('Assinado, mas o certificado não é da ICP-Brasil (gov.br).', $laudo['motivo']);
    }
}

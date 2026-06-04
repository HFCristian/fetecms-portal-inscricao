<?php

return [
    'accepted' => 'O campo :attribute deve ser aceito.',
    'active_url' => 'O campo :attribute não é uma URL válida.',
    'after' => 'O campo :attribute deve ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute deve ser uma data posterior ou igual a :date.',
    'alpha' => 'O campo :attribute deve conter apenas letras.',
    'alpha_dash' => 'O campo :attribute deve conter apenas letras, números, hífens e sublinhados.',
    'alpha_num' => 'O campo :attribute deve conter apenas letras e números.',
    'array' => 'O campo :attribute deve ser uma lista.',
    'before' => 'O campo :attribute deve ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute deve ser uma data anterior ou igual a :date.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'confirmed' => 'A confirmação do campo :attribute não confere.',
    'date' => 'O campo :attribute não é uma data válida.',
    'date_equals' => 'O campo :attribute deve ser uma data igual a :date.',
    'date_format' => 'O campo :attribute não corresponde ao formato :format.',
    'different' => 'Os campos :attribute e :other devem ser diferentes.',
    'digits' => 'O campo :attribute deve ter :digits dígitos.',
    'digits_between' => 'O campo :attribute deve ter entre :min e :max dígitos.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'exists' => 'O :attribute selecionado é inválido.',
    'file' => 'O campo :attribute deve ser um arquivo.',
    'filled' => 'O campo :attribute é obrigatório.',
    'image' => 'O campo :attribute deve ser uma imagem.',
    'in' => 'O :attribute selecionado é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais que :max itens.',
        'file' => 'O campo :attribute não pode ser maior que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute não pode ser maior que :max caracteres.',
    ],
    'mimes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute deve ter pelo menos :min itens.',
        'file' => 'O campo :attribute deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'numeric' => 'O campo :attribute deve ser um número.',
    'present' => 'O campo :attribute deve estar presente.',
    'prohibited' => 'O campo :attribute é proibido.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'same' => 'Os campos :attribute e :other devem ser iguais.',
    'string' => 'O campo :attribute deve ser um texto.',
    'unique' => 'O :attribute informado já está em uso.',
    'url' => 'O campo :attribute deve ser uma URL válida.',
    'uuid' => 'O campo :attribute deve ser um UUID válido.',

    /*
     | Mensagens customizadas por campo+regra (campo.regra => mensagem).
     */
    'custom' => [
        'password' => [
            'min' => 'A senha deve ter pelo menos :min caracteres.',
        ],
    ],

    /*
     | Nomes amigáveis dos campos usados em :attribute.
     */
    'attributes' => [
        'name' => 'nome',
        'nome' => 'nome',
        'email' => 'e-mail',
        'password' => 'senha',
        'cpf' => 'CPF',
        'telefone' => 'telefone',
        'data_nascimento' => 'data de nascimento',
        'genero' => 'gênero',
        'etnia' => 'raça/cor',
        'camiseta' => 'tamanho da camiseta',
        'instituicao' => 'instituição',
        'titulacao' => 'titulação',
        'cep' => 'CEP',
        'cidade' => 'cidade',
        'estado' => 'estado',
    ],
];

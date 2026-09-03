<?php

declare(strict_types=1);

/**
 * Renderização e processamento de formulários incorporados em blocos.
 *
 * A v0.77 mantém os formulários existentes como fonte única:
 * - cadastro e campos continuam em admin/formularios;
 * - respostas continuam em formulario_respostas;
 * - notificações continuam em FormNotificationService.
 */
final class EmbeddedFormService
{
    /**
     * @return array<string,mixed>|null
     */
    public static function findPublished(
        PDO $pdo,
        int $formId
    ): ?array {
        if ($formId <= 0) {
            return null;
        }

        $stmt =
            $pdo->prepare(
                "SELECT *
                 FROM formularios
                 WHERE id=:id
                   AND status='publicado'
                   AND ativo=1
                   AND (
                        publicado_em IS NULL
                        OR publicado_em<=NOW()
                   )
                 LIMIT 1"
            );

        $stmt->execute([
            'id' => $formId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $row
                ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function fields(
        PDO $pdo,
        int $formId
    ): array {
        if ($formId <= 0) {
            return [];
        }

        $stmt =
            $pdo->prepare(
                "SELECT *
                 FROM formulario_campos
                 WHERE formulario_id=:id
                   AND ativo=1
                 ORDER BY ordem,id"
            );

        $stmt->execute([
            'id' => $formId,
        ]);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
            ?: [];
    }

    /**
     * Renderiza um formulário dentro de Página/Notícia.
     *
     * @param array<string,mixed> $options
     */
    public static function render(
        PDO $pdo,
        int $formId,
        array $options,
        string $instanceKey
    ): string {
        $form =
            self::findPublished(
                $pdo,
                $formId
            );

        if (!$form) {
            return '';
        }

        $fields =
            self::fields(
                $pdo,
                $formId
            );

        if (!$fields) {
            return '';
        }

        $instanceKey =
            self::normalizeInstanceKey(
                $instanceKey
            );

        if ($instanceKey === '') {
            $instanceKey =
                'form-'
                . $formId;
        }

        $state =
            self::takeState(
                $formId,
                $instanceKey
            );

        $errors =
            is_array(
                $state['errors']
                ?? null
            )
                ? array_values(
                    array_filter(
                        array_map(
                            static fn(mixed $value): string =>
                                trim(
                                    (string)$value
                                ),
                            $state['errors']
                        ),
                        static fn(string $value): bool =>
                            $value !== ''
                    )
                )
                : [];

        $old =
            is_array(
                $state['old']
                ?? null
            )
                ? $state['old']
                : [];

        $success =
            trim(
                (string)(
                    $state['success']
                    ?? ''
                )
            );

        $showTitle =
            !array_key_exists(
                'show_title',
                $options
            )
            || !empty(
                $options['show_title']
            );

        $showDescription =
            !array_key_exists(
                'show_description',
                $options
            )
            || !empty(
                $options['show_description']
            );

        $customTitle =
            trim(
                (string)(
                    $options['title']
                    ?? ''
                )
            );

        $title =
            $customTitle !== ''
                ? $customTitle
                : trim(
                    (string)(
                        $form['titulo']
                        ?? ''
                    )
                );

        $style =
            (string)(
                $options['style']
                ?? 'card'
            );

        if (
            !in_array(
                $style,
                [
                    'card',
                    'plain',
                ],
                true
            )
        ) {
            $style = 'card';
        }

        $submitLabel =
            trim(
                (string)(
                    $options['submit_label']
                    ?? 'Enviar'
                )
            );

        if ($submitLabel === '') {
            $submitLabel =
                'Enviar';
        }

        $anchor =
            'portal-form-'
            . substr(
                hash(
                    'sha256',
                    $instanceKey
                    . ':'
                    . $formId
                ),
                0,
                12
            );

        $html =
            '<section'
            . ' id="'
            . self::e($anchor)
            . '"'
            . ' class="portal-form-embed my-5">';

        if (
            $showTitle
            && $title !== ''
        ) {
            $html .=
                '<h2 class="h3 mb-3">'
                . self::e($title)
                . '</h2>';
        }

        $description =
            trim(
                (string)(
                    $form['descricao']
                    ?? ''
                )
            );

        if (
            $showDescription
            && $description !== ''
        ) {
            $html .=
                '<div class="text-secondary mb-4">'
                . nl2br(
                    self::e(
                        $description
                    )
                )
                . '</div>';
        }

        if ($success !== '') {
            $html .=
                '<div class="alert alert-success p-4 mb-0" role="status">'
                . self::e($success)
                . '</div>'
                . '</section>';

            return $html;
        }

        if ($errors) {
            $html .=
                '<div class="alert alert-danger">'
                . '<strong>Confira os campos:</strong>'
                . '<ul class="mb-0 mt-2">';

            foreach (
                array_unique(
                    $errors
                )
                as $error
            ) {
                $html .=
                    '<li>'
                    . self::e(
                        $error
                    )
                    . '</li>';
            }

            $html .=
                '</ul></div>';
        }

        $formClass =
            $style === 'card'
                ? 'public-form card border-0 shadow-sm'
                : 'public-form';

        $html .=
            '<form method="post" action="'
            . self::e(
                url(
                    'formulario-enviar.php'
                )
            )
            . '" class="'
            . self::e(
                $formClass
            )
            . '">';

        $html .=
            Csrf::field();

        $html .=
            '<input type="hidden" name="formulario_id" value="'
            . (int)$formId
            . '">';

        $html .=
            '<input type="hidden" name="instance_key" value="'
            . self::e(
                $instanceKey
            )
            . '">';

        $html .=
            '<input type="hidden" name="return_to" value="'
            . self::e(
                self::currentReturnTo()
            )
            . '">';

        $html .=
            '<div class="visually-hidden" aria-hidden="true">'
            . '<label>Website'
            . '<input tabindex="-1" autocomplete="off" name="website">'
            . '</label></div>';

        if ($style === 'card') {
            $html .=
                '<div class="card-body p-4 p-md-5">';
        }

        foreach ($fields as $field) {
            $html .=
                self::renderField(
                    $field,
                    $old
                );
        }

        $html .=
            '<button class="btn btn-primary btn-lg px-4">'
            . self::e(
                $submitLabel
            )
            . '</button>';

        if ($style === 'card') {
            $html .=
                '</div>';
        }

        $html .=
            '</form></section>';

        return $html;
    }

    /**
     * Processa uma resposta do formulário incorporado.
     *
     * @return array{
     *   success:bool,
     *   form:array<string,mixed>|null,
     *   errors:array<int,string>,
     *   old:array<string,string>,
     *   message:string
     * }
     */
    public static function submit(
        PDO $pdo,
        int $formId,
        array $post
    ): array {
        $form =
            self::findPublished(
                $pdo,
                $formId
            );

        if (!$form) {
            return [
                'success' => false,
                'form' => null,
                'errors' => [
                    'Este formulário não está disponível.',
                ],
                'old' => [],
                'message' => '',
            ];
        }

        $fields =
            self::fields(
                $pdo,
                $formId
            );

        if (!$fields) {
            return [
                'success' => false,
                'form' => $form,
                'errors' => [
                    'Este formulário não possui campos ativos.',
                ],
                'old' => [],
                'message' => '',
            ];
        }

        $errors = [];

        if (
            !Csrf::validate(
                $post['_token']
                ?? null
            )
        ) {
            $errors[] =
                'Sua sessão expirou. Atualize a página e tente novamente.';
        }

        if (
            trim(
                (string)(
                    $post['website']
                    ?? ''
                )
            )
            !== ''
        ) {
            $errors[] =
                'Não foi possível enviar o formulário.';
        }

        $lastMap =
            is_array(
                $_SESSION[
                    '_last_embedded_form_submit'
                ]
                ?? null
            )
                ? $_SESSION[
                    '_last_embedded_form_submit'
                ]
                : [];

        $last =
            (int)(
                $lastMap[
                    (string)$formId
                ]
                ?? 0
            );

        if (
            $last > 0
            && time() - $last < 3
        ) {
            $errors[] =
                'Aguarde alguns segundos antes de enviar novamente.';
        }

        $values = [];
        $old = [];

        foreach ($fields as $field) {
            $fieldId =
                (int)(
                    $field['id']
                    ?? 0
                );

            if ($fieldId <= 0) {
                continue;
            }

            $key =
                'campo_'
                . $fieldId;

            $type =
                (string)(
                    $field['tipo']
                    ?? 'texto'
                );

            if ($type === 'checkbox') {
                $checked =
                    isset(
                        $post[$key]
                    );

                $value =
                    $checked
                        ? 'Sim'
                        : '';

                if ($checked) {
                    $old[$key] = '1';
                }
            } else {
                $value =
                    trim(
                        (string)(
                            $post[$key]
                            ?? ''
                        )
                    );

                $old[$key] =
                    self::cut(
                        $value,
                        20000
                    );
            }

            $label =
                trim(
                    (string)(
                        $field['rotulo']
                        ?? 'Campo'
                    )
                );

            if (
                (int)(
                    $field['obrigatorio']
                    ?? 0
                ) === 1
                && $value === ''
            ) {
                $errors[] =
                    'Preencha o campo "'
                    . $label
                    . '".';
            }

            if (
                $value !== ''
                && $type === 'email'
                && !filter_var(
                    $value,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $errors[] =
                    'Informe um e-mail válido.';
            }

            if (
                $value !== ''
                && $type === 'numero'
                && !is_numeric(
                    str_replace(
                        ',',
                        '.',
                        $value
                    )
                )
            ) {
                $errors[] =
                    'Informe um número válido em "'
                    . $label
                    . '".';
            }

            if (
                $type === 'select'
                && $value !== ''
            ) {
                $options =
                    self::fieldOptions(
                        (string)(
                            $field['opcoes']
                            ?? ''
                        )
                    );

                if (
                    !in_array(
                        $value,
                        $options,
                        true
                    )
                ) {
                    $errors[] =
                        'Seleção inválida em "'
                        . $label
                        . '".';
                }
            }

            $values[$fieldId] =
                self::cut(
                    $value,
                    20000
                );
        }

        if ($errors) {
            return [
                'success' => false,
                'form' => $form,
                'errors' =>
                    array_values(
                        array_unique(
                            $errors
                        )
                    ),
                'old' => $old,
                'message' => '',
            ];
        }

        try {
            $pdo->beginTransaction();

            $stmt =
                $pdo->prepare(
                    "INSERT INTO formulario_respostas
                        (
                            formulario_id,
                            status,
                            ip,
                            user_agent,
                            origem
                        )
                     VALUES
                        (
                            :formulario,
                            'nova',
                            :ip,
                            :ua,
                            :origem
                        )"
                );

            $stmt->execute([
                'formulario' =>
                    $formId,
                'ip' =>
                    isset(
                        $_SERVER['REMOTE_ADDR']
                    )
                        ? self::cut(
                            (string)$_SERVER['REMOTE_ADDR'],
                            45
                        )
                        : null,
                'ua' =>
                    isset(
                        $_SERVER['HTTP_USER_AGENT']
                    )
                        ? self::cut(
                            (string)$_SERVER['HTTP_USER_AGENT'],
                            500
                        )
                        : null,
                'origem' =>
                    isset(
                        $_SERVER['HTTP_REFERER']
                    )
                        ? self::cut(
                            (string)$_SERVER['HTTP_REFERER'],
                            500
                        )
                        : null,
            ]);

            $responseId =
                (int)$pdo
                    ->lastInsertId();

            $insert =
                $pdo->prepare(
                    "INSERT INTO formulario_resposta_valores
                        (
                            resposta_id,
                            campo_id,
                            valor
                        )
                     VALUES
                        (
                            :resposta,
                            :campo,
                            :valor
                        )"
                );

            foreach (
                $values
                as $fieldId => $value
            ) {
                $insert->execute([
                    'resposta' =>
                        $responseId,
                    'campo' =>
                        (int)$fieldId,
                    'valor' =>
                        $value !== ''
                            ? $value
                            : null,
                ]);
            }

            $pdo->commit();

            try {
                if (
                    class_exists(
                        'FormNotificationService'
                    )
                ) {
                    FormNotificationService::notifyResponse(
                        $pdo,
                        $form,
                        $fields,
                        $values,
                        $responseId
                    );
                }
            } catch (Throwable $ignored) {
                /*
                 * A notificação não invalida uma resposta já salva.
                 */
            }

            if (
                !isset(
                    $_SESSION[
                        '_last_embedded_form_submit'
                    ]
                )
                || !is_array(
                    $_SESSION[
                        '_last_embedded_form_submit'
                    ]
                )
            ) {
                $_SESSION[
                    '_last_embedded_form_submit'
                ] = [];
            }

            $_SESSION[
                '_last_embedded_form_submit'
            ][
                (string)$formId
            ] = time();

            $message =
                trim(
                    (string)(
                        $form['mensagem_sucesso']
                        ?? ''
                    )
                );

            if ($message === '') {
                $message =
                    'Sua resposta foi enviada com sucesso.';
            }

            return [
                'success' => true,
                'form' => $form,
                'errors' => [],
                'old' => [],
                'message' => $message,
            ];
        } catch (Throwable $e) {
            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            return [
                'success' => false,
                'form' => $form,
                'errors' => [
                    'Não foi possível registrar sua resposta. Tente novamente.',
                ],
                'old' => $old,
                'message' => '',
            ];
        }
    }

    /**
     * Guarda estado temporário da submissão para o bloco certo.
     *
     * @param array<string,mixed> $state
     */
    public static function rememberState(
        int $formId,
        string $instanceKey,
        array $state
    ): void {
        $instanceKey =
            self::normalizeInstanceKey(
                $instanceKey
            );

        if (
            $formId <= 0
            || $instanceKey === ''
        ) {
            return;
        }

        if (
            !isset(
                $_SESSION[
                    '_embedded_form_state'
                ]
            )
            || !is_array(
                $_SESSION[
                    '_embedded_form_state'
                ]
            )
        ) {
            $_SESSION[
                '_embedded_form_state'
            ] = [];
        }

        $_SESSION[
            '_embedded_form_state'
        ][
            self::stateKey(
                $formId,
                $instanceKey
            )
        ] = $state;
    }

    /**
     * @return array<string,mixed>
     */
    private static function takeState(
        int $formId,
        string $instanceKey
    ): array {
        $key =
            self::stateKey(
                $formId,
                $instanceKey
            );

        $state =
            $_SESSION[
                '_embedded_form_state'
            ][
                $key
            ]
            ?? [];

        unset(
            $_SESSION[
                '_embedded_form_state'
            ][
                $key
            ]
        );

        return
            is_array($state)
                ? $state
                : [];
    }

    public static function normalizeInstanceKey(
        string $value
    ): string {
        $value =
            trim(
                $value
            );

        $value =
            preg_replace(
                '/[^a-zA-Z0-9_-]+/',
                '-',
                $value
            )
            ?? '';

        $value =
            trim(
                $value,
                '-_'
            );

        return
            self::cut(
                $value,
                100
            );
    }

    public static function safeReturnTo(
        string $value
    ): string {
        $value =
            trim(
                $value
            );

        if (
            $value === ''
            || preg_match(
                '/[\r\n]/',
                $value
            )
            || str_starts_with(
                $value,
                '//'
            )
        ) {
            return '';
        }

        $parts =
            @parse_url(
                $value
            );

        if (
            !is_array($parts)
            || isset(
                $parts['scheme']
            )
            || isset(
                $parts['host']
            )
        ) {
            return '';
        }

        $path =
            (string)(
                $parts['path']
                ?? ''
            );

        if (
            $path === ''
            || !str_starts_with(
                $path,
                '/'
            )
            || str_contains(
                $path,
                "\0"
            )
        ) {
            return '';
        }

        $query =
            isset(
                $parts['query']
            )
                ? '?'
                    . (string)$parts['query']
                : '';

        return
            self::cut(
                $path
                . $query,
                1800
            );
    }

    public static function currentReturnTo(): string
    {
        $uri =
            self::safeReturnTo(
                (string)(
                    $_SERVER['REQUEST_URI']
                    ?? ''
                )
            );

        if ($uri !== '') {
            return $uri;
        }

        try {
            $root =
                url();

            $parts =
                @parse_url(
                    $root
                );

            if (
                is_array($parts)
                && !empty(
                    $parts['path']
                )
            ) {
                return
                    (string)$parts['path'];
            }
        } catch (Throwable $ignored) {
        }

        return '/';
    }

    private static function renderField(
        array $field,
        array $old
    ): string {
        $fieldId =
            (int)(
                $field['id']
                ?? 0
            );

        if ($fieldId <= 0) {
            return '';
        }

        $key =
            'campo_'
            . $fieldId;

        $type =
            (string)(
                $field['tipo']
                ?? 'texto'
            );

        $label =
            (string)(
                $field['rotulo']
                ?? ''
            );

        $required =
            (int)(
                $field['obrigatorio']
                ?? 0
            ) === 1;

        $placeholder =
            (string)(
                $field['placeholder']
                ?? ''
            );

        $value =
            (string)(
                $old[$key]
                ?? ''
            );

        $requiredAttr =
            $required
                ? ' required'
                : '';

        $requiredLabel =
            $required
                ? ' *'
                : '';

        $html =
            '<div class="mb-4">';

        if ($type === 'checkbox') {
            $html .=
                '<div class="form-check">'
                . '<input class="form-check-input" type="checkbox"'
                . ' id="'
                . self::e($key)
                . '"'
                . ' name="'
                . self::e($key)
                . '"'
                . ' value="1"'
                . (
                    isset(
                        $old[$key]
                    )
                        ? ' checked'
                        : ''
                )
                . $requiredAttr
                . '>'
                . '<label class="form-check-label" for="'
                . self::e($key)
                . '">'
                . self::e($label)
                . self::e($requiredLabel)
                . '</label></div>'
                . '</div>';

            return $html;
        }

        $html .=
            '<label class="form-label fw-semibold" for="'
            . self::e($key)
            . '">'
            . self::e($label)
            . self::e($requiredLabel)
            . '</label>';

        if ($type === 'textarea') {
            $html .=
                '<textarea class="form-control" rows="6"'
                . ' id="'
                . self::e($key)
                . '"'
                . ' name="'
                . self::e($key)
                . '"'
                . ' placeholder="'
                . self::e($placeholder)
                . '"'
                . $requiredAttr
                . '>'
                . self::e($value)
                . '</textarea>';
        } elseif ($type === 'select') {
            $html .=
                '<select class="form-select"'
                . ' id="'
                . self::e($key)
                . '"'
                . ' name="'
                . self::e($key)
                . '"'
                . $requiredAttr
                . '>'
                . '<option value="">Selecione...</option>';

            foreach (
                self::fieldOptions(
                    (string)(
                        $field['opcoes']
                        ?? ''
                    )
                )
                as $option
            ) {
                $html .=
                    '<option value="'
                    . self::e($option)
                    . '"'
                    . (
                        $value === $option
                            ? ' selected'
                            : ''
                    )
                    . '>'
                    . self::e($option)
                    . '</option>';
            }

            $html .=
                '</select>';
        } else {
            $inputType =
                match ($type) {
                    'email' => 'email',
                    'telefone' => 'tel',
                    'numero' => 'number',
                    'data' => 'date',
                    default => 'text',
                };

            $html .=
                '<input class="form-control"'
                . ' type="'
                . self::e($inputType)
                . '"'
                . ' id="'
                . self::e($key)
                . '"'
                . ' name="'
                . self::e($key)
                . '"'
                . ' value="'
                . self::e($value)
                . '"'
                . ' placeholder="'
                . self::e($placeholder)
                . '"'
                . $requiredAttr
                . '>';
        }

        return
            $html
            . '</div>';
    }

    /**
     * @return array<int,string>
     */
    private static function fieldOptions(
        string $value
    ): array {
        return
            array_values(
                array_filter(
                    array_map(
                        'trim',
                        preg_split(
                            '/\R/',
                            $value
                        )
                        ?: []
                    ),
                    static fn(string $item): bool =>
                        $item !== ''
                )
            );
    }

    private static function stateKey(
        int $formId,
        string $instanceKey
    ): string {
        return
            hash(
                'sha256',
                $formId
                . ':'
                . $instanceKey
            );
    }

    private static function cut(
        string $value,
        int $length
    ): string {
        return
            function_exists(
                'mb_substr'
            )
                ? mb_substr(
                    $value,
                    0,
                    $length
                )
                : substr(
                    $value,
                    0,
                    $length
                );
    }

    private static function e(
        string $value
    ): string {
        return
            htmlspecialchars(
                $value,
                ENT_QUOTES
                | ENT_SUBSTITUTE,
                'UTF-8'
            );
    }
}

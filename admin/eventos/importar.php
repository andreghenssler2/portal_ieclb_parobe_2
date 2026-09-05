<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('eventos.gerenciar');

$pdo = Database::connection();

$comunidades =
    $pdo->query(
        'SELECT id,nome
         FROM comunidades
         WHERE ativa=1
         ORDER BY ordem,nome'
    )->fetchAll()
    ?: [];

$error = '';
$success = '';
$importedCount = 0;
$updatedCount = 0;

function externalCalendarUnescape(string $value): string
{
    return str_replace(
        ['\\n', '\\N', '\\,', '\\;', '\\\\'],
        ["\n", "\n", ',', ';', '\\'],
        $value
    );
}

function externalCalendarUnfold(string $content): array
{
    $content =
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $content
        );

    $rawLines =
        explode(
            "\n",
            $content
        );

    $lines = [];

    foreach ($rawLines as $line) {
        if (
            $lines
            && (
                str_starts_with(
                    $line,
                    ' '
                )
                || str_starts_with(
                    $line,
                    "\t"
                )
            )
        ) {
            $lines[
                array_key_last(
                    $lines
                )
            ] .= substr(
                $line,
                1
            );

            continue;
        }

        $lines[] = $line;
    }

    return $lines;
}

function externalCalendarParseProperty(string $line): ?array
{
    $pos =
        strpos(
            $line,
            ':'
        );

    if ($pos === false) {
        return null;
    }

    $left =
        substr(
            $line,
            0,
            $pos
        );

    $value =
        substr(
            $line,
            $pos + 1
        );

    $parts =
        explode(
            ';',
            $left
        );

    $name =
        strtoupper(
            trim(
                (string)array_shift(
                    $parts
                )
            )
        );

    if ($name === '') {
        return null;
    }

    $params = [];

    foreach ($parts as $part) {
        $eq =
            strpos(
                $part,
                '='
            );

        if ($eq === false) {
            continue;
        }

        $paramName =
            strtoupper(
                trim(
                    substr(
                        $part,
                        0,
                        $eq
                    )
                )
            );

        $paramValue =
            trim(
                substr(
                    $part,
                    $eq + 1
                ),
                "\"'"
            );

        if ($paramName !== '') {
            $params[$paramName] =
                $paramValue;
        }
    }

    return [
        'name' =>
            $name,
        'params' =>
            $params,
        'value' =>
            externalCalendarUnescape(
                $value
            ),
    ];
}

function externalCalendarParseEvents(string $content): array
{
    $events = [];
    $current = null;

    foreach (
        externalCalendarUnfold(
            $content
        )
        as $line
    ) {
        $line =
            trim(
                $line
            );

        if ($line === 'BEGIN:VEVENT') {
            $current = [];
            continue;
        }

        if ($line === 'END:VEVENT') {
            if (
                is_array(
                    $current
                )
            ) {
                $events[] =
                    $current;
            }

            $current = null;
            continue;
        }

        if (
            !is_array(
                $current
            )
        ) {
            continue;
        }

        $property =
            externalCalendarParseProperty(
                $line
            );

        if (!$property) {
            continue;
        }

        $name =
            (string)$property['name'];

        if (
            !isset(
                $current[$name]
            )
        ) {
            $current[$name] =
                $property;
        }
    }

    return $events;
}

function externalCalendarDateTime(
    ?array $property,
    DateTimeZone $portalTz
): ?DateTimeImmutable {
    if (!$property) {
        return null;
    }

    $value =
        trim(
            (string)(
                $property['value']
                ?? ''
            )
        );

    if ($value === '') {
        return null;
    }

    $params =
        is_array(
            $property['params']
            ?? null
        )
            ? $property['params']
            : [];

    $valueType =
        strtoupper(
            (string)(
                $params['VALUE']
                ?? ''
            )
        );

    if (
        $valueType === 'DATE'
        || preg_match(
            '/^\d{8}$/',
            $value
        )
    ) {
        $date =
            DateTimeImmutable::createFromFormat(
                '!Ymd',
                substr(
                    $value,
                    0,
                    8
                ),
                $portalTz
            );

        return
            $date instanceof DateTimeImmutable
                ? $date
                : null;
    }

    $timezone =
        $portalTz;

    if (
        str_ends_with(
            $value,
            'Z'
        )
    ) {
        $timezone =
            new DateTimeZone(
                'UTC'
            );

        $value =
            substr(
                $value,
                0,
                -1
            );
    } elseif (
        !empty(
            $params['TZID']
        )
    ) {
        try {
            $timezone =
                new DateTimeZone(
                    (string)$params['TZID']
                );
        } catch (Throwable $ignored) {
            $timezone =
                $portalTz;
        }
    }

    foreach (
        [
            '!Ymd\THis',
            '!Ymd\THi',
        ]
        as $format
    ) {
        $date =
            DateTimeImmutable::createFromFormat(
                $format,
                $value,
                $timezone
            );

        if (
            $date instanceof DateTimeImmutable
        ) {
            return
                $date->setTimezone(
                    $portalTz
                );
        }
    }

    return null;
}

function externalCalendarDetectType(
    string $title,
    string $fallback
): string {
    $text =
        mb_strtolower(
            $title
        );

    if (
        str_contains(
            $text,
            'culto'
        )
        || str_contains(
            $text,
            'celebração'
        )
        || str_contains(
            $text,
            'celebracao'
        )
    ) {
        return 'culto';
    }

    foreach (
        [
            'festa',
            'kerb',
            'jantar',
            'almoço',
            'almoco',
            'chá ',
            'cha ',
            'baile',
            'vispada',
        ]
        as $needle
    ) {
        if (
            str_contains(
                $text,
                $needle
            )
        ) {
            return 'festa';
        }
    }

    foreach (
        [
            'reunião',
            'reuniao',
            'presbitério',
            'presbiterio',
            'conselho',
            'assembleia',
        ]
        as $needle
    ) {
        if (
            str_contains(
                $text,
                $needle
            )
        ) {
            return 'reuniao';
        }
    }

    return $fallback;
}

function externalCalendarSlug(
    array $event,
    string $title,
    DateTimeImmutable $start
): string {
    $uid =
        trim(
            (string)(
                $event['UID']['value']
                ?? ''
            )
        );

    $location =
        trim(
            (string)(
                $event['LOCATION']['value']
                ?? ''
            )
        );

    $identity =
        $uid !== ''
            ? $uid
            : (
                $title
                . '|'
                . $start->format(
                    'c'
                )
                . '|'
                . $location
            );

    return
        'externo-'
        . substr(
            sha1(
                $identity
            ),
            0,
            32
        );
}

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    if (
        !Csrf::validate(
            $_POST['_token']
            ?? null
        )
    ) {
        $error =
            'Token de segurança inválido.';
    } else {
        $defaultType =
            (string)(
                $_POST['tipo_padrao']
                ?? 'atividade'
            );

        if (
            !in_array(
                $defaultType,
                [
                    'culto',
                    'festa',
                    'atividade',
                    'reuniao',
                ],
                true
            )
        ) {
            $defaultType =
                'atividade';
        }

        $status =
            (string)(
                $_POST['status']
                ?? 'rascunho'
            );

        if (
            !in_array(
                $status,
                [
                    'rascunho',
                    'publicado',
                ],
                true
            )
        ) {
            $status =
                'rascunho';
        }

        $communityId =
            ($_POST['comunidade_id'] ?? '') !== ''
                ? (int)$_POST['comunidade_id']
                : null;

        if (
            $communityId !== null
        ) {
            $stmt =
                $pdo->prepare(
                    'SELECT id
                     FROM comunidades
                     WHERE id=?
                       AND ativa=1
                     LIMIT 1'
                );

            $stmt->execute(
                [
                    $communityId,
                ]
            );

            if (
                !(int)$stmt->fetchColumn()
            ) {
                $communityId = null;
            }
        }

        $autoDetect =
            isset(
                $_POST['detectar_tipo']
            );

        $file =
            $_FILES['arquivo_ics']
            ?? null;

        if (
            !$file
            || (int)(
                $file['error']
                ?? UPLOAD_ERR_NO_FILE
            )
                === UPLOAD_ERR_NO_FILE
        ) {
            $error =
                'Selecione um arquivo .ics.';
        } elseif (
            (int)$file['error']
            !== UPLOAD_ERR_OK
        ) {
            $error =
                'Falha no upload do arquivo .ics.';
        } elseif (
            (int)$file['size']
            > 5 * 1024 * 1024
        ) {
            $error =
                'O arquivo .ics excede o limite de 5 MB.';
        } else {
            $name =
                strtolower(
                    (string)(
                        $file['name']
                        ?? ''
                    )
                );

            if (
                !str_ends_with(
                    $name,
                    '.ics'
                )
            ) {
                $error =
                    'Envie um arquivo no formato .ics.';
            } else {
                $content =
                    file_get_contents(
                        (string)$file['tmp_name']
                    );

                if (
                    !is_string(
                        $content
                    )
                    || trim(
                        $content
                    ) === ''
                ) {
                    $error =
                        'O arquivo .ics está vazio ou não pôde ser lido.';
                } else {
                    $rawEvents =
                        externalCalendarParseEvents(
                            $content
                        );

                    if (!$rawEvents) {
                        $error =
                            'Nenhum VEVENT foi encontrado no arquivo.';
                    } else {
                        $portalTz =
                            new DateTimeZone(
                                'America/Sao_Paulo'
                            );

                        $sql = "
                            INSERT INTO eventos (
                                autor_id,
                                comunidade_id,
                                categoria_evento_id,
                                tipo,
                                titulo,
                                slug,
                                resumo,
                                descricao,
                                local,
                                endereco,
                                data_inicio,
                                data_fim,
                                santa_ceia,
                                imagem_capa_id,
                                seo_titulo,
                                seo_descricao,
                                seo_noindex,
                                status
                            ) VALUES (
                                :autor_id,
                                :comunidade_id,
                                NULL,
                                :tipo,
                                :titulo,
                                :slug,
                                :resumo,
                                :descricao,
                                :local,
                                NULL,
                                :data_inicio,
                                :data_fim,
                                0,
                                NULL,
                                NULL,
                                NULL,
                                0,
                                :status
                            )
                            ON DUPLICATE KEY UPDATE
                                comunidade_id=VALUES(comunidade_id),
                                tipo=VALUES(tipo),
                                titulo=VALUES(titulo),
                                resumo=VALUES(resumo),
                                descricao=VALUES(descricao),
                                local=VALUES(local),
                                data_inicio=VALUES(data_inicio),
                                data_fim=VALUES(data_fim),
                                status=VALUES(status)
                        ";

                        $stmt =
                            $pdo->prepare(
                                $sql
                            );

                        try {
                            $pdo->beginTransaction();

                            foreach (
                                $rawEvents
                                as $event
                            ) {
                                $title =
                                    trim(
                                        (string)(
                                            $event['SUMMARY']['value']
                                            ?? ''
                                        )
                                    );

                                $start =
                                    externalCalendarDateTime(
                                        $event['DTSTART']
                                            ?? null,
                                        $portalTz
                                    );

                                if (
                                    $title === ''
                                    || !$start
                                ) {
                                    continue;
                                }

                                $end =
                                    externalCalendarDateTime(
                                        $event['DTEND']
                                            ?? null,
                                        $portalTz
                                    );

                                if (
                                    $end
                                    && $end < $start
                                ) {
                                    $end = null;
                                }

                                $type =
                                    $autoDetect
                                        ? externalCalendarDetectType(
                                            $title,
                                            $defaultType
                                        )
                                        : $defaultType;

                                $description =
                                    trim(
                                        (string)(
                                            $event['DESCRIPTION']['value']
                                            ?? ''
                                        )
                                    );

                                $location =
                                    trim(
                                        (string)(
                                            $event['LOCATION']['value']
                                            ?? ''
                                        )
                                    );

                                $slug =
                                    externalCalendarSlug(
                                        $event,
                                        $title,
                                        $start
                                    );

                                $already =
                                    $pdo->prepare(
                                        'SELECT id
                                         FROM eventos
                                         WHERE slug=?
                                         LIMIT 1'
                                    );

                                $already->execute(
                                    [
                                        $slug,
                                    ]
                                );

                                $exists =
                                    (int)$already->fetchColumn()
                                    > 0;

                                $stmt->execute(
                                    [
                                        'autor_id' =>
                                            (int)Auth::id(),
                                        'comunidade_id' =>
                                            $communityId,
                                        'tipo' =>
                                            $type,
                                        'titulo' =>
                                            mb_substr(
                                                $title,
                                                0,
                                                220
                                            ),
                                        'slug' =>
                                            $slug,
                                        'resumo' =>
                                            mb_substr(
                                                $description !== ''
                                                    ? $description
                                                    : $title,
                                                0,
                                                1000
                                            ),
                                        'descricao' =>
                                            $description !== ''
                                                ? nl2br(
                                                    e(
                                                        $description
                                                    )
                                                )
                                                : null,
                                        'local' =>
                                            $location !== ''
                                                ? mb_substr(
                                                    $location,
                                                    0,
                                                    255
                                                )
                                                : null,
                                        'data_inicio' =>
                                            $start->format(
                                                'Y-m-d H:i:s'
                                            ),
                                        'data_fim' =>
                                            $end
                                                ? $end->format(
                                                    'Y-m-d H:i:s'
                                                )
                                                : null,
                                        'status' =>
                                            $status,
                                    ]
                                );

                                if ($exists) {
                                    $updatedCount++;
                                } else {
                                    $importedCount++;
                                }
                            }

                            $pdo->commit();

                            $total =
                                $importedCount
                                + $updatedCount;

                            if ($total === 0) {
                                $error =
                                    'Nenhum evento válido foi importado. Verifique se os itens possuem título e data de início.';
                            } else {
                                $success =
                                    "{$total} item(ns) processado(s): "
                                    . "{$importedCount} novo(s) e "
                                    . "{$updatedCount} atualizado(s).";

                                logAction(
                                    $pdo,
                                    'agenda.importar_externo',
                                    'eventos',
                                    null,
                                    $success
                                );
                            }
                        } catch (Throwable $e) {
                            if (
                                $pdo->inTransaction()
                            ) {
                                $pdo->rollBack();
                            }

                            $error =
                                'Não foi possível concluir a importação: '
                                . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

$pageTitle =
    'Importação externa da Agenda';

require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Importação externa
        </h1>

        <p class="text-secondary mb-0">
            Importe compromissos de Google Calendar, Outlook, Apple Calendar e outros sistemas pelo formato iCalendar (.ics).
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/eventos/index.php')) ?>"
    >
        <i class="bi bi-arrow-left me-1"></i>
        Voltar para a Agenda
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($success !== ''): ?>
    <div class="alert alert-success">
        <?= e($success) ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <form
            method="post"
            enctype="multipart/form-data"
            class="card border-0 shadow-sm"
        >
            <div class="card-header bg-white fw-semibold">
                Importar arquivo iCalendar
            </div>

            <div class="card-body p-4">
                <?= Csrf::field() ?>

                <div class="mb-4">
                    <label class="form-label fw-semibold">
                        Arquivo .ics
                    </label>

                    <input
                        type="file"
                        class="form-control"
                        name="arquivo_ics"
                        accept=".ics,text/calendar"
                        required
                    >

                    <div class="form-text">
                        Limite: 5 MB.
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">
                            Tipo padrão
                        </label>

                        <select
                            class="form-select"
                            name="tipo_padrao"
                        >
                            <option value="atividade">
                                Atividade
                            </option>
                            <option value="culto">
                                Culto
                            </option>
                            <option value="festa">
                                Festa
                            </option>
                            <option value="reuniao">
                                Reunião
                            </option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Comunidade padrão
                        </label>

                        <select
                            class="form-select"
                            name="comunidade_id"
                        >
                            <option value="">
                                Paroquial / sem comunidade específica
                            </option>

                            <?php foreach ($comunidades as $comunidade): ?>
                                <option
                                    value="<?= (int)$comunidade['id'] ?>"
                                >
                                    <?= e((string)$comunidade['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Status
                        </label>

                        <select
                            class="form-select"
                            name="status"
                        >
                            <option value="rascunho">
                                Rascunho
                            </option>
                            <option value="publicado">
                                Publicado
                            </option>
                        </select>
                    </div>

                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                name="detectar_tipo"
                                id="detectarTipo"
                                checked
                            >

                            <label
                                class="form-check-label"
                                for="detectarTipo"
                            >
                                Detectar tipo automaticamente pelo título
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end">
                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    <i class="bi bi-upload me-1"></i>
                    Importar para a Agenda
                </button>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Como funciona
            </div>

            <div class="card-body">
                <p class="small text-secondary">
                    Exporte o calendário de origem como arquivo <strong>.ics</strong> e envie aqui.
                </p>

                <ul class="small mb-0">
                    <li>
                        O UID externo gera uma identificação estável.
                    </li>
                    <li>
                        Reimportar o mesmo arquivo atualiza os itens em vez de duplicá-los.
                    </li>
                    <li>
                        Título, descrição, local, início e término são aproveitados.
                    </li>
                    <li>
                        A detecção automática reconhece cultos, festas e reuniões pelo título; os demais usam o tipo padrão.
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>

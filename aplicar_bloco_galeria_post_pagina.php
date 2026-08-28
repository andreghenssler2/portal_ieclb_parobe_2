<?php

declare(strict_types=1);

/**
 * Portal IECLB Parobé
 * Adiciona o bloco "Galeria existente" ao editor de blocos de Posts e Páginas.
 *
 * O novo bloco permite escolher uma galeria cadastrada e inserir as FOTOS
 * da galeria dentro da notícia/página.
 *
 * Execute na RAIZ do Portal:
 *   php aplicar_bloco_galeria_post_pagina.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute este arquivo pela linha de comando.\n");
    exit(1);
}

$root = __DIR__;
$stamp = date('Ymd-His');
$backupDir = $root . '/backup_bloco_galeria_' . $stamp;

$files = [
    'admin/_content_blocks_editor.php',
    'public/js/content-block-editor.js',
    'app/Services/ContentBlockService.php',
    'app/Services/DynamicContentBlockService.php',
];

foreach ($files as $relative) {
    $absolute = $root . '/' . $relative;
    if (!is_file($absolute)) {
        fwrite(STDERR, "Arquivo obrigatório não encontrado: {$relative}\n");
        exit(1);
    }
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Não foi possível criar a pasta de backup.\n");
    exit(1);
}

function failPatch(string $file, string $step): never
{
    fwrite(
        STDERR,
        "\nNão foi possível aplicar a etapa '{$step}' em:\n  {$file}\n\n"
        . "O arquivo da sua instalação está diferente do esperado.\n"
        . "Nenhuma alteração incompleta deve ser usada. Restaure pelo backup criado.\n"
    );
    exit(2);
}

function replaceRequired(
    string $content,
    string $search,
    string $replace,
    string $file,
    string $step,
    int $limit = 1
): string {
    if (!str_contains($content, $search)) {
        failPatch($file, $step);
    }

    if ($limit === 1) {
        $pos = strpos($content, $search);
        return substr_replace($content, $replace, $pos, strlen($search));
    }

    return str_replace($search, $replace, $content);
}

function patchFile(
    string $root,
    string $backupDir,
    string $relative,
    callable $patcher
): void {
    $file = $root . '/' . $relative;
    $content = file_get_contents($file);

    if ($content === false) {
        fwrite(STDERR, "Não foi possível ler {$relative}\n");
        exit(1);
    }

    $backupFile = $backupDir . '/' . $relative;
    $backupParent = dirname($backupFile);

    if (!is_dir($backupParent) && !mkdir($backupParent, 0755, true) && !is_dir($backupParent)) {
        fwrite(STDERR, "Não foi possível criar backup de {$relative}\n");
        exit(1);
    }

    if (!copy($file, $backupFile)) {
        fwrite(STDERR, "Não foi possível criar backup de {$relative}\n");
        exit(1);
    }

    $updated = $patcher($content, $relative);

    if (!is_string($updated) || $updated === '') {
        fwrite(STDERR, "Resultado inválido ao alterar {$relative}\n");
        exit(1);
    }

    if ($updated === $content) {
        echo "[OK] {$relative}: já estava atualizado.\n";
        return;
    }

    if (file_put_contents($file, $updated) === false) {
        fwrite(STDERR, "Não foi possível gravar {$relative}\n");
        exit(1);
    }

    echo "[OK] {$relative}: atualizado.\n";
}

/* -------------------------------------------------------------------------
 * 1) Editor administrativo compartilhado por Posts e Páginas
 * ---------------------------------------------------------------------- */
patchFile($root, $backupDir, 'admin/_content_blocks_editor.php',
    static function (string $content, string $file): string {
        if (!str_contains($content, "'galerias' => []")) {
            $content = replaceRequired(
                $content,
                "    'documento_categorias' => [],\n",
                "    'documento_categorias' => [],\n    'galerias' => [],\n",
                $file,
                'opções de galerias'
            );
        }

        if (!str_contains($content, 'data-block-add="portal_gallery"')) {
            $anchor = '                    <li><button class="dropdown-item" type="button" data-block-add="portal_galleries"><i class="bi bi-images me-2"></i>Galerias</button></li>';

            $new = '                    <li><button class="dropdown-item" type="button" data-block-add="portal_gallery"><i class="bi bi-images me-2"></i>Galeria existente</button></li>' . "\n"
                . '                    <li><button class="dropdown-item" type="button" data-block-add="portal_galleries"><i class="bi bi-collection me-2"></i>Galerias recentes</button></li>';

            $content = replaceRequired(
                $content,
                $anchor,
                $new,
                $file,
                'botão Galeria existente'
            );
        }

        return $content;
    }
);

/* -------------------------------------------------------------------------
 * 2) JavaScript do editor de blocos
 * ---------------------------------------------------------------------- */
patchFile($root, $backupDir, 'public/js/content-block-editor.js',
    static function (string $content, string $file): string {
        if (str_contains($content, "portal_gallery: 'Galeria de fotos'")) {
            return $content;
        }

        // LABELS
        $content = replaceRequired(
            $content,
            "    portal_galleries: 'Galerias',\n",
            "    portal_gallery: 'Galeria de fotos',\n    portal_galleries: 'Galerias recentes',\n",
            $file,
            'LABELS portal_gallery'
        );

        // Dados padrão.
        $content = replaceRequired(
            $content,
            "      case 'portal_galleries': return {title:'Galerias', limit:4, layout:'cards', show_date:'1'};\n",
            "      case 'portal_gallery': return {gallery_id:0, title:'', columns:'3', limit:'0', show_title:'1', show_captions:'1', show_link:'1'};\n"
            . "      case 'portal_galleries': return {title:'Galerias', limit:4, layout:'cards', show_date:'1'};\n",
            $file,
            'defaultData portal_gallery'
        );

        // Resumo do cartão no editor.
        $summaryAnchor = "        case 'portal_documents':\n        case 'portal_galleries':\n        case 'portal_communities':\n";
        $summaryNew = "        case 'portal_documents':\n        case 'portal_gallery':\n        case 'portal_galleries':\n        case 'portal_communities':\n";
        $content = replaceRequired(
            $content,
            $summaryAnchor,
            $summaryNew,
            $file,
            'summary portal_gallery'
        );

        // Campos específicos do bloco.
        $fieldsAnchor = <<<'JS'
      case 'portal_galleries':
        return `
          <div class="alert alert-info py-2 small">Mostra automaticamente as Galerias publicadas mais recentes.</div>
JS;

        $galleryFields = <<<'JS'
      case 'portal_gallery':
        return `
          <div class="alert alert-info py-2 small">
            Insere as fotos de uma galeria existente diretamente dentro desta postagem ou página.
            A galeria precisa estar publicada para aparecer no site público.
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-8">
              <label class="form-label small">Galeria</label>
              <select class="form-select form-select-sm" data-field="gallery_id">
                ${selectOptions(dynamicOptions?.galerias, data.gallery_id, 'Selecione uma galeria')}
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small">Colunas</label>
              <select class="form-select form-select-sm" data-field="columns">
                <option value="2" ${String(data.columns) === '2' ? 'selected' : ''}>2 colunas</option>
                <option value="3" ${String(data.columns || '3') === '3' ? 'selected' : ''}>3 colunas</option>
                <option value="4" ${String(data.columns) === '4' ? 'selected' : ''}>4 colunas</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-8">
              <label class="form-label small">Título personalizado</label>
              <input
                class="form-control form-control-sm"
                data-field="title"
                value="${esc(data.title || '')}"
                placeholder="Vazio = usa o título da galeria"
              >
            </div>
            <div class="col-md-4">
              <label class="form-label small">Limite de fotos</label>
              <input
                class="form-control form-control-sm"
                type="number"
                min="0"
                max="100"
                data-field="limit"
                value="${Number(data.limit || 0)}"
              >
              <div class="form-text">0 = todas</div>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-md-4">${yesNoSelect('show_title', data.show_title, 'Mostrar título')}</div>
            <div class="col-md-4">${yesNoSelect('show_captions', data.show_captions, 'Mostrar legendas')}</div>
            <div class="col-md-4">${yesNoSelect('show_link', data.show_link, 'Link para álbum completo')}</div>
          </div>`;

      case 'portal_galleries':
        return `
          <div class="alert alert-info py-2 small">Mostra automaticamente as Galerias publicadas mais recentes.</div>
JS;

        $content = replaceRequired(
            $content,
            $fieldsAnchor,
            $galleryFields,
            $file,
            'campos do bloco Galeria existente'
        );

        return $content;
    }
);

/* -------------------------------------------------------------------------
 * 3) Serviço central de blocos
 * ---------------------------------------------------------------------- */
patchFile($root, $backupDir, 'app/Services/ContentBlockService.php',
    static function (string $content, string $file): string {
        if (str_contains($content, "'portal_gallery',")) {
            return $content;
        }

        // Tipo permitido.
        $content = replaceRequired(
            $content,
            "        'portal_galleries',\n",
            "        'portal_gallery',\n        'portal_galleries',\n",
            $file,
            'tipo permitido portal_gallery'
        );

        // Sanitização dinâmica.
        $sanitizeAnchor = <<<'PHP'
            'portal_documents',
            'portal_galleries',
            'portal_communities'
                => DynamicContentBlockService::sanitize($pdo, $type, $data),
PHP;
        $sanitizeNew = <<<'PHP'
            'portal_documents',
            'portal_gallery',
            'portal_galleries',
            'portal_communities'
                => DynamicContentBlockService::sanitize($pdo, $type, $data),
PHP;
        $content = replaceRequired(
            $content,
            $sanitizeAnchor,
            $sanitizeNew,
            $file,
            'sanitize portal_gallery'
        );

        // Conteúdo válido: a galeria precisa ter sido escolhida.
        $hasAnchor = <<<'PHP'
            'separator' => true,
            'portal_posts',
PHP;
        $hasNew = <<<'PHP'
            'separator' => true,
            'portal_gallery' => (int)($data['gallery_id'] ?? 0) > 0,
            'portal_posts',
PHP;
        $content = replaceRequired(
            $content,
            $hasAnchor,
            $hasNew,
            $file,
            'blockHasContent portal_gallery'
        );

        // Renderização dinâmica.
        $renderAnchor = <<<'PHP'
            'portal_documents',
            'portal_galleries',
            'portal_communities'
                => DynamicContentBlockService::render($pdo, $type, $data),
PHP;
        $renderNew = <<<'PHP'
            'portal_documents',
            'portal_gallery',
            'portal_galleries',
            'portal_communities'
                => DynamicContentBlockService::render($pdo, $type, $data),
PHP;
        $content = replaceRequired(
            $content,
            $renderAnchor,
            $renderNew,
            $file,
            'render portal_gallery'
        );

        return $content;
    }
);

/* -------------------------------------------------------------------------
 * 4) Serviço dos blocos dinâmicos
 * ---------------------------------------------------------------------- */
patchFile($root, $backupDir, 'app/Services/DynamicContentBlockService.php',
    static function (string $content, string $file): string {
        if (str_contains($content, 'private static function renderGalleryEmbed')) {
            return $content;
        }

        // Lista de galerias para o SELECT do editor.
        $optionsAnchor = <<<'PHP'
            'documento_categorias' => self::simpleOptions(
                $pdo,
                'documento_categorias',
                'nome',
                'id',
                'ordem ASC,nome ASC,id ASC',
                self::columnExists($pdo, 'documento_categorias', 'ativo')
                    ? 'ativo=1'
                    : ''
            ),
        ];
PHP;

        $optionsNew = <<<'PHP'
            'documento_categorias' => self::simpleOptions(
                $pdo,
                'documento_categorias',
                'nome',
                'id',
                'ordem ASC,nome ASC,id ASC',
                self::columnExists($pdo, 'documento_categorias', 'ativo')
                    ? 'ativo=1'
                    : ''
            ),
            'galerias' => self::simpleOptions(
                $pdo,
                'galerias',
                'titulo',
                'id',
                'titulo ASC,id DESC',
                self::columnExists($pdo, 'galerias', 'status')
                    ? "status<>'arquivado'"
                    : ''
            ),
        ];
PHP;

        $content = replaceRequired(
            $content,
            $optionsAnchor,
            $optionsNew,
            $file,
            'editorOptions galerias'
        );

        // Sanitização do novo bloco.
        $sanitizeAnchor = <<<'PHP'
            'portal_galleries' => $common + [
                'show_date' => !array_key_exists('show_date', $data)
                    || !empty($data['show_date']),
            ],
PHP;

        $sanitizeNew = <<<'PHP'
            'portal_gallery' => [
                'gallery_id' => self::validId(
                    $pdo,
                    'galerias',
                    (int)($data['gallery_id'] ?? 0)
                ),
                'title' => self::cut(trim((string)($data['title'] ?? '')), 180),
                'columns' => in_array(
                    (string)($data['columns'] ?? '3'),
                    ['2','3','4'],
                    true
                ) ? (string)$data['columns'] : '3',
                'limit' => max(0, min(100, (int)($data['limit'] ?? 0))),
                'show_title' => !array_key_exists('show_title', $data)
                    || !empty($data['show_title']),
                'show_captions' => !array_key_exists('show_captions', $data)
                    || !empty($data['show_captions']),
                'show_link' => !array_key_exists('show_link', $data)
                    || !empty($data['show_link']),
            ],
            'portal_galleries' => $common + [
                'show_date' => !array_key_exists('show_date', $data)
                    || !empty($data['show_date']),
            ],
PHP;

        $content = replaceRequired(
            $content,
            $sanitizeAnchor,
            $sanitizeNew,
            $file,
            'sanitize configuração da galeria'
        );

        // Dispatcher público.
        $renderAnchor = <<<'PHP'
                'portal_documents' => self::renderDocuments($pdo, $data),
                'portal_galleries' => self::renderGalleries($pdo, $data),
PHP;
        $renderNew = <<<'PHP'
                'portal_documents' => self::renderDocuments($pdo, $data),
                'portal_gallery' => self::renderGalleryEmbed($pdo, $data),
                'portal_galleries' => self::renderGalleries($pdo, $data),
PHP;
        $content = replaceRequired(
            $content,
            $renderAnchor,
            $renderNew,
            $file,
            'dispatcher renderGalleryEmbed'
        );

        // Método que importa as fotos da galeria no conteúdo.
        $methodAnchor = '    private static function renderGalleries(PDO $pdo, array $data): string';

        $method = <<<'PHP'
    /**
     * Insere uma galeria específica dentro de uma Página/Notícia.
     * Diferente de portal_galleries, que lista álbuns recentes, este bloco
     * renderiza as próprias fotos do álbum escolhido.
     */
    private static function renderGalleryEmbed(PDO $pdo, array $data): string
    {
        if (
            !self::tableExists($pdo, 'galerias')
            || !self::tableExists($pdo, 'galeria_midias')
            || !self::tableExists($pdo, 'midias')
        ) {
            return '';
        }

        $galleryId = max(0, (int)($data['gallery_id'] ?? 0));
        if ($galleryId <= 0) {
            return '';
        }

        $stmt = $pdo->prepare(
            "SELECT id,titulo,slug,descricao,status,publicado_em
             FROM galerias
             WHERE id=:id
               AND status='publicado'
               AND (publicado_em IS NULL OR publicado_em<=NOW())
             LIMIT 1"
        );
        $stmt->execute(['id' => $galleryId]);
        $gallery = $stmt->fetch();

        if (!$gallery) {
            return '';
        }

        $limit = max(0, min(100, (int)($data['limit'] ?? 0)));

        $sql =
            "SELECT gm.id,gm.legenda,gm.ordem,
                    m.caminho,m.alt_text,m.titulo,m.nome_original
             FROM galeria_midias gm
             INNER JOIN midias m ON m.id=gm.midia_id
             WHERE gm.galeria_id=:galeria
               AND m.mime_type LIKE 'image/%'
             ORDER BY gm.ordem ASC,gm.id ASC";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $photosStmt = $pdo->prepare($sql);
        $photosStmt->execute(['galeria' => $galleryId]);
        $photos = $photosStmt->fetchAll() ?: [];

        if (!$photos) {
            return '';
        }

        $columns = (string)($data['columns'] ?? '3');
        $columnClass = match ($columns) {
            '2' => 'col-md-6',
            '4' => 'col-6 col-md-4 col-xl-3',
            default => 'col-sm-6 col-lg-4',
        };

        $showTitle = !array_key_exists('show_title', $data)
            || !empty($data['show_title']);
        $showCaptions = !array_key_exists('show_captions', $data)
            || !empty($data['show_captions']);
        $showLink = !array_key_exists('show_link', $data)
            || !empty($data['show_link']);

        $customTitle = trim((string)($data['title'] ?? ''));
        $sectionTitle = $customTitle !== ''
            ? $customTitle
            : (string)$gallery['titulo'];

        $html = '<section class="portal-gallery-embed my-5"'
            . ' data-gallery-id="' . (int)$galleryId . '">';

        if ($showTitle && $sectionTitle !== '') {
            $html .= '<div class="d-flex flex-wrap align-items-center'
                . ' justify-content-between gap-2 mb-3">'
                . '<h2 class="h3 mb-0">' . self::e($sectionTitle) . '</h2>';

            if ($showLink) {
                $html .= '<a class="btn btn-sm btn-outline-primary" href="'
                    . self::e(contentUrl('galeria', (string)$gallery['slug']))
                    . '">Ver álbum completo</a>';
            }

            $html .= '</div>';
        }

        $html .= '<div class="row g-3">';

        foreach ($photos as $photo) {
            $image = self::imageUrl((string)($photo['caminho'] ?? ''));
            if ($image === '') {
                continue;
            }

            $alt = trim((string)($photo['alt_text'] ?? ''));
            if ($alt === '') {
                $alt = trim((string)($photo['titulo'] ?? ''));
            }
            if ($alt === '') {
                $alt = (string)$gallery['titulo'];
            }

            $caption = trim((string)($photo['legenda'] ?? ''));

            $html .= '<div class="' . self::e($columnClass) . '">'
                . '<figure class="mb-0 h-100">'
                . '<a href="' . self::e($image) . '"'
                . ' target="_blank" rel="noopener"'
                . ' class="d-block rounded-3 overflow-hidden bg-body-tertiary">'
                . '<img src="' . self::e($image) . '"'
                . ' alt="' . self::e($alt) . '"'
                . ' loading="lazy"'
                . ' class="w-100 d-block"'
                . ' style="height:240px;object-fit:cover">'
                . '</a>';

            if ($showCaptions && $caption !== '') {
                $html .= '<figcaption class="small text-secondary mt-2">'
                    . self::e($caption)
                    . '</figcaption>';
            }

            $html .= '</figure></div>';
        }

        $html .= '</div>';

        // Se o título estiver oculto, ainda permite exibir o link do álbum.
        if (!$showTitle && $showLink) {
            $html .= '<div class="mt-3">'
                . '<a class="btn btn-sm btn-outline-primary" href="'
                . self::e(contentUrl('galeria', (string)$gallery['slug']))
                . '">Ver álbum completo</a></div>';
        }

        $html .= '</section>';

        return $html;
    }

PHP;

        if (!str_contains($content, $methodAnchor)) {
            failPatch($file, 'método renderGalleryEmbed');
        }
        $content = str_replace($methodAnchor, $method . $methodAnchor, $content);

        // Nome padrão.
        $content = replaceRequired(
            $content,
            "            'portal_galleries' => 'Galerias',\n",
            "            'portal_gallery' => 'Galeria de fotos',\n            'portal_galleries' => 'Galerias',\n",
            $file,
            'defaultTitle portal_gallery'
        );

        return $content;
    }
);

echo "\n============================================================\n";
echo "Bloco \"Galeria existente\" instalado com sucesso.\n";
echo "============================================================\n";
echo "Backup: {$backupDir}\n\n";
echo "No editor de uma Notícia ou Página:\n";
echo "  Adicionar bloco > Conteúdo dinâmico do Portal > Galeria existente\n\n";
echo "O bloco permite:\n";
echo "  - escolher uma galeria cadastrada;\n";
echo "  - inserir as fotos do álbum dentro do conteúdo;\n";
echo "  - 2, 3 ou 4 colunas;\n";
echo "  - limitar ou mostrar todas as fotos;\n";
echo "  - mostrar/ocultar título, legendas e link do álbum completo.\n\n";
echo "Não é necessária migração SQL.\n";
echo "Limpe o cache do Portal e faça Ctrl+F5 no navegador.\n";

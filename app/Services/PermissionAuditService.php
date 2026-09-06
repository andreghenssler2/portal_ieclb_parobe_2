<?php

declare(strict_types=1);

/**
 * Auditoria de Perfis e Permissões v0.92.0.
 *
 * Apenas leitura: não altera perfis, permissões, usuários nem vínculos.
 */
final class PermissionAuditService
{
    /**
     * @return array<string,mixed>
     */
    public static function report(PDO $pdo, string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        $errors = [];
        $warnings = [];
        $notes = [];

        $requiredTables = [
            'perfis',
            'permissoes',
            'perfil_permissoes',
            'usuarios',
        ];

        $tableStatus = [];

        foreach ($requiredTables as $table) {
            $exists = self::tableExists($pdo, $table);
            $tableStatus[$table] = $exists;

            if (!$exists) {
                $errors[] = 'Tabela obrigatória ausente: ' . $table;
            }
        }

        if ($errors) {
            return [
                'generated_at' => date('Y-m-d H:i:s'),
                'errors' => $errors,
                'warnings' => $warnings,
                'notes' => $notes,
                'tables' => $tableStatus,
                'summary' => [
                    'profiles' => 0,
                    'permissions' => 0,
                    'users' => 0,
                    'active_users' => 0,
                    'routes' => 0,
                    'unguarded_routes' => 0,
                    'unknown_permission_references' => 0,
                ],
                'profiles' => [],
                'permissions' => [],
                'matrix' => [],
                'routes' => [],
                'unknown_permission_references' => [],
                'unused_permissions' => [],
                'orphan_assignments' => [],
                'duplicate_assignments' => [],
            ];
        }

        $profiles =
            $pdo->query(
                'SELECT id, nome, slug
                 FROM perfis
                 ORDER BY id'
            )->fetchAll(PDO::FETCH_ASSOC);

        $permissions =
            $pdo->query(
                'SELECT id, nome, slug, grupo, descricao, ordem
                 FROM permissoes
                 ORDER BY grupo, ordem, id'
            )->fetchAll(PDO::FETCH_ASSOC);

        $users =
            $pdo->query(
                'SELECT
                    u.id,
                    u.nome,
                    u.email,
                    u.ativo,
                    u.perfil_id,
                    p.nome AS perfil_nome,
                    p.slug AS perfil_slug
                 FROM usuarios u
                 LEFT JOIN perfis p ON p.id=u.perfil_id
                 ORDER BY p.nome, u.nome, u.id'
            )->fetchAll(PDO::FETCH_ASSOC);

        $assignments =
            $pdo->query(
                'SELECT
                    pp.perfil_id,
                    pp.permissao_id,
                    p.slug AS permissao_slug
                 FROM perfil_permissoes pp
                 INNER JOIN permissoes p ON p.id=pp.permissao_id
                 ORDER BY pp.perfil_id, p.ordem, p.id'
            )->fetchAll(PDO::FETCH_ASSOC);

        $profileById = [];
        $profileBySlug = [];

        foreach ($profiles as $profile) {
            $profileId = (int)$profile['id'];
            $profileById[$profileId] = $profile;
            $profileBySlug[(string)$profile['slug']] = $profile;
        }

        if (!isset($profileBySlug['administrador'])) {
            $errors[] = 'Perfil obrigatório "administrador" não encontrado.';
        }

        $permissionById = [];
        $permissionBySlug = [];

        foreach ($permissions as $permission) {
            $permissionId = (int)$permission['id'];
            $slug = (string)$permission['slug'];

            if (isset($permissionBySlug[$slug])) {
                $errors[] = 'Slug de permissão duplicada: ' . $slug;
            }

            $permissionById[$permissionId] = $permission;
            $permissionBySlug[$slug] = $permission;
        }

        $assignedByProfile = [];

        foreach ($assignments as $assignment) {
            $profileId = (int)$assignment['perfil_id'];
            $permissionId = (int)$assignment['permissao_id'];

            $assignedByProfile[$profileId][$permissionId] = true;
        }

        $usersByProfile = [];
        $activeUsersByProfile = [];

        foreach ($users as $user) {
            $profileId = (int)($user['perfil_id'] ?? 0);
            $usersByProfile[$profileId] =
                (int)($usersByProfile[$profileId] ?? 0) + 1;

            if ((int)($user['ativo'] ?? 0) === 1) {
                $activeUsersByProfile[$profileId] =
                    (int)($activeUsersByProfile[$profileId] ?? 0) + 1;
            }

            if (empty($user['perfil_slug'])) {
                $errors[] =
                    'Usuário #' . (int)$user['id']
                    . ' referencia perfil inexistente.';
            }
        }

        $matrix = [];

        foreach ($profiles as $profile) {
            $profileId = (int)$profile['id'];
            $isAdmin = (string)$profile['slug'] === 'administrador';

            $profilePermissions = [];

            foreach ($permissions as $permission) {
                $permissionId = (int)$permission['id'];

                if (
                    $isAdmin
                    || !empty($assignedByProfile[$profileId][$permissionId])
                ) {
                    $profilePermissions[] = (string)$permission['slug'];
                }
            }

            $matrix[] = [
                'profile_id' => $profileId,
                'profile_name' => (string)$profile['nome'],
                'profile_slug' => (string)$profile['slug'],
                'administrator' => $isAdmin,
                'permission_count' => count($profilePermissions),
                'permissions' => $profilePermissions,
                'users' => (int)($usersByProfile[$profileId] ?? 0),
                'active_users' => (int)($activeUsersByProfile[$profileId] ?? 0),
            ];
        }

        $orphanAssignments =
            $pdo->query(
                'SELECT
                    pp.perfil_id,
                    pp.permissao_id
                 FROM perfil_permissoes pp
                 LEFT JOIN perfis pf ON pf.id=pp.perfil_id
                 LEFT JOIN permissoes pm ON pm.id=pp.permissao_id
                 WHERE pf.id IS NULL
                    OR pm.id IS NULL
                 ORDER BY pp.perfil_id, pp.permissao_id'
            )->fetchAll(PDO::FETCH_ASSOC);

        if ($orphanAssignments) {
            $errors[] =
                count($orphanAssignments)
                . ' vínculo(s) órfão(s) em perfil_permissoes.';
        }

        $duplicateAssignments =
            $pdo->query(
                'SELECT
                    perfil_id,
                    permissao_id,
                    COUNT(*) AS quantidade
                 FROM perfil_permissoes
                 GROUP BY perfil_id, permissao_id
                 HAVING COUNT(*) > 1
                 ORDER BY perfil_id, permissao_id'
            )->fetchAll(PDO::FETCH_ASSOC);

        if ($duplicateAssignments) {
            $errors[] =
                count($duplicateAssignments)
                . ' vínculo(s) duplicado(s) em perfil_permissoes.';
        }

        $routes =
            self::scanAdminRoutes(
                $root,
                array_keys($permissionBySlug)
            );

        $unknownPermissionReferences =
            $routes['unknown_permission_references'];

        foreach ($unknownPermissionReferences as $item) {
            $errors[] =
                'Permissão usada no código mas inexistente no banco: '
                . $item['permission']
                . ' em '
                . $item['file'];
        }

        $unguardedRoutes =
            array_values(
                array_filter(
                    $routes['routes'],
                    static fn(array $route): bool =>
                        (string)$route['protection'] === 'none'
                )
            );

        if ($unguardedRoutes) {
            $warnings[] =
                count($unguardedRoutes)
                . ' arquivo(s) administrativo(s) sem requireLogin/requirePermission detectável.';
        }

        $referencedPermissions = [];

        foreach ($routes['permission_references'] as $reference) {
            $referencedPermissions[(string)$reference['permission']] = true;
        }

        $unusedPermissions = [];

        foreach ($permissions as $permission) {
            $slug = (string)$permission['slug'];

            if (!isset($referencedPermissions[$slug])) {
                $unusedPermissions[] = $permission;
            }
        }

        if ($unusedPermissions) {
            $warnings[] =
                count($unusedPermissions)
                . ' permissão(ões) cadastrada(s) não foram localizadas nas verificações estáticas do Admin.';
        }

        foreach ($matrix as $profileRow) {
            if (
                !$profileRow['administrator']
                && $profileRow['active_users'] > 0
                && $profileRow['permission_count'] === 0
            ) {
                $warnings[] =
                    'Perfil "'
                    . $profileRow['profile_name']
                    . '" possui usuário(s) ativo(s), mas nenhuma permissão atribuída.';
            }
        }

        $adminUsers = 0;

        foreach ($users as $user) {
            if (
                (string)($user['perfil_slug'] ?? '') === 'administrador'
                && (int)($user['ativo'] ?? 0) === 1
            ) {
                $adminUsers++;
            }
        }

        if ($adminUsers === 0) {
            $warnings[] =
                'Nenhum usuário Administrador ativo foi localizado.';
        }

        $notes[] =
            'O perfil Administrador é tratado como acesso total pelo Auth::isAdmin(), independentemente de vínculos em perfil_permissoes.';

        $notes[] =
            'A auditoria de arquivos é estática; páginas compostas ou incluídas por outras páginas podem aparecer como aviso mesmo estando protegidas pelo fluxo chamador.';

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'notes' => $notes,
            'tables' => $tableStatus,
            'summary' => [
                'profiles' => count($profiles),
                'permissions' => count($permissions),
                'users' => count($users),
                'active_users' =>
                    count(
                        array_filter(
                            $users,
                            static fn(array $user): bool =>
                                (int)($user['ativo'] ?? 0) === 1
                        )
                    ),
                'routes' => count($routes['routes']),
                'unguarded_routes' => count($unguardedRoutes),
                'unknown_permission_references' => count($unknownPermissionReferences),
            ],
            'profiles' => $profiles,
            'permissions' => $permissions,
            'matrix' => $matrix,
            'routes' => $routes['routes'],
            'permission_references' => $routes['permission_references'],
            'unknown_permission_references' => $unknownPermissionReferences,
            'unused_permissions' => $unusedPermissions,
            'orphan_assignments' => $orphanAssignments,
            'duplicate_assignments' => $duplicateAssignments,
        ];
    }

    /**
     * @param string[] $knownPermissions
     * @return array<string,mixed>
     */
    private static function scanAdminRoutes(
        string $root,
        array $knownPermissions
    ): array {
        $adminRoot =
            $root
            . DIRECTORY_SEPARATOR
            . 'admin';

        $routes = [];
        $permissionReferences = [];
        $unknown = [];

        if (!is_dir($adminRoot)) {
            return [
                'routes' => [],
                'permission_references' => [],
                'unknown_permission_references' => [],
            ];
        }

        $knownSet = array_fill_keys($knownPermissions, true);

        $publicEntryPoints = [
            'admin/login.php',
            'admin/logout.php',
            'admin/2fa.php',
            'admin/login-2fa.php',
        ];

        $iterator =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $adminRoot,
                    FilesystemIterator::SKIP_DOTS
                )
            );

        foreach ($iterator as $item) {
            if (
                !$item->isFile()
                || strtolower($item->getExtension()) !== 'php'
            ) {
                continue;
            }

            $file = $item->getPathname();
            $relative = self::relativePath($root, $file);
            $base = basename($relative);

            /* PORTAL_PERMISSION_AUDIT_R4 */
            if (
                str_starts_with($base, '_')
                || str_contains($relative, '/_update_payload')
                || str_contains($relative, '/storage/')
                || str_starts_with($relative, 'admin/app/')
                || preg_match('/^(?:atualizar|corrigir|reverter)_v.*\.php$/i', $base)
            ) {
                continue;
            }

            $content = file_get_contents($file);

            if (!is_string($content)) {
                continue;
            }

            preg_match_all(
                '~Auth::requirePermission\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)~',
                $content,
                $requiredMatches
            );

            preg_match_all(
                '~Auth::can\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)~',
                $content,
                $canMatches
            );

            $requiredPermissions =
                array_values(
                    array_unique(
                        array_map(
                            'strval',
                            $requiredMatches[1] ?? []
                        )
                    )
                );

            $canPermissions =
                array_values(
                    array_unique(
                        array_map(
                            'strval',
                            $canMatches[1] ?? []
                        )
                    )
                );

            foreach (
                array_unique(
                    array_merge(
                        $requiredPermissions,
                        $canPermissions
                    )
                )
                as $permission
            ) {
                $reference = [
                    'file' => $relative,
                    'permission' => $permission,
                    'required' =>
                        in_array(
                            $permission,
                            $requiredPermissions,
                            true
                        ),
                ];

                $permissionReferences[] = $reference;

                if (!isset($knownSet[$permission])) {
                    $unknown[] = $reference;
                }
            }

            $hasRequireLogin =
                str_contains(
                    $content,
                    'Auth::requireLogin('
                );

            $hasAuthCheck =
                str_contains(
                    $content,
                    'Auth::check('
                );

            $isPublic =
                in_array(
                    $relative,
                    $publicEntryPoints,
                    true
                );

            
            /*
             * PORTAL_PUBLIC_DOCUMENT_ROUTES_V101_R2
             *
             * Estes arquivos ficam em admin/ por legado de roteamento, mas
             * são endpoints públicos do módulo Documentos. Eles consultam
             * apenas documentos publicados e renderizam o tema público.
             */
            if (
                in_array(
                    $relative,
                    [
                        'admin/documento-baixar.php',
                        'admin/documento.php',
                        'admin/documentos.php',
                    ],
                    true
                )
            ) {
                $isPublic = true;
            }
$protection =
                (
                    $requiredPermissions
                    || (
                        $hasAuthCheck
                        && $canPermissions
                    )
                )
                    ? 'permission'
                    : (
                        $hasRequireLogin
                        || $hasAuthCheck
                            ? 'login'
                            : (
                                $isPublic
                                    ? 'public'
                                    : 'none'
                            )
                    );

            $routes[] = [
                'file' => $relative,
                'protection' => $protection,
                'required_permissions' => $requiredPermissions,
                'can_permissions' => $canPermissions,
                'has_require_login' => $hasRequireLogin,
                'public_entry' => $isPublic,
            ];
        }

        usort(
            $routes,
            static fn(array $a, array $b): int =>
                strcmp(
                    (string)$a['file'],
                    (string)$b['file']
                )
        );

        return [
            'routes' => $routes,
            'permission_references' => $permissionReferences,
            'unknown_permission_references' => $unknown,
        ];
    }

    private static function tableExists(
        PDO $pdo,
        string $table
    ): bool {
        /* PORTAL_PERMISSION_TABLE_EXISTS_R2
         *
         * Evita SHOW TABLES LIKE ? porque algumas configurações de PDO/MySQL
         * não aceitam placeholder em comandos SHOW quando prepares nativos
         * estão ativos.
         */
        try {
            $stmt =
                $pdo->prepare(
                    'SELECT 1
                     FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                     LIMIT 1'
                );

            $stmt->execute([
                'table_name' => $table,
            ]);

            return (bool)$stmt->fetchColumn();
        } catch (Throwable $ignored) {
            /*
             * Fallback sem placeholder em SHOW.
             * Os nomes usados aqui vêm somente da lista interna do serviço.
             */
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                return false;
            }

            try {
                $quoted =
                    str_replace(
                        '`',
                        '``',
                        $table
                    );

                $stmt =
                    $pdo->query(
                        "SHOW TABLES LIKE "
                        . $pdo->quote($quoted)
                    );

                return
                    $stmt !== false
                    && (bool)$stmt->fetchColumn();
            } catch (Throwable $ignoredAgain) {
                return false;
            }
        }
    }

    private static function relativePath(
        string $root,
        string $path
    ): string {
        $root =
            rtrim(
                str_replace('\\', '/', $root),
                '/'
            )
            . '/';

        $path =
            str_replace(
                '\\',
                '/',
                $path
            );

        if (
            str_starts_with(
                $path,
                $root
            )
        ) {
            return
                substr(
                    $path,
                    strlen($root)
                );
        }

        return $path;
    }
}

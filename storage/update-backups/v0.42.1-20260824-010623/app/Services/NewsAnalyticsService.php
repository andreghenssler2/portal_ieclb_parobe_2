<?php

declare(strict_types=1);

final class NewsAnalyticsService
{
    private const SESSION_WINDOW_SECONDS = 1800;

    public static function trackView(PDO $pdo, int $postId): bool
    {
        if ($postId <= 0) return false;

        $now = time();
        $sessionKey = '_news_view_' . $postId;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $last = (int)($_SESSION[$sessionKey] ?? 0);
            if ($last > 0 && ($now - $last) < self::SESSION_WINDOW_SECONDS) {
                return false;
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE posts SET visualizacoes=COALESCE(visualizacoes,0)+1 WHERE id=?'
            );
            $stmt->execute([$postId]);

            if ($stmt->rowCount() < 1) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO post_visualizacoes_diarias (post_id,data,visualizacoes)
                 VALUES (?,CURDATE(),1)
                 ON DUPLICATE KEY UPDATE visualizacoes=visualizacoes+1'
            );
            $stmt->execute([$postId]);

            $pdo->commit();

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$sessionKey] = $now;
            }
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('NewsAnalyticsService::trackView: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function ranking(PDO $pdo, string $period = 'total', int $limit = 20): array
    {
        $period = self::normalizePeriod($period);
        $limit = max(1, min(100, $limit));

        if ($period === 'total') {
            $sql = "SELECT p.id,p.titulo,p.slug,p.resumo,p.publicado_em,p.created_at,
                           COALESCE(p.visualizacoes,0) visualizacoes_periodo,
                           COALESCE(p.visualizacoes,0) visualizacoes_total,
                           m.caminho imagem_capa_midia,m.alt_text imagem_capa_alt,
                           c.nome comunidade_nome
                    FROM posts p
                    LEFT JOIN midias m ON m.id=p.imagem_capa_id
                    LEFT JOIN comunidades c ON c.id=p.comunidade_id
                    WHERE p.status='publicado'
                      AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())
                    ORDER BY COALESCE(p.visualizacoes,0) DESC,
                             COALESCE(p.publicado_em,p.created_at) DESC,p.id DESC
                    LIMIT " . $limit;
            return $pdo->query($sql)->fetchAll() ?: [];
        }

        $days = $period === '7' ? 7 : 30;

        $sql = "SELECT p.id,p.titulo,p.slug,p.resumo,p.publicado_em,p.created_at,
                       COALESCE(v.visualizacoes_periodo,0) visualizacoes_periodo,
                       COALESCE(p.visualizacoes,0) visualizacoes_total,
                       m.caminho imagem_capa_midia,m.alt_text imagem_capa_alt,
                       c.nome comunidade_nome
                FROM posts p
                INNER JOIN (
                    SELECT post_id,SUM(visualizacoes) visualizacoes_periodo
                    FROM post_visualizacoes_diarias
                    WHERE data>=DATE_SUB(CURDATE(),INTERVAL " . ($days - 1) . " DAY)
                    GROUP BY post_id
                ) v ON v.post_id=p.id
                LEFT JOIN midias m ON m.id=p.imagem_capa_id
                LEFT JOIN comunidades c ON c.id=p.comunidade_id
                WHERE p.status='publicado'
                  AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())
                ORDER BY v.visualizacoes_periodo DESC,
                         COALESCE(p.publicado_em,p.created_at) DESC,p.id DESC
                LIMIT " . $limit;

        return $pdo->query($sql)->fetchAll() ?: [];
    }

    /** @return array{hoje:int,sete_dias:int,trinta_dias:int,total:int} */
    public static function summary(PDO $pdo): array
    {
        $today = (int)$pdo->query(
            'SELECT COALESCE(SUM(visualizacoes),0)
             FROM post_visualizacoes_diarias WHERE data=CURDATE()'
        )->fetchColumn();

        $seven = (int)$pdo->query(
            'SELECT COALESCE(SUM(visualizacoes),0)
             FROM post_visualizacoes_diarias
             WHERE data>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)'
        )->fetchColumn();

        $thirty = (int)$pdo->query(
            'SELECT COALESCE(SUM(visualizacoes),0)
             FROM post_visualizacoes_diarias
             WHERE data>=DATE_SUB(CURDATE(),INTERVAL 29 DAY)'
        )->fetchColumn();

        $total = (int)$pdo->query(
            "SELECT COALESCE(SUM(visualizacoes),0)
             FROM posts WHERE status='publicado'"
        )->fetchColumn();

        return [
            'hoje'=>$today,
            'sete_dias'=>$seven,
            'trinta_dias'=>$thirty,
            'total'=>$total,
        ];
    }

    /** @return array<int,array{data:string,visualizacoes:int}> */
    public static function daily(PDO $pdo, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $pdo->query(
            "SELECT data,SUM(visualizacoes) visualizacoes
             FROM post_visualizacoes_diarias
             WHERE data>=DATE_SUB(CURDATE(),INTERVAL " . ($days - 1) . " DAY)
             GROUP BY data ORDER BY data DESC"
        )->fetchAll() ?: [];
    }

    public static function normalizePeriod(string $period): string
    {
        $period = strtolower(trim($period));
        return in_array($period, ['7','30','total'], true) ? $period : 'total';
    }

    public static function periodLabel(string $period): string
    {
        return match (self::normalizePeriod($period)) {
            '7' => 'Últimos 7 dias',
            '30' => 'Últimos 30 dias',
            default => 'Todo o período',
        };
    }
}

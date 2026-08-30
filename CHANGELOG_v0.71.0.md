# Portal IECLB Parobé v0.71.0

## Uso seguro da Biblioteca de Mídia

- Novo `MediaUsageService`.
- Tela de detalhes da mídia reformulada.
- Exibe onde a mídia está sendo usada.
- Notícias, páginas, eventos/cultos e documentos são reconhecidos.
- FOREIGN KEYs adicionais para `midias.id` também são detectadas.
- Verificação de existência do arquivo físico.
- Alerta quando tamanho físico diverge do banco.
- Exclusão bloqueada quando a mídia está em uso.
- Proteção centralizada em `MediaService::delete()`.
- Nenhuma alteração no banco de dados.

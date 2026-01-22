-- ============================================================
-- SCRIPT DE OTIMIZAÇÃO DE PERFORMANCE - HubPorn
-- ============================================================
-- Este script adiciona índices para melhorar a performance das queries.
-- Execute diretamente no banco de dados do conteúdo (mysql_content).
-- 
-- IMPORTANTE: Este script é SEGURO e NÃO modifica dados existentes.
--             Apenas adiciona índices para melhorar performance.
-- ============================================================

-- Verificar se os índices já existem antes de criar (evita erros)

-- ============================================================
-- ÍNDICES PARA TABELA: albums
-- ============================================================

-- Índice para listagem de álbuns públicos ordenados por data
-- Usado na página inicial (Home)
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'albums' AND index_name = 'idx_albums_public_created');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_albums_public_created already exists''', 
    'CREATE INDEX idx_albums_public_created ON albums(is_public, created_at DESC)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice para busca por user_id (usado no Dashboard do usuário)
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'albums' AND index_name = 'idx_albums_user_id');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_albums_user_id already exists''', 
    'CREATE INDEX idx_albums_user_id ON albums(user_id)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice para busca por album_id (usado na página do álbum)
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'albums' AND index_name = 'idx_albums_album_id');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_albums_album_id already exists''', 
    'CREATE INDEX idx_albums_album_id ON albums(album_id)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice FULLTEXT para busca (título, tags, autor)
-- Nota: Este índice pode demorar um pouco em tabelas grandes
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'albums' AND index_name = 'idx_albums_search');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_albums_search already exists''', 
    'CREATE FULLTEXT INDEX idx_albums_search ON albums(title, tags, author)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- ÍNDICES PARA TABELA: media_items
-- ============================================================

-- Índice composto para busca de mídia por álbum, status e tipo
-- Usado ao carregar fotos/vídeos de um álbum
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'media_items' AND index_name = 'idx_media_album_status_type');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_media_album_status_type already exists''', 
    'CREATE INDEX idx_media_album_status_type ON media_items(album_id, status, media_type)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice para status (filtragem geral)
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'media_items' AND index_name = 'idx_media_status');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_media_status already exists''', 
    'CREATE INDEX idx_media_status ON media_items(status)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- ANÁLISE DE TABELAS (Atualiza estatísticas para o otimizador)
-- ============================================================

ANALYZE TABLE albums;
ANALYZE TABLE media_items;

-- ============================================================
-- VERIFICAÇÃO FINAL
-- ============================================================

-- Lista todos os índices criados nas tabelas
SELECT 
    TABLE_NAME, 
    INDEX_NAME, 
    COLUMN_NAME,
    SEQ_IN_INDEX,
    INDEX_TYPE
FROM information_schema.statistics 
WHERE table_schema = DATABASE() 
  AND table_name IN ('albums', 'media_items')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- ============================================================
-- FIM DO SCRIPT
-- ============================================================

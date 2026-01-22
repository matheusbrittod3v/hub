<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

/**
 * ============================================================================
 * 🔗 SLUG SERVICE - GERAÇÃO PERFORMÁTICA DE URLS SEO-FRIENDLY
 * ============================================================================
 * 
 * Service centralizado para gerar, validar e resolver slugs.
 * Usa cache para evitar recalcular slugs frequentemente.
 */
class SlugService
{
    /**
     * Gera slug SEO-friendly a partir do título e tags
     * 
     * @param string|null $title Título do conteúdo
     * @param string|null $tags Tags separadas por vírgula
     * @param int $maxLength Tamanho máximo do slug
     * @return string Slug gerado
     */
    public static function generateFromTitle(?string $title, ?string $tags = null, int $maxLength = 60): string
    {
        // Cache key baseado no título e tags
        $cacheKey = 'slug_' . md5(($title ?? '') . ($tags ?? ''));
        
        return Cache::remember($cacheKey, 3600, function () use ($title, $tags, $maxLength) {
            return self::buildSlug($title, $tags, $maxLength);
        });
    }

    /**
     * Gera slug sem cache (para uso em migrations/commands)
     */
    public static function generateWithoutCache(?string $title, ?string $tags = null, int $maxLength = 60): string
    {
        return self::buildSlug($title, $tags, $maxLength);
    }

    /**
     * Lógica principal de construção do slug
     */
    private static function buildSlug(?string $title, ?string $tags = null, int $maxLength = 60): string
    {
        if (empty($title)) {
            return '';
        }

        // Combinar título e principais tags para mais keywords SEO
        $text = $title;
        
        if (!empty($tags)) {
            // Adicionar primeiras 3 tags ao slug para melhor SEO
            $tagArray = array_slice(
                array_map('trim', explode(',', $tags)),
                0,
                3
            );
            $text .= ' ' . implode(' ', $tagArray);
        }

        // Normalizar slug
        $slug = self::normalizeSlug($text);
        
        // Limitar tamanho (Google recomenda até 60 caracteres)
        if (strlen($slug) > $maxLength) {
            // Cortar em um hífen para não quebrar palavras
            $slug = substr($slug, 0, $maxLength);
            $lastHyphen = strrpos($slug, '-');
            if ($lastHyphen !== false && $lastHyphen > ($maxLength / 2)) {
                $slug = substr($slug, 0, $lastHyphen);
            }
        }

        return trim($slug, '-');
    }

    /**
     * Normaliza texto para slug (remove acentos, caracteres especiais, etc)
     */
    private static function normalizeSlug(string $text): string
    {
        // Mapeamento de acentos para ASCII
        $acentos = [
            'á'=>'a', 'à'=>'a', 'ã'=>'a', 'â'=>'a', 'ä'=>'a',
            'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
            'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
            'ó'=>'o', 'ò'=>'o', 'õ'=>'o', 'ô'=>'o', 'ö'=>'o',
            'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
            'ç'=>'c', 'ñ'=>'n',
            'Á'=>'a', 'À'=>'a', 'Ã'=>'a', 'Â'=>'a', 'Ä'=>'a',
            'É'=>'e', 'È'=>'e', 'Ê'=>'e', 'Ë'=>'e',
            'Í'=>'i', 'Ì'=>'i', 'Î'=>'i', 'Ï'=>'i',
            'Ó'=>'o', 'Ò'=>'o', 'Õ'=>'o', 'Ô'=>'o', 'Ö'=>'o',
            'Ú'=>'u', 'Ù'=>'u', 'Û'=>'u', 'Ü'=>'u',
            'Ç'=>'c', 'Ñ'=>'n'
        ];
        
        // Remover acentos
        $text = strtr($text, $acentos);
        
        // Converter para minúsculas
        $text = strtolower($text);
        
        // Remover caracteres especiais (manter apenas letras, números e espaços)
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        
        // Substituir espaços múltiplos por um único espaço
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Substituir espaços por hífens
        $text = str_replace(' ', '-', $text);
        
        // Remover hífens duplicados
        $text = preg_replace('/-+/', '-', $text);
        
        return $text;
    }

    /**
     * Valida se um slug corresponde a um título
     * (Usado para garantir que a URL está correta)
     * 
     * @param string $slug Slug da URL
     * @param string $title Título do conteúdo
     * @return bool True se válido
     */
    public static function isValid(string $slug, string $title): bool
    {
        $expectedSlug = self::generateFromTitle($title);
        return $slug === $expectedSlug;
    }

    /**
     * Gera URL completa para um álbum
     * 
     * @param string $albumId ID do álbum
     * @param string|null $title Título do álbum
     * @param string|null $tags Tags do álbum
     * @return string URL completa
     */
    public static function generateAlbumUrl(string $albumId, ?string $title = null, ?string $tags = null): string
    {
        $url = "/album/{$albumId}";
        
        if ($title) {
            $slug = self::generateFromTitle($title, $tags);
            if ($slug) {
                $url .= "/{$slug}";
            }
        }
        
        return $url;
    }

    /**
     * Gera URL completa para um perfil
     * 
     * @param int|string $userId ID do usuário
     * @param string|null $username Nome do usuário
     * @return string URL completa
     */
    public static function generateProfileUrl($userId, ?string $username = null): string
    {
        $url = "/profile/{$userId}";
        
        if ($username) {
            $slug = self::generateFromTitle($username);
            if ($slug) {
                $url .= "/{$slug}";
            }
        }
        
        return $url;
    }

    /**
     * Limpa cache de slugs (útil após atualizações em massa)
     */
    public static function clearCache(): void
    {
        // O Laravel não tem um jeito fácil de limpar apenas slugs pelo prefixo
        // então vamos documentar que o cache expira automaticamente em 1h
        // Para limpar tudo: Cache::flush()
    }
}

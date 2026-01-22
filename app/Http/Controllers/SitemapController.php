<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * 🗺️ SITEMAP CONTROLLER - ULTRA PERFORMÁTICO (v2)
 * ============================================================================
 *
 * Gera sitemaps dinâmicos otimizados para SEO usando banco de leitura.
 * URLs amigáveis com keywords do título para melhor indexação.
 *
 * @see https://www.sitemaps.org/protocol.html
 */
class SitemapController extends Controller
{
    private const CACHE_TTL = 3600;               // 1 hora

    private const ALBUMS_PER_SITEMAP = 10000;     // URLs por sitemap

    private const PROFILES_PER_SITEMAP = 5000;

    private string $baseUrl;

    private string $dbConnection = 'mysql_content'; // Usar banco de leitura

    public function __construct()
    {
        // ⭐ Hardcoded para garantir que nunca use localhost em produção
        $this->baseUrl = 'https://hubporn.online';
    }

    // ========================================================================
    // SITEMAP INDEX
    // ========================================================================

    public function index(): Response
    {
        $cacheKey = 'sitemap_index_v2';

        $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return $this->generateSitemapIndex();
        });

        return $this->xmlResponse($xml);
    }

    private function generateSitemapIndex(): string
    {
        $sitemaps = [];
        $now = now()->toW3cString();

        // 1. Sitemap de páginas estáticas
        $sitemaps[] = [
            'loc' => $this->baseUrl.'/sitemap-static.xml',
            'lastmod' => $now,
        ];

        // 2. Sitemaps de álbuns (paginados)
        $albumCount = $this->getAlbumCount();
        $albumSitemaps = max(1, (int) ceil($albumCount / self::ALBUMS_PER_SITEMAP));

        for ($i = 1; $i <= min($albumSitemaps, 500); $i++) { // Max 500 sitemaps
            $sitemaps[] = [
                'loc' => $this->baseUrl."/sitemap-albums-{$i}.xml",
                'lastmod' => $now,
            ];
        }

        // 3. Sitemaps de perfis (baseado em autores únicos nos álbuns)
        $profileCount = $this->getProfileCount();
        if ($profileCount > 0) {
            $profileSitemaps = max(1, (int) ceil($profileCount / self::PROFILES_PER_SITEMAP));

            for ($i = 1; $i <= min($profileSitemaps, 100); $i++) {
                $sitemaps[] = [
                    'loc' => $this->baseUrl."/sitemap-profiles-{$i}.xml",
                    'lastmod' => $now,
                ];
            }
        }

        // Gerar XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.PHP_EOL;

        foreach ($sitemaps as $sitemap) {
            $xml .= '  <sitemap>'.PHP_EOL;
            $xml .= '    <loc>'.htmlspecialchars($sitemap['loc']).'</loc>'.PHP_EOL;
            $xml .= '    <lastmod>'.$sitemap['lastmod'].'</lastmod>'.PHP_EOL;
            $xml .= '  </sitemap>'.PHP_EOL;
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    // ========================================================================
    // SITEMAP DE PÁGINAS ESTÁTICAS
    // ========================================================================

    public function staticPages(): Response
    {
        $cacheKey = 'sitemap_static_v2';

        $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $pages = [
                ['url' => '/', 'priority' => '1.0', 'changefreq' => 'hourly'],
                ['url' => '/login', 'priority' => '0.3', 'changefreq' => 'monthly'],
                ['url' => '/register', 'priority' => '0.3', 'changefreq' => 'monthly'],
            ];

            return $this->buildUrlSet($pages);
        });

        return $this->xmlResponse($xml);
    }

    // ========================================================================
    // SITEMAP DE ÁLBUNS (URLs SEO-friendly com keywords)
    // ========================================================================

    public function albums(int $page = 1): Response
    {
        $page = max(1, min($page, 500)); // Max 500 páginas
        $cacheKey = "sitemap_albums_v2_{$page}";

        $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($page) {
            return $this->generateAlbumsSitemap($page);
        });

        return $this->xmlResponse($xml);
    }

    private function generateAlbumsSitemap(int $page): string
    {
        $offset = ($page - 1) * self::ALBUMS_PER_SITEMAP;

        // Query do banco de LEITURA (mysql_content)
        // ⭐ Agora selecionamos o slug diretamente do banco
        $albums = DB::connection($this->dbConnection)
            ->table('albums')
            ->useWritePdo()
            ->select(['album_id', 'title', 'slug', 'tags', 'updated_at', 'created_at', 'views'])
            ->where('is_public', 1)
            ->orderBy('id', 'asc')
            ->offset($offset)
            ->limit(self::ALBUMS_PER_SITEMAP)
            ->get();

        $urls = [];

        foreach ($albums as $album) {
            // ⭐ Usar slug do banco ou gerar se não existir (fallback)
            $slug = $album->slug;

            // Fallback: gerar slug dinamicamente se não existir no banco
            if (empty($slug) && ! empty($album->title)) {
                $slug = $this->generateSeoSlug($album->title, $album->tags);
            }

            // URL amigável: /album/{id}/{slug-com-keywords}
            // Ex: /album/abc123/video-porno-brasileiro-amador
            $url = "/album/{$album->album_id}";
            if ($slug) {
                $url .= "/{$slug}";
            }

            // Prioridade baseada em views
            $priority = $this->calculatePriority($album->views ?? 0);

            // Data de modificação
            $lastmod = $album->updated_at ?? $album->created_at;

            $urls[] = [
                'url' => $url,
                'lastmod' => $lastmod ? date('Y-m-d', strtotime($lastmod)) : date('Y-m-d'),
                'priority' => $priority,
                'changefreq' => 'weekly',
            ];
        }

        return $this->buildUrlSet($urls);
    }

    // ========================================================================
    // SITEMAP DE PERFIS (Autores dos álbuns)
    // ========================================================================

    public function profiles(int $page = 1): Response
    {
        $page = max(1, min($page, 100));
        $cacheKey = "sitemap_profiles_v2_{$page}";

        $xml = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($page) {
            return $this->generateProfilesSitemap($page);
        });

        return $this->xmlResponse($xml);
    }

    private function generateProfilesSitemap(int $page): string
    {
        $offset = ($page - 1) * self::PROFILES_PER_SITEMAP;

        // Buscar autores/user_ids únicos do BANCO DE LEITURA
        // Sem cross-database query!
        $profiles = DB::connection($this->dbConnection)
            ->table('albums')
            ->select([
                'user_id',
                'author',
                DB::raw('MAX(updated_at) as last_updated'),
                DB::raw('SUM(views) as total_views'),
                DB::raw('COUNT(*) as album_count'),
            ])
            ->where('is_public', 1)
            ->where(function ($q) {
                $q->whereNotNull('user_id')
                    ->orWhereNotNull('author');
            })
            ->groupBy('user_id', 'author')
            ->orderByDesc('total_views')
            ->offset($offset)
            ->limit(self::PROFILES_PER_SITEMAP)
            ->get();

        $urls = [];

        foreach ($profiles as $profile) {
            // Gerar URL do perfil
            if ($profile->user_id) {
                // Perfil de usuário registrado
                $username = $this->generateSlug($profile->author ?: 'user');
                $url = "/profile/{$profile->user_id}/{$username}";
            } else {
                // Autor sem conta (scraping)
                $authorSlug = $this->generateSlug($profile->author ?: 'anonymous');
                $url = "/author/{$authorSlug}";
            }

            // Prioridade baseada em views totais
            $priority = $this->calculatePriority($profile->total_views ?? 0);

            $urls[] = [
                'url' => $url,
                'lastmod' => $profile->last_updated ? date('Y-m-d', strtotime($profile->last_updated)) : date('Y-m-d'),
                'priority' => $priority,
                'changefreq' => 'weekly',
            ];
        }

        return $this->buildUrlSet($urls);
    }

    // ========================================================================
    // HELPERS - GERAÇÃO DE SLUGS SEO
    // ========================================================================

    /**
     * Gera slug otimizado para SEO com keywords do título e tags
     */
    private function generateSeoSlug(?string $title, ?string $tags = null): string
    {
        // Combinar título e principais tags para mais keywords
        $text = $title ?: '';

        if ($tags) {
            // Adicionar primeiras 3 tags ao slug
            $tagArray = array_slice(explode(',', $tags), 0, 3);
            $text .= ' '.implode(' ', array_map('trim', $tagArray));
        }

        return $this->generateSlug($text);
    }

    /**
     * Gera slug básico
     */
    private function generateSlug(?string $text): string
    {
        if (! $text) {
            return '';
        }

        // Mapeamento de acentos para letras sem acento
        $acentos = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a', 'Ä' => 'a',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
            'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'Ç' => 'c', 'Ñ' => 'n',
        ];

        $text = strtr($text, $acentos);

        // Converter para minúsculas
        $text = strtolower($text);

        // Remover caracteres especiais (manter apenas letras, números e espaços)
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);

        // Substituir espaços por hífens
        $text = preg_replace('/[\s_]+/', '-', $text);

        // Remover hífens duplicados
        $text = preg_replace('/-+/', '-', $text);

        // Limitar tamanho (SEO recomenda até 60 caracteres)
        $text = substr($text, 0, 60);

        // Remover hífens no início/fim
        return trim($text, '-');
    }

    /**
     * Calcular prioridade baseada em popularidade
     */
    private function calculatePriority(int $views): string
    {
        if ($views >= 100000) {
            return '1.0';
        }
        if ($views >= 50000) {
            return '0.9';
        }
        if ($views >= 10000) {
            return '0.8';
        }
        if ($views >= 1000) {
            return '0.7';
        }
        if ($views >= 100) {
            return '0.6';
        }

        return '0.5';
    }

    // ========================================================================
    // CONTAGENS (Cache de 5 minutos)
    // ========================================================================

    private function getAlbumCount(): int
    {
        return Cache::remember('sitemap_album_count_v2', 300, function () {
            return (int) DB::connection($this->dbConnection)
                ->table('albums')
                ->where('is_public', 1)
                ->count();
        });
    }

    private function getProfileCount(): int
    {
        return Cache::remember('sitemap_profile_count_v2', 300, function () {
            // Contar autores/usuários únicos
            return (int) DB::connection($this->dbConnection)
                ->table('albums')
                ->where('is_public', 1)
                ->where(function ($q) {
                    $q->whereNotNull('user_id')
                        ->orWhereNotNull('author');
                })
                ->selectRaw('COUNT(DISTINCT COALESCE(user_id, author)) as cnt')
                ->value('cnt');
        });
    }

    // ========================================================================
    // BUILD XML
    // ========================================================================

    private function buildUrlSet(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'.PHP_EOL;

        foreach ($urls as $item) {
            $xml .= '  <url>'.PHP_EOL;
            $xml .= '    <loc>'.htmlspecialchars($this->baseUrl.$item['url']).'</loc>'.PHP_EOL;

            if (! empty($item['lastmod'])) {
                $xml .= '    <lastmod>'.$item['lastmod'].'</lastmod>'.PHP_EOL;
            }

            if (! empty($item['changefreq'])) {
                $xml .= '    <changefreq>'.$item['changefreq'].'</changefreq>'.PHP_EOL;
            }

            if (! empty($item['priority'])) {
                $xml .= '    <priority>'.$item['priority'].'</priority>'.PHP_EOL;
            }

            $xml .= '  </url>'.PHP_EOL;
        }

        $xml .= '</urlset>';

        return $xml;
    }

    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    // ========================================================================
    // LIMPAR CACHE (Via Rota)
    // ========================================================================

    /**
     * Limpa todo o cache relacionado ao sitemap.
     */
    public function forceClearCache(): Response
    {
        self::clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Cache do sitemap limpo com sucesso!',
            'base_url' => $this->baseUrl,
        ]);
    }

    public static function clearCache(): void
    {
        $keys = [
            'sitemap_index_v2',
            'sitemap_static_v2',
            'sitemap_album_count_v2',
            'sitemap_profile_count_v2',
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        // Limpar sitemaps paginados
        for ($i = 1; $i <= 500; $i++) {
            Cache::forget("sitemap_albums_v2_{$i}");
        }
        for ($i = 1; $i <= 100; $i++) {
            Cache::forget("sitemap_profiles_v2_{$i}");
        }
    }
}

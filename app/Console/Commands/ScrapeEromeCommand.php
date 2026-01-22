<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ============================================================================
 * 🕷️ SCRAPER COMMAND - Erome Scraper em Laravel
 * ============================================================================
 * 
 * Comando Artisan para fazer scraping do erome.com
 * 
 * Features:
 * - Lock para evitar múltiplas instâncias
 * - Scraping de fotos e vídeos
 * - Upload automático para R2
 * - Salvamento no banco de dados
 * - Rate limiting para evitar bloqueio
 * 
 * Uso:
 *   php artisan scrape:erome                    # Scrape páginas 1-5
 *   php artisan scrape:erome --pages=10         # Scrape páginas 1-10
 *   php artisan scrape:erome --start=5 --end=20 # Scrape páginas 5-20
 *   php artisan scrape:erome --category=straight
 *   php artisan scrape:erome --no-download      # Apenas metadados
 */
class ScrapeEromeCommand extends Command
{
    protected $signature = 'scrape:erome 
                            {--start=1 : Página inicial}
                            {--end=5 : Página final}
                            {--pages= : Número de páginas (atalho para end)}
                            {--category=all : Categoria (all, straight, trans, gay, hentai)}
                            {--no-download : Não baixar mídias, apenas metadados}
                            {--force : Forçar execução mesmo se outra instância estiver rodando}';
    
    protected $description = '🕷️ Scraper de álbuns do Erome.com';
    
    // Configurações
    private const LOCK_KEY = 'scraper_erome_running';
    private const LOCK_TTL = 7200; // 2 horas
    private const BASE_URL = 'https://www.erome.com';
    private const EXPLORE_URL = 'https://www.erome.com/explore';
    private const REQUEST_DELAY_MIN = 1.0;
    private const REQUEST_DELAY_MAX = 3.0;
    private const MAX_RETRIES = 3;
    private const TIMEOUT = 30;
    
    private const CATEGORIES = [
        'all' => '/explore',
        'straight' => '/explore/straight',
        'trans' => '/explore/trans',
        'gay' => '/explore/gay',
        'hentai' => '/explore/hentai',
    ];
    
    private const BLOCKED_PATTERNS = [
        'googleads', 'doubleclick', 'facebook.net', 'analytics',
        'tracking', 'advertisement', 'sponsor', 'banner', 'popup',
    ];
    
    private array $visitedAlbums = [];
    private array $stats = [
        'albums' => 0,
        'photos' => 0,
        'videos' => 0,
        'downloaded' => 0,
        'errors' => 0,
    ];
    
    private string $r2Url;
    
    public function handle(): int
    {
        // Verificar lock
        if (!$this->acquireLock()) {
            return Command::FAILURE;
        }
        
        try {
            $this->r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
            
            $startPage = (int)$this->option('start');
            $endPage = (int)($this->option('pages') ?: $this->option('end'));
            $category = $this->option('category');
            $download = !$this->option('no-download');
            
            if ($this->option('pages')) {
                $endPage = $startPage + (int)$this->option('pages') - 1;
            }
            
            $this->info("🕷️ Iniciando Erome Scraper");
            $this->info("   Páginas: {$startPage} - {$endPage}");
            $this->info("   Categoria: {$category}");
            $this->info("   Download: " . ($download ? 'Sim' : 'Não'));
            $this->newLine();
            
            $this->runScraping($startPage, $endPage, $category, $download);
            
            $this->displayStats();
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("❌ Erro: " . $e->getMessage());
            return Command::FAILURE;
            
        } finally {
            $this->releaseLock();
        }
    }
    
    // ========================================================================
    // LOCK MANAGEMENT
    // ========================================================================
    
    private function acquireLock(): bool
    {
        if ($this->option('force')) {
            $this->warn("⚠️ Modo forçado - ignorando lock");
            Cache::forget(self::LOCK_KEY);
        }
        
        $lockInfo = Cache::get(self::LOCK_KEY);
        
        if ($lockInfo) {
            $startedAt = $lockInfo['started_at'] ?? 'desconhecido';
            $pid = $lockInfo['pid'] ?? 'N/A';
            
            $this->error("❌ Outra instância já está rodando!");
            $this->error("   Iniciada em: {$startedAt}");
            $this->error("   PID: {$pid}");
            $this->newLine();
            $this->info("Use --force para forçar execução");
            
            return false;
        }
        
        // Adquirir lock
        Cache::put(self::LOCK_KEY, [
            'started_at' => now()->toDateTimeString(),
            'pid' => getmypid(),
        ], self::LOCK_TTL);
        
        $this->info("🔒 Lock adquirido");
        
        return true;
    }
    
    private function releaseLock(): void
    {
        Cache::forget(self::LOCK_KEY);
        $this->info("🔓 Lock liberado");
    }
    
    // ========================================================================
    // SCRAPING
    // ========================================================================
    
    private function runScraping(int $startPage, int $endPage, string $category, bool $download): void
    {
        $progressBar = $this->output->createProgressBar($endPage - $startPage + 1);
        $progressBar->start();
        
        for ($page = $startPage; $page <= $endPage; $page++) {
            $this->info("\n📄 Página {$page}...");
            
            $albums = $this->scrapeExplorePage($page, $category);
            
            foreach ($albums as $albumInfo) {
                // Verificar se já existe
                if ($this->albumExists($albumInfo['album_id'])) {
                    $this->line("   ⏭️ Álbum já existe: {$albumInfo['album_id']}");
                    continue;
                }
                
                // Scrape do álbum
                $albumData = $this->scrapeAlbum($albumInfo['url']);
                
                if ($albumData) {
                    // Salvar no banco
                    $savedAlbum = $this->saveAlbumToDb($albumData);
                    
                    if ($savedAlbum && $download) {
                        $this->downloadAlbumMedia($savedAlbum, $albumData);
                    }
                    
                    $this->stats['albums']++;
                }
                
                // Rate limiting
                $this->randomDelay();
            }
            
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);
    }
    
    private function scrapeExplorePage(int $page, string $category): array
    {
        $url = self::BASE_URL . (self::CATEGORIES[$category] ?? '/explore') . "?page={$page}";
        
        $html = $this->fetchPage($url);
        if (!$html) {
            return [];
        }
        
        $albums = [];
        
        // Parse com regex (mais leve que DOM completo)
        preg_match_all('/<a[^>]*href=["\']([^"\']*\/a\/([a-zA-Z0-9]+)[^"\']*)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $href = $match[1];
            $albumId = $match[2];
            
            if ($this->isBlockedUrl($href) || isset($this->visitedAlbums[$albumId])) {
                continue;
            }
            
            $this->visitedAlbums[$albumId] = true;
            
            $fullUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
            
            $albums[] = [
                'album_id' => $albumId,
                'url' => $fullUrl,
                'category' => $category,
            ];
        }
        
        $this->line("   Encontrados " . count($albums) . " álbuns");
        
        return $albums;
    }
    
    private function scrapeAlbum(string $url): ?array
    {
        preg_match('/\/a\/([a-zA-Z0-9]+)/', $url, $match);
        $albumId = $match[1] ?? null;
        
        if (!$albumId) {
            return null;
        }
        
        $html = $this->fetchPage($url);
        if (!$html) {
            return null;
        }
        
        // Extrair título
        preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $titleMatch);
        $title = html_entity_decode(trim($titleMatch[1] ?? "Album {$albumId}"));
        $title = str_replace(' - Porn - EroMe', '', $title);
        
        // Extrair autor
        preg_match('/<a[^>]*class=["\'][^"\']*user-name[^"\']*["\'][^>]*>([^<]+)<\/a>/i', $html, $authorMatch);
        $author = trim($authorMatch[1] ?? '');
        
        // Extrair tags
        preg_match_all('/<a[^>]*href=["\'][^"\']*\/search\?q=([^"\'&]+)[^"\']*["\'][^>]*>/i', $html, $tagMatches);
        $tags = array_map('urldecode', $tagMatches[1] ?? []);
        
        // Extrair fotos e vídeos
        $photos = [];
        $videos = [];
        
        // Buscar vídeos
        preg_match_all('/<source[^>]*src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $html, $videoMatches);
        foreach ($videoMatches[1] as $vidUrl) {
            if ($this->isValidMediaUrl($vidUrl, 'video')) {
                $videos[] = $vidUrl;
            }
        }
        
        // Buscar fotos (ignorar se dentro de video-container)
        preg_match_all('/<div[^>]*class=["\'][^"\']*media-group[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $groups);
        
        foreach ($groups[1] ?? [] as $group) {
            // Se tem vídeo, ignorar imagens deste grupo
            if (preg_match('/<video|video-js/i', $group)) {
                continue;
            }
            
            // Buscar img.img-front
            if (preg_match('/<img[^>]*class=["\'][^"\']*img-front[^"\']*["\'][^>]*(data-src|src)=["\']([^"\']+)["\'][^>]*>/i', $group, $imgMatch)) {
                $imgUrl = $imgMatch[2];
                if ($this->isValidMediaUrl($imgUrl, 'photo')) {
                    $photos[] = $imgUrl;
                }
            }
        }
        
        // Fallback: buscar todas as imagens do CDN se nenhuma encontrada
        if (empty($photos)) {
            preg_match_all('/data-src=["\']([^"\']+\.(?:jpg|jpeg|png|webp)[^"\']*)["\']|src=["\']([^"\']+\.(?:jpg|jpeg|png|webp)[^"\']*)["\']/', $html, $allImgs);
            $allImgUrls = array_filter(array_merge($allImgs[1], $allImgs[2]));
            
            foreach ($allImgUrls as $imgUrl) {
                if ($this->isValidMediaUrl($imgUrl, 'photo') && !str_contains($imgUrl, 'poster')) {
                    $photos[] = $imgUrl;
                }
            }
        }
        
        $photos = array_unique($photos);
        $videos = array_unique($videos);
        
        $this->line("   🎬 {$albumId}: 📷 " . count($photos) . " fotos, 🎥 " . count($videos) . " vídeos");
        
        return [
            'album_id' => $albumId,
            'url' => $url,
            'title' => $title,
            'author' => $author,
            'tags' => implode(',', $tags),
            'photos' => array_values($photos),
            'videos' => array_values($videos),
            'photo_count' => count($photos),
            'video_count' => count($videos),
        ];
    }
    
    // ========================================================================
    // DATABASE
    // ========================================================================
    
    private function albumExists(string $albumId): bool
    {
        return DB::connection('mysql_content')
            ->table('albums')
            ->where('album_id', $albumId)
            ->exists();
    }
    
    private function saveAlbumToDb(array $albumData): ?object
    {
        try {
            $now = now();
            
            // Usar apenas colunas que existem na tabela
            $id = DB::connection('mysql_content')->table('albums')->insertGetId([
                'album_id' => $albumData['album_id'],
                'title' => Str::limit($albumData['title'], 255),
                'description' => null,
                'author' => Str::limit($albumData['author'], 100) ?: null,
                'author_url' => $albumData['author'] ? self::BASE_URL . '/' . $albumData['author'] : null,
                'tags' => Str::limit($albumData['tags'], 500) ?: null,
                'url' => $albumData['url'], // Coluna correta é 'url', não 'source_url'
                'photo_count' => $albumData['photo_count'],
                'video_count' => $albumData['video_count'],
                'views' => 0,
                'likes' => 0,
                'is_public' => true,
                'is_processed' => true,
                'scraped_at' => $now,
                'source' => 'erome',
                'user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            
            return (object)['id' => $id, 'album_id' => $albumData['album_id']];
            
        } catch (\Exception $e) {
            $this->error("   ❌ Erro ao salvar álbum: " . $e->getMessage());
            $this->stats['errors']++;
            return null;
        }
    }
    
    private function saveMediaItem(int $albumId, string $url, string $type, ?string $s3Path = null, ?string $thumbPath = null): void
    {
        try {
            DB::connection('mysql_content')->table('media_items')->insert([
                'album_id' => $albumId,
                'media_type' => $type,
                'media_url' => $url,
                's3_path' => $s3Path,
                'thumbnail_url' => $thumbPath,
                'status' => $s3Path ? 'completed' : 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Ignorar duplicados
        }
    }
    
    // ========================================================================
    // DOWNLOAD & UPLOAD
    // ========================================================================
    
    private function downloadAlbumMedia(object $album, array $albumData): void
    {
        $this->line("   📥 Baixando mídias...");
        
        // Processar fotos
        foreach ($albumData['photos'] as $index => $photoUrl) {
            $result = $this->downloadAndUpload($photoUrl, $album->album_id, 'photo', $index);
            if ($result) {
                $this->saveMediaItem($album->id, $photoUrl, 'photo', $result['s3_path'], $result['thumb_path']);
                $this->stats['photos']++;
                $this->stats['downloaded']++;
            }
        }
        
        // Processar vídeos
        foreach ($albumData['videos'] as $index => $videoUrl) {
            $result = $this->downloadAndUpload($videoUrl, $album->album_id, 'video', $index);
            if ($result) {
                $this->saveMediaItem($album->id, $videoUrl, 'video', $result['s3_path'], $result['thumb_path']);
                $this->stats['videos']++;
                $this->stats['downloaded']++;
            }
        }
    }
    
    private function downloadAndUpload(string $url, string $albumId, string $type, int $index): ?array
    {
        try {
            // Download
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
                    'Referer' => self::BASE_URL,
                ])
                ->get($url);
            
            if (!$response->successful()) {
                return null;
            }
            
            $content = $response->body();
            
            // Determinar extensão
            $ext = $type === 'video' ? 'mp4' : 'jpg';
            preg_match('/\.([a-z0-9]+)(?:\?|$)/i', $url, $extMatch);
            if (!empty($extMatch[1])) {
                $ext = strtolower($extMatch[1]);
            }
            
            // Path no R2
            $filename = sprintf('%s_%d_%s.%s', $type, $index + 1, Str::random(8), $ext);
            $s3Path = "albums/{$albumId}/{$filename}";
            
            // Upload para R2
            Storage::disk('r2')->put($s3Path, $content);
            
            return [
                's3_path' => $s3Path,
                'thumb_path' => null, // Thumbnail será gerada depois
            ];
            
        } catch (\Exception $e) {
            $this->error("   ⚠️ Erro download: " . Str::limit($e->getMessage(), 50));
            $this->stats['errors']++;
            return null;
        }
    }
    
    // ========================================================================
    // HELPERS
    // ========================================================================
    
    private function fetchPage(string $url): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'en-US,en;q=0.9,pt-BR;q=0.8',
                    ])
                    ->get($url);
                
                if ($response->successful()) {
                    return $response->body();
                }
                
            } catch (\Exception $e) {
                $this->warn("   ⚠️ Tentativa {$attempt} falhou: " . $e->getMessage());
            }
            
            sleep($attempt * 2);
        }
        
        return null;
    }
    
    private function isBlockedUrl(string $url): bool
    {
        $urlLower = strtolower($url);
        
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains($urlLower, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isValidMediaUrl(string $url, string $type): bool
    {
        if (empty($url) || $this->isBlockedUrl($url)) {
            return false;
        }
        
        $urlLower = strtolower($url);
        
        // Deve ser do CDN do Erome
        if (!str_contains($urlLower, 'erome.com')) {
            return false;
        }
        
        // Excluir assets do site
        $excluded = ['logo', 'avatar', 'icon', 'favicon', 'banner', 'placeholder', 'assets'];
        foreach ($excluded as $ex) {
            if (str_contains($urlLower, $ex)) {
                return false;
            }
        }
        
        // Verificar extensão
        $urlClean = strtok($urlLower, '?');
        
        if ($type === 'photo') {
            return preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $urlClean);
        } else {
            return preg_match('/\.(mp4|webm|mov|m4v)$/i', $urlClean);
        }
    }
    
    private function randomDelay(): void
    {
        $delay = rand((int)(self::REQUEST_DELAY_MIN * 1000), (int)(self::REQUEST_DELAY_MAX * 1000));
        usleep($delay * 1000);
    }
    
    private function displayStats(): void
    {
        $this->newLine();
        $this->info("📊 ESTATÍSTICAS FINAIS:");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Álbuns processados', $this->stats['albums']],
                ['Fotos baixadas', $this->stats['photos']],
                ['Vídeos baixados', $this->stats['videos']],
                ['Total downloads', $this->stats['downloaded']],
                ['Erros', $this->stats['errors']],
            ]
        );
    }
}

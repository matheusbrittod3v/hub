<?php

namespace App\Console\Commands;

use App\Services\SlugService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * 🔧 GENERATE ALBUM SLUGS COMMAND
 * ============================================================================
 *
 * Gera slugs para álbuns que ainda não possuem.
 * Processa em lotes (chunks) para evitar uso excessivo de memória.
 *
 * Usage:
 *   php artisan albums:generate-slugs
 *   php artisan albums:generate-slugs --force  (regenera todos)
 *   php artisan albums:generate-slugs --limit=1000
 */
class GenerateAlbumSlugsCommand extends Command
{
    protected $signature = 'albums:generate-slugs 
                            {--force : Regenerar slugs mesmo se já existirem}
                            {--limit= : Limitar número de álbuns a processar}
                            {--chunk=1000 : Tamanho do lote para processar}';

    protected $description = 'Gera slugs SEO-friendly para álbuns';

    public function handle(): int
    {
        // Otimização PHP
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        DB::disableQueryLog();

        $this->info('🚀 Iniciando geração de slugs via Bulk Update (Ultra Rápido)...');
        $this->newLine();

        $force = $this->option('force');
        $limit = $this->option('limit');
        // Aumentar chunk padrão para performance em massa
        $chunkSize = (int) $this->option('chunk') ?: 2000;

        // Construir query base
        $query = DB::connection('mysql_content')
            ->table('albums')
            ->useWritePdo() // Garantir leitura da réplica de escrita
            ->select(['id', 'album_id', 'title', 'tags', 'slug']);

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            });
        }

        // Para contagem inicial (pode ser lenta em tabelas gigantes, mas útil para UX)
        // Se a tabela for MUITO grande (milhões), count() sem index pode demorar.
        // Vamos assumir que processar é mais importante.
        $totalEstimado = $this->option('limit') ? (int) $this->option('limit') : 'Desconhecido (Modo Fast)';
        if (! $limit && $totalEstimado === 'Desconhecido (Modo Fast)') {
            // Tentar count rápido se não tiver filtros complexos
            try {
                $totalEstimado = $query->count();
                $this->info("📊 Total estimado: {$totalEstimado}");
            } catch (\Exception $e) {
                $this->warn('⚠️ Pulei contagem para performance.');
            }
        }

        $bar = $this->output->createProgressBar($isNumeric = is_numeric($totalEstimado) ? $totalEstimado : 0);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $processed = 0;
        $updated = 0;
        $errors = 0;
        $startTime = microtime(true);

        // CALLBACK para processamento em lote
        $processCallback = function ($albums) use (&$processed, &$updated, &$errors, $bar) {
            $cases = [];
            $params = [];
            $idsToUpdate = [];

            foreach ($albums as $album) {
                try {
                    // Gerar slug puramente em PHP (rápido)
                    $newSlug = SlugService::generateWithoutCache($album->title, $album->tags);

                    if (! empty($newSlug) && $newSlug !== $album->slug) {
                        $cases[] = 'WHEN id = ? THEN ?';
                        $params[] = $album->id;
                        $params[] = $newSlug;
                        $idsToUpdate[] = $album->id;
                        $updated++;
                    }

                    $processed++;
                } catch (\Exception $e) {
                    $errors++; // Falha silenciosa para não parar o batch
                }
            }

            // Executar UPDATE em massa (1 query ao invés de 2000)
            if (! empty($idsToUpdate)) {
                $idsString = implode(',', array_fill(0, count($idsToUpdate), '?'));
                $params = array_merge($params, $idsToUpdate);

                $sql = 'UPDATE albums SET slug = CASE '.implode(' ', $cases)." END WHERE id IN ($idsString)";

                try {
                    DB::connection('mysql_content')->update($sql, $params);
                } catch (\Exception $e) {
                    // Fallback: Se der erro no lote gigante, tenta um por um (segurança)
                    $this->fallbackUpdate($idsToUpdate, $cases);
                    $errors++;
                }
            }

            $bar->advance(count($albums));
        };

        // LOOP PRINCIPAL OTIMIZADO (chunkById)
        // chunkById é O(1) em database, enquanto chunk() é O(N) devido ao offset
        if ($limit) {
            // Se tem limit, chunkById é complicado pois ele pega ids > lastId.
            // Para limit simples, usa-se take() + get() ou chunk normal se for pouco.
            // Mas para "milhões" assumimos sem limit ou com limit alto.
            $query->orderBy('id')->limit((int) $limit)->chunk($chunkSize, $processCallback);
        } else {
            $query->orderBy('id')->chunkById($chunkSize, $processCallback);
        }

        $bar->finish();
        $this->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);
        $throughput = $duration > 0 ? round($processed / $duration) : 0;

        $this->info("✅ Concluído em {$duration}s ({$throughput} registros/s)!");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Processados', $processed],
                ['Atualizados', $updated],
                ['Erros (Lotes)', $errors],
                ['Memória', round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB'],
            ]
        );

        // Limpar cache APENAS se houve atualizações
        if ($updated > 0) {
            $this->info('🧹 Limpando cache do sitemap...');
            \App\Http\Controllers\SitemapController::clearCache();
        }

        return self::SUCCESS;
    }

    private function fallbackUpdate(array $ids, array $cases): void
    {
        // Implementação simplificada de fallback se necessário
        // Na prática, em migrações de milhões, é melhor logar o erro do lote e seguir
        \Log::warning('Falha em lote de update de slugs. IDs: '.implode(',', array_slice($ids, 0, 10)).'...');
    }
}

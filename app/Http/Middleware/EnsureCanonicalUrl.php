<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Album;
use App\Services\SlugService;

/**
 * ============================================================================
 * 🔀 CANONICAL URL MIDDLEWARE - "Método Mágico" para URLs SEO
 * ============================================================================
 * 
 * Este middleware garante que as URLs sempre tenham o slug correto.
 * Se o slug estiver faltando ou incorreto, redireciona para a URL canônica.
 * 
 * Benefícios:
 * - SEO: Evita conteúdo duplicado (URLs diferentes para o mesmo conteúdo)
 * - UX: Usuários sempre veem a URL correta
 * - Flexibilidade: Aceita URLs antigas mesmo após mudar o título
 */
class EnsureCanonicalUrl
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar se é uma rota de álbum
        if ($request->route()->getName() === 'album.show') {
            return $this->handleAlbumUrl($request, $next);
        }

        // Verificar se é uma rota de perfil
        if ($request->route()->getName() === 'profile.show') {
            return $this->handleProfileUrl($request, $next);
        }

        return $next($request);
    }

    /**
     * Valida e corrige URL de álbum
     */
    private function handleAlbumUrl(Request $request, Closure $next): Response
    {
        $albumId = $request->route('id');
        $providedSlug = $request->route('slug');

        // Buscar álbum pelo ID
        $album = Album::where('album_id', $albumId)->first();

        if (!$album) {
            // Álbum não existe, deixar o controller lidar com 404
            return $next($request);
        }

        // Obter slug correto
        $correctSlug = $album->slug ?: SlugService::generateFromTitle($album->title, $album->tags);

        // Se não tiver slug ou o slug estiver correto, continuar
        if (empty($providedSlug) || $providedSlug === $correctSlug) {
            return $next($request);
        }

        // ⚠️ Slug incorreto! Redirecionar para URL canônica
        $canonicalUrl = route('album.show', [
            'id' => $albumId,
            'slug' => $correctSlug
        ]);

        return redirect($canonicalUrl, 301); // 301 = Permanent Redirect (SEO-friendly)
    }

    /**
     * Valida e corrige URL de perfil
     */
    private function handleProfileUrl(Request $request, Closure $next): Response
    {
        $userId = $request->route('id');
        $providedUsername = $request->route('username');

        // Buscar usuário
        $user = \App\Models\User::find($userId);

        if (!$user) {
            return $next($request);
        }

        // Obter username correto (slug)
        $correctUsername = SlugService::generateFromTitle($user->name);

        // Se não tiver username na URL ou estiver correto, continuar
        if (empty($providedUsername) || $providedUsername === $correctUsername) {
            return $next($request);
        }

        // Redirecionar para URL canônica
        $canonicalUrl = route('profile.show', [
            'id' => $userId,
            'username' => $correctUsername
        ]);

        return redirect($canonicalUrl, 301);
    }
}

<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            total_views: 0,
            total_likes: 0,
            total_albums: 0,
            total_photos: 0,
            total_videos: 0
        })
    },
    albums: {
        type: Object,
        default: () => ({ data: [] })
    }
});

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const t = computed(() => page.props.translations || {});
const albumsList = computed(() => props.albums?.data || []);

// Pagination Helper
const pagination = computed(() => ({
    currentPage: props.albums?.current_page || 1,
    lastPage: props.albums?.last_page || 1,
    links: props.albums?.links || [],
}));

const formatNumber = (num) => {
    if (!num) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
};
</script>

<template>
    <AppLayout :title="t?.dashboard?.title || 'Dashboard'">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ t?.dashboard?.title || 'Dashboard' }}
            </h2>
        </template>

        <!-- Profile Stats Header -->
        <div class="profile-header-card">
            <div class="profile-main">
                <img 
                    :src="user.computed_avatar_url || user.profile_photo_url || '/assets/images/default-avatar.jpg'" 
                    :alt="user.name" 
                    class="profile-avatar"
                />
                <div class="profile-info">
                    <h1 class="profile-name">{{ user?.name || 'Usuário' }}</h1>
                    <p class="profile-username">@{{ user?.username || (user?.email ? user.email.split('@')[0] : 'user') }}</p>
                    <div class="profile-badges">
                        <span class="badge user-badge">{{ t?.dashboard?.member || 'Membro' }}</span>
                        <!-- <span class="badge premium-badge">Premium</span> -->
                    </div>
                </div>
            </div>

            <div class="profile-stats">
                <div class="stat-item">
                    <span class="stat-value">{{ formatNumber(stats.total_views) }}</span>
                    <span class="stat-label">{{ t?.album?.views || 'Visualizações' }}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">{{ formatNumber(stats.total_likes) }}</span>
                    <span class="stat-label">{{ t?.album?.likes || 'Curtidas' }}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">{{ stats.total_albums }}</span>
                    <span class="stat-label">{{ t?.dashboard?.albums || 'Álbuns' }}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">{{ stats.total_videos }}</span>
                    <span class="stat-label">{{ t?.dashboard?.videos || 'Vídeos' }}</span>
                </div>
            </div>
            
            <div class="profile-actions">
                <Link href="/user/profile" class="btn btn-secondary">
                    <i class="fas fa-cog"></i> {{ t?.nav?.settings || 'Configurações' }}
                </Link>
                <Link :href="route('album.create')" class="btn btn-primary">
                    <i class="fas fa-cloud-upload-alt"></i> {{ t?.welcome?.upload || 'Novo Upload' }}
                </Link>
            </div>
        </div>

        <div class="content-section">
            <h2 class="section-title">
                <i class="fas fa-folder-open"></i> {{ t?.dashboard?.my_content || 'Meus Conteúdos' }}
            </h2>
            
            <div v-if="albumsList.length > 0" class="albums-grid">
                <AlbumCard 
                    v-for="album in albumsList" 
                    :key="album.id"
                    :album="album"
                />
            </div>
            
            <div v-else class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <h3>Você ainda não enviou nada!</h3>
                <p>Comece a compartilhar seus vídeos e fotos com a comunidade.</p>
                <button class="btn btn-primary mt-4">
                    Enviar Agora
                </button>
            </div>

            <!-- Pagination -->
            <div v-if="pagination.lastPage > 1" class="pagination-wrapper">
                <div class="pagination">
                    <Link 
                        v-for="(link, i) in pagination.links" 
                        :key="i"
                        :href="link.url"
                        v-html="link.label"
                        :class="['pagination-item', { 
                            'active': link.active, 
                            'disabled': !link.url 
                        }]"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Profile Header */
.profile-header-card {
    background: var(--dark-card);
    border-radius: var(--radius-lg);
    padding: 30px;
    margin-bottom: 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 30px;
}

.profile-main {
    display: flex;
    align-items: center;
    gap: 20px;
}

.profile-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    border: 4px solid var(--dark-bg);
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    object-fit: cover;
}

.profile-name {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.2;
}

.profile-username {
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.profile-badges {
    display: flex;
    gap: 8px;
}

.badge {
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.user-badge {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-secondary);
}

.premium-badge {
    background: var(--gradient-primary);
    color: white;
}

/* Stats */
.profile-stats {
    display: flex;
    gap: 30px;
    background: rgba(0,0,0,0.2);
    padding: 15px 30px;
    border-radius: var(--radius-md);
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 80px;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Actions */
.profile-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
    border: none;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-secondary {
    background: rgba(255,255,255,0.1);
    color: white;
}

.btn-secondary:hover {
    background: rgba(255,255,255,0.2);
}

/* Content Section */
.section-title {
    font-size: 1.4rem;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-primary);
}

.content-section {
    min-height: 400px;
}

.empty-state {
    text-align: center;
    padding: 80px;
    background: var(--dark-card);
    border-radius: var(--radius-lg);
    border: 1px dashed var(--border);
}

.empty-icon {
    font-size: 4rem;
    color: var(--text-muted);
    margin-bottom: 20px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 1.4rem;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.empty-state p {
    color: var(--text-secondary);
}

.pagination-wrapper {
    display: flex;
    justify-content: center;
    margin-top: 40px;
}

@media (max-width: 1024px) {
    .profile-header-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .profile-main {
        flex-direction: column;
    }
    
    .profile-stats {
        width: 100%;
        justify-content: space-around;
    }
    
    .profile-actions {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';

const props = defineProps({
    profile: Object,
    stats: Object,
    albums: Array,
    pagination: Object,
    isOwnProfile: Boolean,
});

const page = usePage();

// Refs for file inputs
const avatarInput = ref(null);
const coverInput = ref(null);
const uploadingAvatar = ref(false);
const uploadingCover = ref(false);

const formatNumber = (num) => {
    if (!num) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
};

const shareProfile = () => {
    const url = window.location.href;
    if (navigator.share) {
        navigator.share({ title: props.profile.name, url: url });
    } else {
        navigator.clipboard.writeText(url);
        alert('Link copiado!');
    }
};

// Upload Avatar
const triggerAvatarUpload = () => {
    avatarInput.value?.click();
};

const handleAvatarChange = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    uploadingAvatar.value = true;
    
    const formData = new FormData();
    formData.append('avatar', file);

    router.post(route('profile.avatar'), formData, {
        forceFormData: true,
        onFinish: () => {
            uploadingAvatar.value = false;
        },
    });
};

// Upload Cover
const triggerCoverUpload = () => {
    coverInput.value?.click();
};

const handleCoverChange = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    uploadingCover.value = true;
    
    const formData = new FormData();
    formData.append('cover', file);

    router.post(route('profile.cover'), formData, {
        forceFormData: true,
        onFinish: () => {
            uploadingCover.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :title="profile.name">
        <Head :title="profile.name" />

        <div class="profile-page">
            <!-- Profile Header -->
            <div class="profile-header">
                <!-- Cover Image -->
                <div class="profile-cover" :style="profile.cover ? `background-image: url('${profile.cover}')` : ''">
                    <!-- Edit Cover Button -->
                    <button 
                        v-if="isOwnProfile" 
                        class="edit-cover-btn"
                        @click="triggerCoverUpload"
                        :disabled="uploadingCover"
                        title="Alterar foto de capa"
                    >
                        <i :class="uploadingCover ? 'fas fa-spinner fa-spin' : 'fas fa-pencil-alt'"></i>
                    </button>
                    <input 
                        ref="coverInput"
                        type="file" 
                        accept="image/*" 
                        class="hidden-input"
                        @change="handleCoverChange"
                    />
                </div>
                
                <div class="profile-info">
                    <!-- Avatar with Edit Button -->
                    <div class="profile-avatar">
                        <img :src="profile.avatar" :alt="profile.name" />
                        <button 
                            v-if="isOwnProfile" 
                            class="edit-avatar-btn"
                            @click="triggerAvatarUpload"
                            :disabled="uploadingAvatar"
                            title="Alterar foto de perfil"
                        >
                            <i :class="uploadingAvatar ? 'fas fa-spinner fa-spin' : 'fas fa-pencil-alt'"></i>
                        </button>
                        <input 
                            ref="avatarInput"
                            type="file" 
                            accept="image/*" 
                            class="hidden-input"
                            @change="handleAvatarChange"
                        />
                    </div>
                    
                    <div class="profile-details">
                        <h1 class="profile-name">{{ profile.name }}</h1>
                        
                        <p v-if="profile.bio" class="profile-bio">{{ profile.bio }}</p>
                        
                        <div class="profile-stats">
                            <div class="profile-stat">
                                <div class="profile-stat-value">{{ formatNumber(stats.albums) }}</div>
                                <div class="profile-stat-label">Álbuns</div>
                            </div>
                            <div class="profile-stat">
                                <div class="profile-stat-value">{{ formatNumber(stats.views) }}</div>
                                <div class="profile-stat-label">Views</div>
                            </div>
                            <div class="profile-stat">
                                <div class="profile-stat-value">{{ formatNumber(stats.likes) }}</div>
                                <div class="profile-stat-label">Curtidas</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-actions">
                        <template v-if="isOwnProfile">
                            <Link href="/user/profile" class="btn btn-secondary">
                                <i class="fas fa-cog"></i> Configurações
                            </Link>
                            <Link :href="route('album.create')" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Novo Upload
                            </Link>
                        </template>
                        <template v-else>
                            <button class="btn btn-secondary" @click="shareProfile">
                                <i class="fas fa-share"></i> Compartilhar
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Albums Section -->
            <div class="albums-section">
                <h2 class="section-title">
                    <i class="fas fa-folder"></i> Álbuns 
                    <span class="count">({{ stats.albums }})</span>
                </h2>

                <div v-if="albums.length === 0" class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>Nenhum álbum ainda</h3>
                    <p v-if="isOwnProfile">Crie seu primeiro álbum agora!</p>
                    <Link v-if="isOwnProfile" :href="route('album.create')" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Criar Álbum
                    </Link>
                </div>

                <div v-else class="albums-grid">
                    <AlbumCard 
                        v-for="album in albums" 
                        :key="album.id" 
                        :album="album"
                    />
                </div>

                <!-- Pagination -->
                <div v-if="pagination.last_page > 1" class="pagination">
                    <span class="pagination-info">
                        Página {{ pagination.current_page }} de {{ pagination.last_page }}
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.profile-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Hidden file input */
.hidden-input {
    display: none;
}

/* Profile Header */
.profile-header {
    background: var(--dark-card);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 40px;
    border: 1px solid var(--border);
}

.profile-cover {
    height: 200px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    background-size: cover;
    background-position: center;
    position: relative;
}

/* Edit Cover Button */
.edit-cover-btn {
    position: absolute;
    bottom: 16px;
    right: 16px;
    width: 40px;
    height: 40px;
    background: rgba(0,0,0,0.6);
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    backdrop-filter: blur(4px);
}

.edit-cover-btn:hover {
    background: var(--primary);
    border-color: var(--primary);
    transform: scale(1.1);
}

.edit-cover-btn:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}

.profile-info {
    display: flex;
    align-items: flex-start;
    padding: 0 30px 30px;
    padding-top: 20px;
    gap: 24px;
    flex-wrap: wrap;
    min-height: 100px;
}

.profile-avatar {
    margin-top: -60px;
    flex-shrink: 0;
    position: relative;
    z-index: 10;
}

.profile-avatar img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid var(--dark-card);
    object-fit: cover;
    background: var(--dark-bg);
}

/* Edit Avatar Button */
.edit-avatar-btn {
    position: absolute;
    bottom: 4px;
    right: 4px;
    width: 32px;
    height: 32px;
    background: var(--primary);
    border: 2px solid var(--dark-card);
    border-radius: 50%;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    font-size: 0.8rem;
}

.edit-avatar-btn:hover {
    background: #e02d5c;
    transform: scale(1.1);
}

.edit-avatar-btn:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}

.profile-details {
    flex: 1;
    min-width: 200px;
    padding-top: 10px;
}

.profile-name {
    font-size: 1.8rem;
    margin-bottom: 4px;
    color: var(--text-primary);
}

.profile-username {
    color: var(--text-secondary);
    margin-bottom: 12px;
}

.profile-bio {
    color: var(--text-secondary);
    margin-bottom: 16px;
    line-height: 1.5;
}

.profile-stats {
    display: flex;
    gap: 30px;
}

.profile-stat {
    text-align: center;
}

.profile-stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
}

.profile-stat-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.profile-actions {
    display: flex;
    gap: 12px;
    margin-left: auto;
}

/* Albums Section */
.albums-section {
    margin-top: 40px;
}

.section-title {
    font-size: 1.4rem;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: var(--primary);
}

.section-title .count {
    color: var(--text-secondary);
    font-weight: normal;
}

.albums-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
    background: var(--dark-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    color: var(--text-muted);
}

.empty-state h3 {
    margin-bottom: 8px;
    color: var(--text-primary);
}

.empty-state .btn {
    margin-top: 20px;
}

.pagination {
    display: flex;
    justify-content: center;
    margin-top: 40px;
}

.pagination-info {
    color: var(--text-secondary);
    padding: 10px 20px;
    background: var(--dark-card);
    border-radius: var(--radius-md);
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: var(--radius-md);
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-secondary {
    background: rgba(255,255,255,0.1);
    color: var(--text-primary);
}

.btn:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .profile-info {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .profile-avatar {
        margin-top: -50px;
    }

    .profile-actions {
        margin-left: 0;
        width: 100%;
        justify-content: center;
    }

    .profile-stats {
        justify-content: center;
    }
}
</style>

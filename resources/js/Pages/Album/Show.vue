<script setup>
import { ref, onMounted, computed, onBeforeUnmount } from 'vue';
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';
import VideoPlayer from '@/Components/VideoPlayer.vue';
import AdBanner from '@/Components/AdBanner.vue';

const props = defineProps({
    album: Object,
    videos: Array,
    photos: Array,
    relatedAlbums: Array,
});

const page = usePage();
const isOwner = computed(() => page.props.auth?.user?.id === props.album.user_id);
const isLoggedIn = computed(() => !!page.props.auth?.user);
const t = computed(() => page.props.translations || {});

// ============================================================================
// LIKES, VIEWS & STATS
// ============================================================================
const likeCount = ref(props.album.likes || 0);
const viewCount = ref(props.album.views || 0);
const hasLiked = ref(false);
const isLiking = ref(false);

// Register view on mount
onMounted(async () => {
    window.addEventListener('keydown', handleKeydown);
    
    // Register view
    try {
        await fetch('/interactions/view', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                type: 'album',
                id: props.album.id,
            }),
        });
    } catch (e) {
        console.warn('Failed to register view:', e);
    }
    
    // Check like status - first check localStorage, then API
    const localLikeKey = `liked_album_${props.album.id}`;
    const localLikeValue = localStorage.getItem(localLikeKey);
    
    if (localLikeValue !== null) {
        hasLiked.value = localLikeValue === 'true';
    }
    
    try {
        const res = await fetch(`/interactions/like/check?type=album&id=${props.album.id}`, {
            credentials: 'include', // Include cookies for session
        });
        const data = await res.json();
        hasLiked.value = data.liked;
        likeCount.value = data.count;
        // Sync localStorage with server
        localStorage.setItem(localLikeKey, data.liked ? 'true' : 'false');
    } catch (e) {
        console.warn('Failed to check like status:', e);
    }
});

// Toggle like (works for both logged-in and anonymous users)
const toggleLike = async () => {
    if (isLiking.value) return;
    isLiking.value = true;
    
    const localLikeKey = `liked_album_${props.album.id}`;
    
    // Optimistic update
    const wasLiked = hasLiked.value;
    hasLiked.value = !wasLiked;
    likeCount.value += wasLiked ? -1 : 1;
    
    // Update localStorage immediately
    localStorage.setItem(localLikeKey, hasLiked.value ? 'true' : 'false');
    
    try {
        const res = await fetch('/interactions/like', {
            method: 'POST',
            credentials: 'include', // Include cookies for session
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                type: 'album',
                id: props.album.id,
            }),
        });
        
        const data = await res.json();
        
        if (data.success) {
            hasLiked.value = data.liked;
            likeCount.value = data.count;
            // Sync localStorage with server response
            localStorage.setItem(localLikeKey, data.liked ? 'true' : 'false');
        } else {
            // Revert on failure
            hasLiked.value = wasLiked;
            likeCount.value += wasLiked ? 1 : -1;
            localStorage.setItem(localLikeKey, wasLiked ? 'true' : 'false');
            console.warn('Like failed:', data.message);
        }
    } catch (e) {
        // Revert on error
        hasLiked.value = wasLiked;
        likeCount.value += wasLiked ? 1 : -1;
        localStorage.setItem(localLikeKey, wasLiked ? 'true' : 'false');
        console.error('Like error:', e);
    } finally {
        isLiking.value = false;
    }
};

// ============================================================================
// REPORT MODAL
// ============================================================================
const showReportModal = ref(false);
const reportReason = ref('');
const reportDescription = ref('');
const reportReasons = ref({});
const isReporting = ref(false);

// Fetch report reasons
const fetchReportReasons = async () => {
    try {
        const res = await fetch('/interactions/report/reasons');
        const data = await res.json();
        reportReasons.value = data.reasons;
    } catch (e) {
        reportReasons.value = {
            'spam': 'Spam ou propaganda',
            'illegal': 'Conteúdo ilegal',
            'underage': 'Possível menor de idade',
            'violence': 'Violência extrema',
            'non_consensual': 'Conteúdo não consensual',
            'copyright': 'Violação de direitos autorais',
            'other': 'Outros',
        };
    }
};

const openReportModal = () => {
    fetchReportReasons();
    showReportModal.value = true;
    document.body.style.overflow = 'hidden';
};

const closeReportModal = () => {
    showReportModal.value = false;
    reportReason.value = '';
    reportDescription.value = '';
    document.body.style.overflow = 'auto';
};

const submitReport = async () => {
    if (!reportReason.value) {
        alert('Por favor, selecione um motivo.');
        return;
    }
    
    if (isReporting.value) return;
    isReporting.value = true;
    
    try {
        const res = await fetch('/interactions/report', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                type: 'album',
                id: props.album.id,
                reason: reportReason.value,
                description: reportDescription.value,
            }),
        });
        
        const data = await res.json();
        
        if (data.success) {
            alert('Denúncia enviada com sucesso! Iremos analisar em breve.');
            closeReportModal();
        } else {
            alert(data.message || 'Erro ao enviar denúncia.');
        }
    } catch (e) {
        alert('Erro ao enviar denúncia. Tente novamente.');
        console.error('Report error:', e);
    } finally {
        isReporting.value = false;
    }
};

// ============================================================================
// SHARE MODAL
// ============================================================================
const showShareModal = ref(false);
const activeShareTab = ref('link');
const copiedField = ref(null);

// Computed share data
const albumUrl = computed(() => window.location.href);
const albumTitle = computed(() => props.album.title);
const albumDescription = computed(() => props.album.description || `Confira ${props.album.title}`);
const albumThumbnail = computed(() => {
    if (props.photos.length > 0) return props.photos[0].url;
    if (props.videos.length > 0) return props.videos[0].thumbnail;
    return '';
});

// Embed codes
const embedIframe = computed(() => {
    return `<iframe src="${albumUrl.value}?embed=1" width="640" height="480" frameborder="0" allowfullscreen></iframe>`;
});

const embedBBCode = computed(() => {
    return `[url=${albumUrl.value}][img]${albumThumbnail.value}[/img][/url]`;
});

const embedBBCodeText = computed(() => {
    return `[url=${albumUrl.value}]${albumTitle.value}[/url]`;
});

const embedHtml = computed(() => {
    return `<a href="${albumUrl.value}" target="_blank"><img src="${albumThumbnail.value}" alt="${albumTitle.value}" /></a>`;
});

const embedMarkdown = computed(() => {
    return `[![${albumTitle.value}](${albumThumbnail.value})](${albumUrl.value})`;
});

const openShareModal = () => {
    showShareModal.value = true;
    activeShareTab.value = 'link';
    copiedField.value = null;
    document.body.style.overflow = 'hidden';
};

const closeShareModal = () => {
    showShareModal.value = false;
    copiedField.value = null;
    document.body.style.overflow = 'auto';
};

const copyToClipboard = (text, fieldName = null) => {
    navigator.clipboard.writeText(text).then(() => {
        if (fieldName) {
            copiedField.value = fieldName;
            setTimeout(() => {
                copiedField.value = null;
            }, 2000);
        }
    }).catch(() => {
        // Fallback
        const input = document.createElement('textarea');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        if (fieldName) {
            copiedField.value = fieldName;
            setTimeout(() => {
                copiedField.value = null;
            }, 2000);
        }
    });
};

// Social share URLs
const shareTwitter = () => {
    const url = `https://twitter.com/intent/tweet?url=${encodeURIComponent(albumUrl.value)}&text=${encodeURIComponent(albumTitle.value)}`;
    window.open(url, '_blank', 'width=600,height=400');
};

const shareFacebook = () => {
    const url = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(albumUrl.value)}`;
    window.open(url, '_blank', 'width=600,height=400');
};

const shareReddit = () => {
    const url = `https://reddit.com/submit?url=${encodeURIComponent(albumUrl.value)}&title=${encodeURIComponent(albumTitle.value)}`;
    window.open(url, '_blank', 'width=600,height=600');
};

const shareTelegram = () => {
    const url = `https://t.me/share/url?url=${encodeURIComponent(albumUrl.value)}&text=${encodeURIComponent(albumTitle.value)}`;
    window.open(url, '_blank', 'width=600,height=400');
};

const shareWhatsApp = () => {
    const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(albumTitle.value + ' ' + albumUrl.value)}`;
    window.open(url, '_blank');
};

const shareEmail = () => {
    const subject = encodeURIComponent(albumTitle.value);
    const body = encodeURIComponent(`Confira este conteúdo: ${albumUrl.value}`);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
};

// Native share (mobile)
const shareNative = async () => {
    if (navigator.share) {
        try {
            await navigator.share({
                title: albumTitle.value,
                text: albumDescription.value,
                url: albumUrl.value,
            });
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.warn('Native share failed:', e);
            }
        }
    }
};

// ============================================================================
// LIGHTBOX
// ============================================================================
const lightboxOpen = ref(false);
const activePhotoIndex = ref(0);
const videoLightboxOpen = ref(false);
const activeVideoIndex = ref(0);

const openLightbox = (index) => {
    activePhotoIndex.value = index;
    lightboxOpen.value = true;
    document.body.style.overflow = 'hidden';
};

const closeLightbox = () => {
    lightboxOpen.value = false;
    document.body.style.overflow = 'auto';
};

const openVideoLightbox = (index) => {
    activeVideoIndex.value = index;
    videoLightboxOpen.value = true;
    document.body.style.overflow = 'hidden';
};

const closeVideoLightbox = () => {
    videoLightboxOpen.value = false;
    document.body.style.overflow = 'auto';
};

const nextPhoto = () => {
    if (activePhotoIndex.value < props.photos.length - 1) {
        activePhotoIndex.value++;
    } else {
        activePhotoIndex.value = 0;
    }
};

const prevPhoto = () => {
    if (activePhotoIndex.value > 0) {
        activePhotoIndex.value--;
    } else {
        activePhotoIndex.value = props.photos.length - 1;
    }
};

const nextVideo = () => {
    if (activeVideoIndex.value < props.videos.length - 1) {
        activeVideoIndex.value++;
    } else {
        activeVideoIndex.value = 0;
    }
};

const prevVideo = () => {
    if (activeVideoIndex.value > 0) {
        activeVideoIndex.value--;
    } else {
        activeVideoIndex.value = props.videos.length - 1;
    }
};

// Keyboard navigation
const handleKeydown = (e) => {
    if (showReportModal.value && e.key === 'Escape') {
        closeReportModal();
        return;
    }
    if (lightboxOpen.value) {
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowRight') nextPhoto();
        if (e.key === 'ArrowLeft') prevPhoto();
    }
    if (videoLightboxOpen.value) {
        if (e.key === 'Escape') closeVideoLightbox();
        if (e.key === 'ArrowRight' && props.videos.length > 1) nextVideo();
        if (e.key === 'ArrowLeft' && props.videos.length > 1) prevVideo();
    }
};

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
});

// ============================================================================
// UTILITIES
// ============================================================================
const formatNumber = (num) => {
    if (!num) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
};

// ============================================================================
// DELETE FUNCTIONALITY
// ============================================================================
const showDeleteConfirm = ref(false);
const deleteTargetMedia = ref(null);

const confirmDeleteAlbum = () => {
    if (confirm('Tem certeza que deseja DELETAR este álbum? Todos os arquivos serão removidos permanentemente.')) {
        router.delete(route('album.destroy', props.album.album_id));
    }
};

const deleteMedia = (mediaId) => {
    if (confirm('Tem certeza que deseja deletar esta mídia?')) {
        router.delete(route('media.destroy', mediaId));
    }
};

// Video preview hover state
const hoveredVideo = ref(null);
const videoPreviewRefs = ref({});

const handleVideoMouseEnter = (videoId) => {
    hoveredVideo.value = videoId;
};

const handleVideoMouseLeave = (videoId) => {
    hoveredVideo.value = null;
};
</script>

<template>
    <AppLayout :title="album.title">
        <Head :title="album.title" />

        <div class="album-container">
            <!-- Header Section -->
            <div class="album-header">
                <div>
                    <h1 class="album-title">{{ album.title }}</h1>
                    <div class="album-meta">
                        <span class="meta-item">
                            <i class="fas fa-eye"></i> {{ formatNumber(viewCount) }} {{ t.album?.views || 'visualizações' }}
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-heart"></i> {{ formatNumber(likeCount) }} {{ t.album?.likes || 'curtidas' }}
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-calendar-alt"></i> {{ album.created_at }}
                        </span>
                    </div>
                </div>
                
                <div class="author-info">
                    <img :src="album.author.avatar" :alt="album.author.name" class="author-avatar" />
                    <div>
                        <span class="author-label">Enviado por</span>
                        <a :href="album.author.url" class="author-name">{{ album.author.name }}</a>
                    </div>
                </div>
                
                <!-- Owner Controls -->
                <div v-if="isOwner" class="owner-controls">
                    <button class="btn btn-danger" @click="confirmDeleteAlbum">
                        <i class="fas fa-trash-alt"></i> Deletar Álbum
                    </button>
                </div>
            </div>

            <!-- Video Section with Multiple Players Grid -->
            <div v-if="videos.length > 0" class="video-section">
                <h2 class="section-title"><i class="fas fa-video"></i> Vídeos ({{ videos.length }})</h2>
                
                <!-- Album Ad -->
                <AdBanner zone="1109450" width="125" height="125" :count="5" />

                <!-- Videos Grid - Multiple Players Side by Side -->
                <div class="videos-grid" :class="{ 'single-video': videos.length === 1, 'two-videos': videos.length === 2 }">
                    <div 
                        v-for="(video, index) in videos" 
                        :key="video.id" 
                        class="video-grid-item"
                    >
                        <!-- Delete Button -->
                        <button 
                            v-if="isOwner" 
                            class="delete-media-btn"
                            @click.stop="deleteMedia(video.id)"
                            title="Deletar vídeo"
                        >
                            <i class="fas fa-trash"></i>
                        </button>
                        
                        <!-- Video Number Badge -->
                        <span v-if="videos.length > 1" class="video-number">{{ index + 1 }}</span>
                        
                        <!-- Custom Video Player for each video -->
                        <VideoPlayer
                            :src="video.url"
                            :poster="video.thumbnail"
                            :title="`${album.title} - Vídeo ${index + 1}`"
                        />
                    </div>
                </div>
            </div>

            <!-- Photos Section -->
            <div v-if="photos.length > 0" class="photos-section">
                <h2 class="section-title"><i class="fas fa-images"></i> Galeria de Fotos ({{ photos.length }})</h2>
                
                <div class="photos-grid">
                    <div 
                        v-for="(photo, index) in photos" 
                        :key="photo.id" 
                        class="photo-item"
                    >
                        <button v-if="isOwner" class="delete-media-btn" @click.stop="deleteMedia(photo.id)" title="Deletar foto">
                            <i class="fas fa-trash"></i>
                        </button>
                        <img 
                            :src="photo.thumbnail || photo.url" 
                            :alt="`Photo ${index + 1}`"
                            loading="lazy"
                            @click="openLightbox(index)"
                        />
                        <div class="zoom-overlay" @click="openLightbox(index)">
                            <i class="fas fa-search-plus"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Album Actions & Tags -->
            <div class="album-footer">
                <div class="tags-list" v-if="album.tags.length > 0">
                    <span v-for="tag in album.tags" :key="tag" class="tag">#{{ tag.trim() }}</span>
                </div>
                
                <div class="action-buttons">
                    <button 
                        class="btn" 
                        :class="hasLiked ? 'btn-liked' : 'btn-primary'"
                        @click="toggleLike"
                        :disabled="isLiking"
                    >
                        <i :class="['fas', hasLiked ? 'fa-heart' : 'fa-thumbs-up']"></i> 
                        {{ hasLiked ? 'Curtido' : (t.album?.like || 'Curtir') }}
                        <span class="like-count">({{ formatNumber(likeCount) }})</span>
                    </button>
                    <button class="btn btn-secondary" @click="openShareModal">
                        <i class="fas fa-share-alt"></i> {{ t.album?.share || 'Compartilhar' }}
                    </button>
                    <button class="btn btn-warning" @click="openReportModal">
                        <i class="fas fa-flag"></i> {{ t.album?.report || 'Denunciar' }}
                    </button>
                </div>
            </div>

            <!-- Description -->
            <div v-if="album.description" class="album-description">
                <h3>{{ t.album?.about || 'Sobre este álbum' }}</h3>
                <p>{{ album.description }}</p>
            </div>

            <hr class="divider" />

            <!-- Related Albums -->
            <div class="related-section">
                <h2 class="section-title">{{ t.album?.related || 'Recomendados para você' }}</h2>
                <div class="photos-grid related-grid"> <!-- Reusing grid styles or custom -->
                    <AlbumCard 
                        v-for="relAlbum in relatedAlbums" 
                        :key="relAlbum.id" 
                        :album="relAlbum"
                    />
                </div>
            </div>
        </div>

        <!-- Lightbox -->
        <div v-if="lightboxOpen && photos.length > 0 && photos[activePhotoIndex]" class="lightbox" @click.self="closeLightbox">
            <button class="close-btn" @click="closeLightbox"><i class="fas fa-times"></i></button>
            <button class="nav-btn prev" @click="prevPhoto"><i class="fas fa-chevron-left"></i></button>
            
            <div class="lightbox-content">
                <img :src="photos[activePhotoIndex]?.url" alt="" class="lightbox-img" />
                <div class="lightbox-counter">{{ activePhotoIndex + 1 }} / {{ photos.length }}</div>
            </div>
            
            <button class="nav-btn next" @click="nextPhoto"><i class="fas fa-chevron-right"></i></button>
        </div>

        <!-- Report Modal -->
        <div v-if="showReportModal" class="modal-overlay" @click.self="closeReportModal">
            <div class="modal-container">
                <div class="modal-header">
                    <h2><i class="fas fa-flag"></i> Denunciar Conteúdo</h2>
                    <button class="modal-close" @click="closeReportModal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="modal-body">
                    <p class="modal-subtitle">Por que você está denunciando este conteúdo?</p>
                    
                    <div class="report-reasons">
                        <label 
                            v-for="(label, key) in reportReasons" 
                            :key="key" 
                            class="report-reason-option"
                            :class="{ 'selected': reportReason === key }"
                        >
                            <input 
                                type="radio" 
                                :value="key" 
                                v-model="reportReason" 
                                name="report-reason"
                            />
                            <span class="reason-radio"></span>
                            <span class="reason-text">{{ label }}</span>
                        </label>
                    </div>
                    
                    <div class="report-description">
                        <label for="report-description">Detalhes adicionais (opcional)</label>
                        <textarea 
                            id="report-description"
                            v-model="reportDescription"
                            placeholder="Descreva o problema com mais detalhes..."
                            rows="4"
                            maxlength="2000"
                        ></textarea>
                        <span class="char-count">{{ reportDescription.length }}/2000</span>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="closeReportModal">
                        Cancelar
                    </button>
                    <button 
                        class="btn btn-danger" 
                        @click="submitReport"
                        :disabled="!reportReason || isReporting"
                    >
                        <i v-if="isReporting" class="fas fa-spinner fa-spin"></i>
                        <i v-else class="fas fa-paper-plane"></i>
                        {{ isReporting ? 'Enviando...' : 'Enviar Denúncia' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Share Modal -->
        <div v-if="showShareModal" class="modal-overlay" @click.self="closeShareModal">
            <div class="modal-container share-modal">
                <div class="modal-header">
                    <h2><i class="fas fa-share-alt"></i> Compartilhar</h2>
                    <button class="modal-close" @click="closeShareModal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="modal-body">
                    <!-- Share Tabs -->
                    <div class="share-tabs">
                        <button 
                            class="share-tab" 
                            :class="{ active: activeShareTab === 'link' }"
                            @click="activeShareTab = 'link'"
                        >
                            <i class="fas fa-link"></i> Link
                        </button>
                        <button 
                            class="share-tab" 
                            :class="{ active: activeShareTab === 'social' }"
                            @click="activeShareTab = 'social'"
                        >
                            <i class="fas fa-share-nodes"></i> Redes
                        </button>
                        <button 
                            class="share-tab" 
                            :class="{ active: activeShareTab === 'embed' }"
                            @click="activeShareTab = 'embed'"
                        >
                            <i class="fas fa-code"></i> Embed
                        </button>
                        <button 
                            class="share-tab" 
                            :class="{ active: activeShareTab === 'bbcode' }"
                            @click="activeShareTab = 'bbcode'"
                        >
                            <i class="fas fa-brackets-curly"></i> BBCode
                        </button>
                    </div>

                    <!-- Link Tab -->
                    <div v-if="activeShareTab === 'link'" class="share-content">
                        <div class="share-field">
                            <label>Link direto</label>
                            <div class="input-copy-group">
                                <input type="text" :value="albumUrl" readonly />
                                <button 
                                    class="copy-btn" 
                                    @click="copyToClipboard(albumUrl, 'link')"
                                    :class="{ 'copied': copiedField === 'link' }"
                                >
                                    <i :class="copiedField === 'link' ? 'fas fa-check' : 'fas fa-copy'"></i>
                                    {{ copiedField === 'link' ? 'Copiado!' : 'Copiar' }}
                                </button>
                            </div>
                        </div>
                        
                        <!-- QR Code placeholder -->
                        <div class="qr-placeholder">
                            <i class="fas fa-qrcode"></i>
                            <span>QR Code disponível em breve</span>
                        </div>
                    </div>

                    <!-- Social Tab -->
                    <div v-if="activeShareTab === 'social'" class="share-content">
                        <div class="social-buttons">
                            <button class="social-btn twitter" @click="shareTwitter">
                                <i class="fab fa-twitter"></i> Twitter / X
                            </button>
                            <button class="social-btn facebook" @click="shareFacebook">
                                <i class="fab fa-facebook-f"></i> Facebook
                            </button>
                            <button class="social-btn reddit" @click="shareReddit">
                                <i class="fab fa-reddit-alien"></i> Reddit
                            </button>
                            <button class="social-btn telegram" @click="shareTelegram">
                                <i class="fab fa-telegram-plane"></i> Telegram
                            </button>
                            <button class="social-btn whatsapp" @click="shareWhatsApp">
                                <i class="fab fa-whatsapp"></i> WhatsApp
                            </button>
                            <button class="social-btn email" @click="shareEmail">
                                <i class="fas fa-envelope"></i> Email
                            </button>
                        </div>
                        
                        <button v-if="navigator.share" class="native-share-btn" @click="shareNative">
                            <i class="fas fa-share"></i> Compartilhar via sistema
                        </button>
                    </div>

                    <!-- Embed Tab -->
                    <div v-if="activeShareTab === 'embed'" class="share-content">
                        <div class="share-field">
                            <label>Código iFrame (para sites)</label>
                            <div class="input-copy-group">
                                <textarea readonly rows="3">{{ embedIframe }}</textarea>
                                <button 
                                    class="copy-btn" 
                                    @click="copyToClipboard(embedIframe, 'iframe')"
                                    :class="{ 'copied': copiedField === 'iframe' }"
                                >
                                    <i :class="copiedField === 'iframe' ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="share-field">
                            <label>HTML com imagem</label>
                            <div class="input-copy-group">
                                <textarea readonly rows="2">{{ embedHtml }}</textarea>
                                <button 
                                    class="copy-btn" 
                                    @click="copyToClipboard(embedHtml, 'html')"
                                    :class="{ 'copied': copiedField === 'html' }"
                                >
                                    <i :class="copiedField === 'html' ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="share-field">
                            <label>Markdown</label>
                            <div class="input-copy-group">
                                <input type="text" :value="embedMarkdown" readonly />
                                <button 
                                    class="copy-btn" 
                                    @click="copyToClipboard(embedMarkdown, 'markdown')"
                                    :class="{ 'copied': copiedField === 'markdown' }"
                                >
                                    <i :class="copiedField === 'markdown' ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- BBCode Tab -->
                    <div v-if="activeShareTab === 'bbcode'" class="share-content">
                        <div class="share-field">
                            <label>BBCode com imagem (para fóruns)</label>
                            <div class="input-copy-group">
                                <textarea readonly rows="2">{{ embedBBCode }}</textarea>
                                <button 
                                    class="copy-btn" 
                                    @click="copyToClipboard(embedBBCode, 'bbcode')"
                                    :class="{ 'copied': copiedField === 'bbcode' }"
                                >
                                    <i :class="copiedField === 'bbcode' ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="share-field">
                            <label>BBCode apenas texto</label>
                            <div class="input-copy-group">
                                <input type="text" :value="embedBBCodeText" readonly />
                                <button 
                                    class="copy-btn" 
                                    @click="copyToClipboard(embedBBCodeText, 'bbcodetext')"
                                    :class="{ 'copied': copiedField === 'bbcodetext' }"
                                >
                                    <i :class="copiedField === 'bbcodetext' ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bbcode-preview">
                            <h4>Prévia:</h4>
                            <div class="preview-box">
                                <a :href="albumUrl" target="_blank">
                                    <img v-if="albumThumbnail" :src="albumThumbnail" :alt="albumTitle" class="preview-thumb" />
                                    <span v-else>{{ albumTitle }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="closeShareModal">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.album-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Header */
.album-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 20px;
}

.album-title {
    font-size: 1.8rem;
    margin-bottom: 8px;
    color: var(--text-primary);
}

.album-meta {
    display: flex;
    gap: 16px;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.meta-item i {
    margin-right: 6px;
    color: var(--primary);
}

.author-info {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--dark-card);
    padding: 10px 16px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
}

.author-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.author-label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-muted);
}

.author-name {
    color: var(--text-primary);
    text-decoration: none;
    font-weight: 600;
}

.author-name:hover {
    color: var(--primary);
}

/* Video Section */
.video-section {
    margin-bottom: 40px;
}

/* Videos Grid - Multiple Players Side by Side */
.videos-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

/* Single video takes full width */
.videos-grid.single-video {
    grid-template-columns: 1fr;
}

/* Two videos side by side */
.videos-grid.two-videos {
    grid-template-columns: repeat(2, 1fr);
}

/* Individual video item in grid */
.video-grid-item {
    position: relative;
    background: #000;
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.video-grid-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.5);
}

.video-grid-item .video-number {
    position: absolute;
    top: 12px;
    left: 12px;
    background: linear-gradient(135deg, var(--primary) 0%, #ff6b9d 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    z-index: 15;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

/* Legacy main-video-wrapper (for compatibility) */
.main-video-wrapper {
    position: relative;
    width: 100%;
    background: #000;
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
}

/* Video Thumbnails Grid (legacy) */
.video-thumbnails-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 16px;
}

.video-thumb-item {
    position: relative;
    aspect-ratio: 16/9;
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    background: #111;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}

.video-thumb-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
    border-color: var(--primary);
}

.video-thumb-item.active {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(255, 62, 143, 0.3);
}

.video-thumb-item img,
.video-thumb-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.video-thumb-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.3);
    opacity: 1;
    transition: all 0.3s ease;
}

.video-thumb-item:hover .video-thumb-overlay {
    background: rgba(0,0,0,0.5);
}

.video-thumb-overlay i {
    font-size: 2rem;
    color: white;
    text-shadow: 0 2px 8px rgba(0,0,0,0.5);
    transition: transform 0.2s;
}

.video-thumb-item:hover .video-thumb-overlay i {
    transform: scale(1.2);
}

.video-number {
    position: absolute;
    top: 8px;
    left: 8px;
    background: var(--primary);
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    z-index: 5;
}

.delete-media-btn.small {
    width: 26px;
    height: 26px;
    font-size: 0.7rem;
}

/* Video wrapper (legacy support) */
.video-wrapper {
    position: relative;
    width: 100%;
    background: #000;
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.4);
}

.video-wrapper video {
    width: 100%;
    max-height: 80vh;
    display: block;
}

/* Photos Grid */
.photos-section {
    margin-bottom: 40px;
}

.section-title {
    font-size: 1.4rem;
    margin-bottom: 20px;
    border-left: 4px solid var(--primary);
    padding-left: 12px;
}

.photos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}

.photo-item {
    position: relative;
    aspect-ratio: 1; /* Square thumbnails */
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    background: var(--dark-card);
}

.photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.photo-item:hover img {
    transform: scale(1.05);
}

.zoom-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
}

.photo-item:hover .zoom-overlay {
    opacity: 1;
}

.zoom-overlay i {
    font-size: 2rem;
    color: white;
}

/* Footer & Actions */
.album-footer {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 30px;
    background: var(--dark-card);
    padding: 20px;
    border-radius: var(--radius-lg);
}

.tags-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.tag {
    background: rgba(255,255,255,0.1);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    color: var(--text-secondary);
    transition: background 0.2s;
}

.tag:hover {
    background: var(--primary);
    color: white;
}

.action-buttons {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: opacity 0.2s;
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
}

.album-description {
    background: var(--dark-bg);
    padding: 20px;
    border-radius: var(--radius-lg);
    margin-bottom: 30px;
    color: var(--text-secondary);
    line-height: 1.6;
}

.divider {
    border: 0;
    border-top: 1px solid var(--border);
    margin: 40px 0;
}

/* Related Grid overrides */
.related-grid {
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
}

/* Lightbox */
.lightbox {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.95);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.lightbox-content {
    max-width: 90vw;
    max-height: 90vh;
    position: relative;
}

.lightbox-img {
    max-width: 100%;
    max-height: 90vh;
    object-fit: contain;
    box-shadow: 0 0 20px rgba(0,0,0,0.5);
}

.lightbox-counter {
    position: absolute;
    bottom: -30px;
    left: 50%;
    transform: translateX(-50%);
    color: white;
    font-size: 0.9rem;
}

.close-btn {
    position: absolute;
    top: 20px;
    right: 20px;
    background: transparent;
    border: none;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    z-index: 1001;
}

.nav-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255,255,255,0.1);
    border: none;
    color: white;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    transition: background 0.2s;
}

.nav-btn:hover {
    background: rgba(255,255,255,0.2);
}

.prev { left: 20px; }
.next { right: 20px; }

@media (max-width: 768px) {
    .album-header {
        flex-direction: column;
    }
    
    .author-info {
        width: 100%;
    }
    
    .album-footer {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .action-buttons {
        width: 100%;
        justify-content: space-between;
    }
    
    /* Video Thumbnails Mobile */
    .video-thumbnails-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    
    .video-thumb-overlay i {
        font-size: 1.5rem;
    }
    
    .video-number {
        padding: 3px 8px;
        font-size: 0.65rem;
    }
    
    .main-video-wrapper {
        border-radius: var(--radius-md);
    }
    
    /* Videos Grid Mobile - Stack vertically */
    .videos-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .videos-grid.two-videos {
        grid-template-columns: 1fr;
    }
    
    .video-grid-item {
        border-radius: var(--radius-md);
    }
    
    .video-grid-item .video-number {
        padding: 4px 10px;
        font-size: 0.7rem;
        top: 8px;
        left: 8px;
    }
}

/* Owner Controls */
.owner-controls {
    display: flex;
    gap: 10px;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

/* Delete Media Button */
.delete-media-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 10;
    background: rgba(220, 53, 69, 0.9);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s, background 0.2s;
}

.video-wrapper:hover .delete-media-btn,
.main-video-wrapper:hover .delete-media-btn,
.video-grid-item:hover .delete-media-btn,
.video-thumb-item:hover .delete-media-btn,
.photo-item:hover .delete-media-btn {
    opacity: 1;
}

.delete-media-btn:hover {
    background: #c82333;
}

.video-wrapper {
    position: relative;
}

/* Like Button Styles */
.btn-liked {
    background: linear-gradient(135deg, #ff3e8f 0%, #ff6b6b 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(255, 62, 143, 0.4);
}

.btn-liked:hover {
    background: linear-gradient(135deg, #ff6b9d 0%, #ff8080 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(255, 62, 143, 0.5);
}

.btn-liked i {
    animation: heartPulse 0.3s ease-out;
}

@keyframes heartPulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.3); }
    100% { transform: scale(1); }
}

.like-count {
    margin-left: 4px;
    font-size: 0.85em;
    opacity: 0.9;
}

/* Warning Button for Report */
.btn-warning {
    background: rgba(255, 193, 7, 0.2);
    color: #ffc107;
    border: 1px solid rgba(255, 193, 7, 0.3);
}

.btn-warning:hover {
    background: rgba(255, 193, 7, 0.3);
    border-color: #ffc107;
}

/* Report Modal Styles */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(5px);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-container {
    background: var(--dark-card, #1a1a2e);
    border-radius: var(--radius-lg, 16px);
    border: 1px solid var(--border, #333);
    max-width: 500px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border, #333);
}

.modal-header h2 {
    font-size: 1.25rem;
    color: var(--text-primary, #fff);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.modal-header h2 i {
    color: #ffc107;
}

.modal-close {
    background: transparent;
    border: none;
    color: var(--text-secondary, #888);
    font-size: 1.2rem;
    cursor: pointer;
    padding: 5px;
    transition: color 0.2s;
}

.modal-close:hover {
    color: var(--text-primary, #fff);
}

.modal-body {
    padding: 24px;
}

.modal-subtitle {
    color: var(--text-secondary, #aaa);
    margin-bottom: 20px;
    font-size: 0.95rem;
}

.report-reasons {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 20px;
}

.report-reason-option {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border, #333);
    border-radius: var(--radius-md, 10px);
    cursor: pointer;
    transition: all 0.2s;
}

.report-reason-option:hover {
    background: rgba(255, 255, 255, 0.06);
    border-color: rgba(255, 255, 255, 0.15);
}

.report-reason-option.selected {
    background: rgba(255, 62, 143, 0.1);
    border-color: var(--primary, #ff3e8f);
}

.report-reason-option input {
    display: none;
}

.reason-radio {
    width: 20px;
    height: 20px;
    border: 2px solid var(--text-muted, #666);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.report-reason-option.selected .reason-radio {
    border-color: var(--primary, #ff3e8f);
}

.report-reason-option.selected .reason-radio::after {
    content: '';
    width: 10px;
    height: 10px;
    background: var(--primary, #ff3e8f);
    border-radius: 50%;
}

.reason-text {
    color: var(--text-primary, #fff);
    font-size: 0.95rem;
}

.report-description {
    position: relative;
}

.report-description label {
    display: block;
    color: var(--text-secondary, #aaa);
    margin-bottom: 8px;
    font-size: 0.9rem;
}

.report-description textarea {
    width: 100%;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border, #333);
    border-radius: var(--radius-md, 10px);
    padding: 12px;
    color: var(--text-primary, #fff);
    font-size: 0.95rem;
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
}

.report-description textarea:focus {
    outline: none;
    border-color: var(--primary, #ff3e8f);
}

.report-description textarea::placeholder {
    color: var(--text-muted, #666);
}

.char-count {
    position: absolute;
    right: 10px;
    bottom: 10px;
    font-size: 0.75rem;
    color: var(--text-muted, #666);
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--border, #333);
}

.modal-footer .btn {
    padding: 10px 20px;
}

.modal-footer .btn i {
    margin-right: 6px;
}

/* ============================================================================
   SHARE MODAL STYLES
   ============================================================================ */
.share-modal {
    max-width: 600px;
}

.share-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--border, #333);
    padding-bottom: 12px;
}

.share-tab {
    flex: 1;
    padding: 10px 16px;
    background: transparent;
    border: 1px solid var(--border, #333);
    border-radius: var(--radius-md, 10px);
    color: var(--text-secondary, #888);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 0.85rem;
}

.share-tab:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.15);
}

.share-tab.active {
    background: var(--primary, #ff3e8f);
    border-color: var(--primary, #ff3e8f);
    color: white;
}

.share-content {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}

.share-field {
    margin-bottom: 16px;
}

.share-field label {
    display: block;
    color: var(--text-secondary, #aaa);
    margin-bottom: 8px;
    font-size: 0.85rem;
}

.input-copy-group {
    display: flex;
    gap: 8px;
}

.input-copy-group input,
.input-copy-group textarea {
    flex: 1;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border, #333);
    border-radius: var(--radius-md, 10px);
    padding: 10px 14px;
    color: var(--text-primary, #fff);
    font-size: 0.9rem;
    font-family: monospace;
}

.input-copy-group textarea {
    resize: none;
}

.copy-btn {
    padding: 10px 16px;
    background: var(--primary, #ff3e8f);
    border: none;
    border-radius: var(--radius-md, 10px);
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    white-space: nowrap;
}

.copy-btn:hover {
    background: #ff5a9f;
    transform: scale(1.02);
}

.copy-btn.copied {
    background: #28a745;
}

.qr-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 30px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px dashed var(--border, #333);
    border-radius: var(--radius-md, 10px);
    color: var(--text-muted, #666);
    gap: 10px;
}

.qr-placeholder i {
    font-size: 2rem;
}

/* Social Buttons */
.social-buttons {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}

.social-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 16px;
    border: none;
    border-radius: var(--radius-md, 10px);
    color: white;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
}

.social-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.social-btn.twitter {
    background: linear-gradient(135deg, #1da1f2 0%, #0d8ed9 100%);
}

.social-btn.facebook {
    background: linear-gradient(135deg, #4267b2 0%, #3b5998 100%);
}

.social-btn.reddit {
    background: linear-gradient(135deg, #ff4500 0%, #e04000 100%);
}

.social-btn.telegram {
    background: linear-gradient(135deg, #0088cc 0%, #0077b5 100%);
}

.social-btn.whatsapp {
    background: linear-gradient(135deg, #25d366 0%, #20bd5a 100%);
}

.social-btn.email {
    background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
}

.native-share-btn {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary, #ff3e8f) 0%, #ff6b6b 100%);
    border: none;
    border-radius: var(--radius-md, 10px);
    color: white;
    cursor: pointer;
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.native-share-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(255, 62, 143, 0.4);
}

/* BBCode Preview */
.bbcode-preview {
    margin-top: 20px;
    padding: 16px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border, #333);
    border-radius: var(--radius-md, 10px);
}

.bbcode-preview h4 {
    margin: 0 0 12px 0;
    color: var(--text-secondary, #aaa);
    font-size: 0.85rem;
}

.preview-box {
    display: flex;
    justify-content: center;
}

.preview-thumb {
    max-width: 200px;
    max-height: 150px;
    border-radius: var(--radius-sm, 8px);
    object-fit: cover;
}

/* Responsive for share modal */
@media (max-width: 600px) {
    .share-tabs {
        flex-wrap: wrap;
    }
    
    .share-tab {
        flex: 1 1 45%;
    }
    
    .social-buttons {
        grid-template-columns: 1fr;
    }
}
</style>

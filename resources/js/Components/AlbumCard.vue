<script setup>
import { ref, onBeforeUnmount, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    album: {
        type: Object,
        required: true,
    },
});

const isHovering = ref(false);
const videoRef = ref(null);
const videoPreviewRef = ref(null);
const videoError = ref(false);
const imageError = ref(false);
const isVideoReady = ref(false);

let hoverTimeout = null;
let playPromise = null;

// Preload video metadata on mount for better UX
onMounted(() => {
    // If there's a preview URL, preload its metadata after a delay
    if (props.album.preview_url && !props.album.thumb_is_video) {
        setTimeout(() => {
            if (videoPreviewRef.value) {
                videoPreviewRef.value.preload = 'metadata';
            }
        }, 1000);
    }
});

const handleMouseEnter = () => {
    const targetVideo = props.album.thumb_is_video ? videoRef.value : videoPreviewRef.value;
    
    if (!targetVideo || videoError.value) return;
    
    // Clear any existing timeout
    if (hoverTimeout) {
        clearTimeout(hoverTimeout);
    }
    
    hoverTimeout = setTimeout(async () => {
        isHovering.value = true;
        
        try {
            // Reset video to start
            targetVideo.currentTime = 0;
            
            // Start playing
            playPromise = targetVideo.play();
            
            if (playPromise !== undefined) {
                await playPromise;
                isVideoReady.value = true;
            }
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.warn('Video play failed:', e.message);
                videoError.value = true;
            }
            isHovering.value = false;
        }
    }, 200); // Reduced delay for faster response
};

const handleMouseLeave = () => {
    // Cancel pending hover immediately
    if (hoverTimeout) {
        clearTimeout(hoverTimeout);
        hoverTimeout = null;
    }
    
    isHovering.value = false;
    isVideoReady.value = false;
    
    const targetVideo = props.album.thumb_is_video ? videoRef.value : videoPreviewRef.value;
    
    if (targetVideo) {
        // Don't await - just pause immediately
        try {
            targetVideo.pause();
            targetVideo.currentTime = 0;
        } catch (e) {
            // Ignore errors during pause
        }
    }
    
    playPromise = null;
};

const handleImageError = (event) => {
    if (imageError.value) return;
    imageError.value = true;
    event.target.src = '/assets/images/placeholder.jpg';
};

const handleVideoError = () => {
    videoError.value = true;
};

const formatNumber = (num) => {
    if (!num) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
};

// Get safe thumbnail URL
const getThumbnail = () => {
    if (!props.album.thumbnail) return '/assets/images/placeholder.jpg';
    return props.album.thumbnail;
};

// Get safe preview URL  
const getPreviewUrl = () => {
    if (!props.album.preview_url) return null;
    return props.album.preview_url;
};

onBeforeUnmount(() => {
    if (hoverTimeout) {
        clearTimeout(hoverTimeout);
    }
});
</script>

<template>
    <Link :href="album.slug ? `/album/${album.album_id}/${album.slug}` : `/album/${album.album_id}`" class="album-card">
        <div 
            class="album-thumbnail"
            @mouseenter="handleMouseEnter"
            @mouseleave="handleMouseLeave"
        >
            <!-- Video as Thumbnail (when no static image available) -->
            <video
                v-if="album.thumb_is_video && !videoError"
                ref="videoRef"
                :src="getThumbnail() + '#t=0.1'"
                class="static-thumb"
                muted
                loop
                playsinline
                preload="metadata"
                @error="handleVideoError"
            ></video>

            <!-- Static Image Thumbnail -->
            <img 
                v-if="!album.thumb_is_video || videoError"
                v-show="!isHovering || videoError || !getPreviewUrl()"
                :src="getThumbnail()" 
                :alt="album.title" 
                loading="lazy"
                class="static-thumb"
                @error="handleImageError"
            />
            
            <!-- Video Preview on Hover -->
            <video
                v-if="!album.thumb_is_video && getPreviewUrl() && !videoError"
                ref="videoPreviewRef"
                :src="getPreviewUrl()"
                v-show="isHovering"
                class="video-preview"
                muted
                loop
                playsinline
                preload="none"
                @error="handleVideoError"
            ></video>
            
            <!-- Placeholder when everything fails -->
            <div v-if="imageError && videoError" class="placeholder-fallback">
                <i class="fas fa-image"></i>
            </div>
            
            <!-- Media Count Badge -->
            <span v-if="album.photo_count > 0 || album.video_count > 0" class="album-media-count">
                <template v-if="album.photo_count > 0">
                    <i class="fas fa-image"></i> {{ album.photo_count }}
                </template>
                <template v-if="album.video_count > 0">
                    <i class="fas fa-video"></i> {{ album.video_count }}
                </template>
            </span>
            
            <!-- Loading Indicator -->
            <div v-if="isHovering && !isVideoReady && !videoError" class="loading-overlay">
                <div class="loader"></div>
            </div>
        </div>
        
        <div class="album-info">
            <h3 class="album-title" :title="album.title">{{ album.title || 'Sem título' }}</h3>
            <div class="album-meta">
                <span class="album-author">
                    <img 
                        :src="album.author_avatar || '/assets/images/default-avatar.jpg'" 
                        alt="" 
                        loading="lazy"
                        @error="$event.target.src = '/assets/images/default-avatar.jpg'"
                    />
                    {{ album.author || 'Anônimo' }}
                </span>
                <div class="album-stats">
                    <span><i class="fas fa-eye"></i> {{ formatNumber(album.views) }}</span>
                    <span><i class="fas fa-heart"></i> {{ formatNumber(album.likes) }}</span>
                </div>
            </div>
        </div>
    </Link>
</template>

<style scoped>
.album-card {
    text-decoration: none;
    color: inherit;
    display: block;
    background: var(--dark-card);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid var(--border);
    will-change: transform;
}

.album-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary);
}

.album-thumbnail {
    position: relative;
    aspect-ratio: 16/9;
    background: #111;
    overflow: hidden;
}

.static-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.video-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    position: absolute;
    top: 0;
    left: 0;
    z-index: 2;
}

.placeholder-fallback {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #222;
    color: #555;
    font-size: 2rem;
}

.album-media-count {
    position: absolute;
    top: 8px;
    left: 8px;
    background: var(--primary);
    color: white;
    padding: 4px 8px;
    border-radius: 99px;
    font-size: 0.7rem;
    font-weight: 600;
    display: flex;
    gap: 6px;
    align-items: center;
    z-index: 5;
}

.album-info {
    padding: 12px;
}

.album-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.album-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.album-author {
    display: flex;
    align-items: center;
    gap: 6px;
    max-width: 50%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.album-author img {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.album-stats {
    display: flex;
    gap: 12px;
}

.album-stats span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.loading-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    pointer-events: none;
}

.loader {
    width: 24px;
    height: 24px;
    border: 3px solid rgba(255,255,255,0.3);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

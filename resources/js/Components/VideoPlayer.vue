<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    src: {
        type: String,
        required: true,
    },
    poster: {
        type: String,
        default: '',
    },
    autoplay: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['play', 'pause', 'ended', 'timeupdate', 'fullscreen-change']);

// Refs
const videoRef = ref(null);
const containerRef = ref(null);
const progressRef = ref(null);
const volumeRef = ref(null);

// State
const isPlaying = ref(false);
const isMuted = ref(false);
const isFullscreen = ref(false);
const isLoading = ref(true);
const showControls = ref(true);
const currentTime = ref(0);
const duration = ref(0);
const buffered = ref(0);
const volume = ref(1);
const playbackRate = ref(1);
const isHovering = ref(false);
const showSpeedMenu = ref(false);
const showQualityMenu = ref(false);
const isDraggingProgress = ref(false);
const isDraggingVolume = ref(false);
const error = ref(null);
const showVolumeSlider = ref(false);

// Ad State
const showAdOverlay = ref(false);
const adCountdown = ref(5);
const adStarted = ref(false);
let adTimer = null;
const capturedPoster = ref(null); // Auto-captured thumbnail when no poster provided
const supportsPiP = ref(false); // Picture-in-Picture support

// Timeouts
let controlsTimeout = null;
let doubleClickTimer = null;
let clickCount = 0;

// Computed
const progressPercent = computed(() => {
    if (duration.value === 0) return 0;
    return (currentTime.value / duration.value) * 100;
});

const bufferedPercent = computed(() => {
    if (duration.value === 0) return 0;
    return (buffered.value / duration.value) * 100;
});

const formattedCurrentTime = computed(() => formatTime(currentTime.value));
const formattedDuration = computed(() => formatTime(duration.value));

const volumeIcon = computed(() => {
    if (isMuted.value || volume.value === 0) return 'fa-volume-mute';
    if (volume.value < 0.5) return 'fa-volume-down';
    return 'fa-volume-up';
});

// Effective poster - use captured one if no poster provided
const effectivePoster = computed(() => {
    if (props.poster && props.poster !== '' && typeof props.poster === 'string' && !props.poster.includes('undefined')) {
        return props.poster;
    }
    return capturedPoster.value || '';
});

// Methods
const formatTime = (seconds) => {
    if (isNaN(seconds) || seconds === 0) return '0:00';
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    
    if (hrs > 0) {
        return `${hrs}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const startAd = () => {
    adStarted.value = true;
    showAdOverlay.value = true;
    adCountdown.value = 5;
    
    // Automatic redirects removed as per user request

    adTimer = setInterval(() => {
        adCountdown.value--;
        if (adCountdown.value <= 0) {
            skipAd();
        }
    }, 1000);
};

const skipAd = () => {
    if (adTimer) {
        clearInterval(adTimer);
        adTimer = null;
    }
    showAdOverlay.value = false;
    if (videoRef.value) {
        videoRef.value.play().catch(() => {});
    }
};

const togglePlay = () => {
    if (!videoRef.value) return;
    
    // Show ad on first play
    if (!adStarted.value && !isPlaying.value) {
        startAd();
        return;
    }

    if (isPlaying.value) {
        videoRef.value.pause();
    } else {
        videoRef.value.play().catch(e => {
            console.error('Play failed:', e);
            error.value = 'Não foi possível reproduzir o vídeo';
        });
    }
};

const toggleMute = () => {
    if (!videoRef.value) return;
    isMuted.value = !isMuted.value;
    videoRef.value.muted = isMuted.value;
};

const toggleFullscreen = async () => {
    if (!containerRef.value || !videoRef.value) return;
    
    try {
        // More robust fullscreen detection (including webkit/mobile)
        const isCurrentlyFullscreen = document.fullscreenElement || 
                                     document.webkitFullscreenElement || 
                                     document.mozFullScreenElement || 
                                     document.msFullscreenElement;

        if (!isCurrentlyFullscreen) {
            // Check for iOS/safari mobile specifically which often only supports fullscreen on video element
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            
            if (isIOS && videoRef.value.webkitEnterFullscreen) {
                videoRef.value.webkitEnterFullscreen();
            } else if (containerRef.value.requestFullscreen) {
                await containerRef.value.requestFullscreen();
            } else if (containerRef.value.webkitRequestFullscreen) {
                await containerRef.value.webkitRequestFullscreen();
            } else if (videoRef.value.webkitEnterFullscreen) {
                // Secondary fallback to video element
                videoRef.value.webkitEnterFullscreen();
            }
            isFullscreen.value = true;
        } else {
            if (document.exitFullscreen) {
                await document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                await document.webkitExitFullscreen();
            } else if (document.mozCancelFullScreen) {
                await document.mozCancelFullScreen();
            } else if (document.msExitFullscreen) {
                await document.msExitFullscreen();
            }
            isFullscreen.value = false;
        }
        emit('fullscreen-change', isFullscreen.value);
    } catch (e) {
        console.error('Fullscreen error:', e);
        // Final desperate attempt on the video element itself
        try {
            if (videoRef.value.webkitEnterFullscreen) {
                videoRef.value.webkitEnterFullscreen();
            }
        } catch (err) {}
    }
};

const setVolume = (e) => {
    if (!videoRef.value) return;
    const rect = volumeRef.value.getBoundingClientRect();
    let percent = (e.clientX - rect.left) / rect.width;
    percent = Math.max(0, Math.min(1, percent));
    volume.value = percent;
    videoRef.value.volume = percent;
    isMuted.value = percent === 0;
    videoRef.value.muted = percent === 0;
};

const seekTo = (e) => {
    if (!videoRef.value || !progressRef.value) return;
    const rect = progressRef.value.getBoundingClientRect();
    let percent = (e.clientX - rect.left) / rect.width;
    percent = Math.max(0, Math.min(1, percent));
    videoRef.value.currentTime = percent * duration.value;
};

const setPlaybackRate = (rate) => {
    if (!videoRef.value) return;
    playbackRate.value = rate;
    videoRef.value.playbackRate = rate;
    showSpeedMenu.value = false;
};

const skip = (seconds) => {
    if (!videoRef.value) return;
    videoRef.value.currentTime = Math.max(0, Math.min(duration.value, currentTime.value + seconds));
};

// Event Handlers
const onPlay = () => {
    isPlaying.value = true;
    emit('play');
};

const onPause = () => {
    isPlaying.value = false;
    emit('pause');
};

const onEnded = () => {
    isPlaying.value = false;
    emit('ended');
};

const onTimeUpdate = () => {
    if (!videoRef.value) return;
    currentTime.value = videoRef.value.currentTime;
    emit('timeupdate', currentTime.value);
};

const onLoadedMetadata = () => {
    if (!videoRef.value) return;
    duration.value = videoRef.value.duration;
    isLoading.value = false;
    
    // Auto-capture thumbnail if no poster provided
    if (!props.poster || props.poster === '' || props.poster.includes('undefined')) {
        captureThumbnailFromVideo();
    }
};

// Auto-capture thumbnail from video when no poster is provided
const captureThumbnailFromVideo = async () => {
    if (!videoRef.value || capturedPoster.value) return;
    
    try {
        const video = videoRef.value;
        
        // Wait for video to have enough data
        if (video.readyState < 2) {
            await new Promise(resolve => {
                const onCanPlay = () => {
                    video.removeEventListener('canplay', onCanPlay);
                    resolve();
                };
                video.addEventListener('canplay', onCanPlay);
                setTimeout(resolve, 3000); // Timeout fallback
            });
        }
        
        // Seek to 2 seconds or 10% of the video, whichever is smaller
        const seekTime = Math.min(2, video.duration * 0.1);
        const originalTime = video.currentTime;
        video.currentTime = seekTime;
        
        // Wait for seek to complete
        await new Promise(resolve => {
            const onSeeked = () => {
                video.removeEventListener('seeked', onSeeked);
                resolve();
            };
            video.addEventListener('seeked', onSeeked);
            setTimeout(resolve, 2000); // Timeout fallback
        });
        
        // Capture the frame to canvas
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 360;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Convert to data URL and store
        capturedPoster.value = canvas.toDataURL('image/jpeg', 0.85);
        
        // Reset video to original position (usually 0)
        video.currentTime = originalTime;
    } catch (e) {
        console.warn('Failed to capture video thumbnail:', e);
    }
};

const onProgress = () => {
    if (!videoRef.value) return;
    const buf = videoRef.value.buffered;
    if (buf.length > 0) {
        buffered.value = buf.end(buf.length - 1);
    }
};

const onWaiting = () => {
    isLoading.value = true;
};

const onCanPlay = () => {
    isLoading.value = false;
};

const onError = (e) => {
    console.error('Video error:', e);
    error.value = 'Erro ao carregar o vídeo';
    isLoading.value = false;
};

const onVolumeChange = () => {
    if (!videoRef.value) return;
    volume.value = videoRef.value.volume;
    isMuted.value = videoRef.value.muted;
};

// Controls visibility
const showControlsTemporarily = () => {
    showControls.value = true;
    if (controlsTimeout) clearTimeout(controlsTimeout);
    if (isPlaying.value) {
        controlsTimeout = setTimeout(() => {
            if (isPlaying.value && !isHovering.value) {
                showControls.value = false;
            }
        }, 3000);
    }
};

const onMouseMove = () => {
    showControlsTemporarily();
};

const onMouseEnter = () => {
    isHovering.value = true;
    showControls.value = true;
};

const onMouseLeave = () => {
    isHovering.value = false;
    if (isPlaying.value) {
        controlsTimeout = setTimeout(() => {
            showControls.value = false;
        }, 2000);
    }
};

// Double click for fullscreen
const handleVideoClick = (e) => {
    clickCount++;
    
    if (clickCount === 1) {
        doubleClickTimer = setTimeout(() => {
            if (clickCount === 1) {
                togglePlay();
            }
            clickCount = 0;
        }, 250);
    } else if (clickCount === 2) {
        clearTimeout(doubleClickTimer);
        toggleFullscreen();
        clickCount = 0;
    }
};

// Drag handlers for progress
const startProgressDrag = (e) => {
    isDraggingProgress.value = true;
    seekTo(e);
    document.addEventListener('mousemove', onProgressDrag);
    document.addEventListener('mouseup', stopProgressDrag);
};

const onProgressDrag = (e) => {
    if (isDraggingProgress.value) {
        seekTo(e);
    }
};

const stopProgressDrag = () => {
    isDraggingProgress.value = false;
    document.removeEventListener('mousemove', onProgressDrag);
    document.removeEventListener('mouseup', stopProgressDrag);
};

// Drag handlers for volume
const startVolumeDrag = (e) => {
    isDraggingVolume.value = true;
    setVolume(e);
    document.addEventListener('mousemove', onVolumeDrag);
    document.addEventListener('mouseup', stopVolumeDrag);
};

const onVolumeDrag = (e) => {
    if (isDraggingVolume.value) {
        setVolume(e);
    }
};

const stopVolumeDrag = () => {
    isDraggingVolume.value = false;
    document.removeEventListener('mousemove', onVolumeDrag);
    document.removeEventListener('mouseup', stopVolumeDrag);
};

// Keyboard controls
const handleKeydown = (e) => {
    if (!containerRef.value?.contains(document.activeElement) && document.activeElement !== document.body) return;
    
    switch (e.key) {
        case ' ':
        case 'k':
            e.preventDefault();
            togglePlay();
            break;
        case 'ArrowLeft':
            e.preventDefault();
            skip(-10);
            break;
        case 'ArrowRight':
            e.preventDefault();
            skip(10);
            break;
        case 'ArrowUp':
            e.preventDefault();
            if (!isMuted.value) {
                volume.value = Math.min(1, volume.value + 0.1);
                if (videoRef.value) videoRef.value.volume = volume.value;
            }
            break;
        case 'ArrowDown':
            e.preventDefault();
            volume.value = Math.max(0, volume.value - 0.1);
            if (videoRef.value) videoRef.value.volume = volume.value;
            break;
        case 'm':
            e.preventDefault();
            toggleMute();
            break;
        case 'f':
            e.preventDefault();
            toggleFullscreen();
            break;
        case 'j':
            e.preventDefault();
            skip(-10);
            break;
        case 'l':
            e.preventDefault();
            skip(10);
            break;
    }
    showControlsTemporarily();
};

// Picture in Picture
const togglePiP = async () => {
    if (!document.pictureInPictureEnabled) return;
    
    try {
        if (document.pictureInPictureElement) {
            await document.exitPictureInPicture();
        } else if (videoRef.value) {
            await videoRef.value.requestPictureInPicture();
        }
    } catch (e) {
        console.error('PiP error:', e);
    }
};

// Fullscreen change listener
const onFullscreenChange = () => {
    isFullscreen.value = !!(document.fullscreenElement || 
                           document.webkitFullscreenElement || 
                           document.mozFullScreenElement || 
                           document.msFullscreenElement);
};

// Lifecycle
onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
    document.addEventListener('mozfullscreenchange', onFullscreenChange);
    document.addEventListener('MSFullscreenChange', onFullscreenChange);

    // iOS specific video element fullscreen events
    if (videoRef.value) {
        videoRef.value.addEventListener('webkitbeginfullscreen', () => { isFullscreen.value = true; });
        videoRef.value.addEventListener('webkitendfullscreen', () => { isFullscreen.value = false; });
    }
    
    // Check PiP support safely
    supportsPiP.value = typeof document !== 'undefined' && 'pictureInPictureEnabled' in document && document.pictureInPictureEnabled;
    
    if (props.autoplay && videoRef.value) {
        videoRef.value.play().catch(() => {});
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    document.removeEventListener('mozfullscreenchange', onFullscreenChange);
    document.removeEventListener('MSFullscreenChange', onFullscreenChange);
    if (controlsTimeout) clearTimeout(controlsTimeout);
    if (doubleClickTimer) clearTimeout(doubleClickTimer);
    if (adTimer) clearInterval(adTimer);
});

// Watch source changes
watch(() => props.src, () => {
    if (videoRef.value) {
        videoRef.value.load();
        isLoading.value = true;
        error.value = null;
    }
});
</script>

<template>
    <div 
        ref="containerRef"
        class="video-player"
        :class="{ 'is-fullscreen': isFullscreen, 'show-controls': showControls, 'showing-ad': showAdOverlay }"
        @mousemove="onMouseMove"
        @mouseenter="onMouseEnter"
        @mouseleave="onMouseLeave"
        tabindex="0"
    >
        <!-- Video Element -->
        <video
            ref="videoRef"
            class="video-element"
            :src="src"
            :poster="effectivePoster"
            playsinline
            preload="metadata"
            @play="onPlay"
            @pause="onPause"
            @ended="onEnded"
            @timeupdate="onTimeUpdate"
            @loadedmetadata="onLoadedMetadata"
            @progress="onProgress"
            @waiting="onWaiting"
            @canplay="onCanPlay"
            @error="onError"
            @volumechange="onVolumeChange"
            @click="handleVideoClick"
        ></video>

        <!-- Ad Pre-roll Overlay -->
        <div v-if="showAdOverlay" class="ad-overlay">
            <div class="ad-card">
                <div class="ad-header">
                    <span class="ad-label">Anúncio</span>
                    <span class="ad-timer">Vídeo inicia em {{ adCountdown }}s</span>
                </div>
                <div class="ad-body" @click="window.open('https://ad.juicyads.com/network?zone=1109538', '_blank')">
                    <!-- Real JuicyAds Banner for zone 1109538 -->
                    <iframe
                        :srcdoc="`
                            <html>
                            <body style='margin:0; background: #222; display: flex; justify-content: center; align-items: center; height: 100vh;'>
                                <script type='text/javascript' data-cfasync='false' async src='https://poweredby.jads.co/js/jads.js'><\/script>
                                <ins id='1109538' data-width='308' data-height='286'></ins>
                                <script type='text/javascript' data-cfasync='false' async>(adsbyjuicy = window.adsbyjuicy || []).push({'adzone':1109538});<\/script>
                            </body>
                            </html>
                        `"
                        class="ad-iframe-box"
                        frameborder="0"
                        scrolling="no"
                    ></iframe>
                    <div class="ad-click-overlay"></div>
                </div>
                <div class="ad-footer">
                    <button 
                        class="skip-ad-btn" 
                        :disabled="adCountdown > 0"
                        @click="skipAd"
                    >
                        {{ adCountdown > 0 ? `Pular em ${adCountdown}s` : 'Pular Anúncio' }}
                        <i class="fas fa-forward ml-2" v-if="adCountdown <= 0"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading Spinner -->
        <div v-if="isLoading && !showAdOverlay" class="loading-overlay">
            <div class="spinner"></div>
        </div>

        <!-- Error Overlay -->
        <div v-if="error" class="error-overlay">
            <i class="fas fa-exclamation-triangle"></i>
            <p>{{ error }}</p>
            <button @click="error = null; videoRef?.load()">Tentar novamente</button>
        </div>


        <!-- Big Play Button (when paused) -->
        <div 
            v-if="!isPlaying && !isLoading && !error" 
            class="big-play-btn"
            @click="togglePlay"
        >
            <i class="fas fa-play"></i>
        </div>

        <!-- Controls -->
        <div class="controls" :class="{ 'visible': showControls }">
            <!-- Progress Bar -->
            <div 
                ref="progressRef"
                class="progress-container"
                @mousedown="startProgressDrag"
            >
                <div class="progress-buffer" :style="{ width: bufferedPercent + '%' }"></div>
                <div class="progress-played" :style="{ width: progressPercent + '%' }">
                    <div class="progress-handle"></div>
                </div>
            </div>

            <!-- Controls Bottom -->
            <div class="controls-bottom">
                <!-- Left Controls -->
                <div class="controls-left">
                    <!-- Play/Pause -->
                    <button class="control-btn" @click="togglePlay" :title="isPlaying ? 'Pausar (k)' : 'Reproduzir (k)'">
                        <i :class="['fas', isPlaying ? 'fa-pause' : 'fa-play']"></i>
                    </button>

                    <!-- Skip Buttons -->
                    <button class="control-btn" @click="skip(-10)" title="Voltar 10s (j)">
                        <i class="fas fa-undo-alt"></i>
                        <span class="skip-label">10</span>
                    </button>
                    <button class="control-btn" @click="skip(10)" title="Avançar 10s (l)">
                        <i class="fas fa-redo-alt"></i>
                        <span class="skip-label">10</span>
                    </button>

                    <!-- Volume -->
                    <div 
                        class="volume-container"
                        @mouseenter="showVolumeSlider = true"
                        @mouseleave="showVolumeSlider = false"
                    >
                        <button class="control-btn" @click="toggleMute" :title="isMuted ? 'Ativar som (m)' : 'Mudo (m)'">
                            <i :class="['fas', volumeIcon]"></i>
                        </button>
                        <div 
                            class="volume-slider-container"
                            :class="{ 'visible': showVolumeSlider }"
                        >
                            <div 
                                ref="volumeRef"
                                class="volume-slider"
                                @mousedown="startVolumeDrag"
                            >
                                <div class="volume-level" :style="{ width: (volume * 100) + '%' }">
                                    <div class="volume-handle"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time -->
                    <div class="time-display">
                        <span>{{ formattedCurrentTime }}</span>
                        <span class="time-separator">/</span>
                        <span>{{ formattedDuration }}</span>
                    </div>
                </div>

                <!-- Right Controls -->
                <div class="controls-right">
                    <!-- Playback Speed -->
                    <div class="dropdown-container">
                        <button 
                            class="control-btn speed-btn" 
                            @click="showSpeedMenu = !showSpeedMenu"
                            title="Velocidade"
                        >
                            {{ playbackRate }}x
                        </button>
                        <div class="dropdown-menu" :class="{ 'visible': showSpeedMenu }">
                            <button 
                                v-for="rate in [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]" 
                                :key="rate"
                                :class="{ 'active': playbackRate === rate }"
                                @click="setPlaybackRate(rate)"
                            >
                                {{ rate }}x
                            </button>
                        </div>
                    </div>

                    <!-- Picture in Picture -->
                    <button 
                        v-if="supportsPiP" 
                        class="control-btn" 
                        @click="togglePiP"
                        title="Picture-in-Picture"
                    >
                        <i class="fas fa-clone"></i>
                    </button>

                    <!-- Fullscreen -->
                    <button class="control-btn" @click="toggleFullscreen" :title="isFullscreen ? 'Sair da tela cheia (f)' : 'Tela cheia (f)'">
                        <i :class="['fas', isFullscreen ? 'fa-compress' : 'fa-expand']"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Title Overlay (Fullscreen) -->
        <div v-if="title && isFullscreen" class="title-overlay" :class="{ 'visible': showControls }">
            {{ title }}
        </div>
    </div>
</template>

<style scoped>
.video-player {
    position: relative;
    width: 100%;
    background: #000;
    border-radius: 12px;
    overflow: hidden;
    aspect-ratio: 16/9;
    outline: none;
    user-select: none;
    transition: all 0.3s ease;
}

.video-player.showing-ad {
    /* Allow player to expand height on mobile if the ad is taller than 16/9 */
    aspect-ratio: auto;
    min-height: 350px;
}

@media (max-width: 600px) {
    .video-player.showing-ad {
        min-height: 420px;
    }
}

.video-player.is-fullscreen {
    border-radius: 0;
    aspect-ratio: auto;
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
    cursor: pointer;
}

/* Loading Spinner */
.loading-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.5);
    z-index: 10;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(255,255,255,0.2);
    border-top-color: var(--primary, #ff3e8f);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Error Overlay */
.error-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.9);
    color: white;
    z-index: 15;
}

.error-overlay i {
    font-size: 3rem;
    color: #dc3545;
    margin-bottom: 16px;
}

.error-overlay p {
    margin-bottom: 16px;
    font-size: 1.1rem;
}

.error-overlay button {
    padding: 10px 24px;
    background: var(--primary, #ff3e8f);
    border: none;
    border-radius: 8px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.error-overlay button:hover {
    filter: brightness(1.1);
}

.error-overlay button:hover {
    filter: brightness(1.1);
}

/* Big Play Button */
.big-play-btn {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(4px);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 5;
}

.big-play-btn:hover {
    background: var(--primary, #ff3e8f);
    transform: translate(-50%, -50%) scale(1.1);
}

.big-play-btn i {
    font-size: 2rem;
    color: white;
    margin-left: 4px;
}

/* Controls Container */
.controls {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(transparent, rgba(0,0,0,0.9));
    padding: 30px 16px 12px;
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 10;
}

.controls.visible,
.video-player:hover .controls {
    opacity: 1;
}

/* Progress Bar */
.progress-container {
    position: relative;
    height: 5px;
    background: rgba(255,255,255,0.2);
    border-radius: 3px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: height 0.2s;
}

.progress-container:hover {
    height: 7px;
}

.progress-buffer {
    position: absolute;
    height: 100%;
    background: rgba(255,255,255,0.3);
    border-radius: 3px;
}

.progress-played {
    position: absolute;
    height: 100%;
    background: var(--primary, #ff3e8f);
    border-radius: 3px;
}

.progress-handle {
    position: absolute;
    right: -6px;
    top: 50%;
    transform: translateY(-50%);
    width: 14px;
    height: 14px;
    background: var(--primary, #ff3e8f);
    border-radius: 50%;
    opacity: 0;
    transition: opacity 0.2s;
    box-shadow: 0 0 4px rgba(0,0,0,0.5);
}

.progress-container:hover .progress-handle {
    opacity: 1;
}

/* Controls Bottom */
.controls-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.controls-left,
.controls-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Control Buttons */
.control-btn {
    background: transparent;
    border: none;
    color: white;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.2s;
    position: relative;
}

.control-btn:hover {
    background: rgba(255,255,255,0.1);
}

.control-btn i {
    font-size: 1rem;
}

.skip-label {
    position: absolute;
    font-size: 0.6rem;
    font-weight: 700;
    bottom: 6px;
}

.speed-btn {
    width: auto;
    padding: 0 10px;
    font-weight: 600;
    font-size: 0.8rem;
}

/* Volume */
.volume-container {
    display: flex;
    align-items: center;
}

.volume-slider-container {
    width: 0;
    overflow: hidden;
    transition: width 0.2s;
}

.volume-slider-container.visible {
    width: 80px;
    margin-left: 4px;
}

.volume-slider {
    height: 4px;
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
    cursor: pointer;
}

.volume-level {
    height: 100%;
    background: white;
    border-radius: 2px;
    position: relative;
}

.volume-handle {
    position: absolute;
    right: -5px;
    top: 50%;
    transform: translateY(-50%);
    width: 10px;
    height: 10px;
    background: white;
    border-radius: 50%;
}

/* Time Display */
.time-display {
    color: white;
    font-size: 0.85rem;
    font-weight: 500;
    font-family: 'Inter', monospace;
    margin-left: 8px;
}

.time-separator {
    margin: 0 4px;
    color: rgba(255,255,255,0.5);
}

/* Dropdown Menu */
.dropdown-container {
    position: relative;
}

.dropdown-menu {
    position: absolute;
    bottom: 100%;
    right: 0;
    background: rgba(28,28,28,0.95);
    border-radius: 8px;
    padding: 8px 0;
    min-width: 80px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(10px);
    transition: all 0.2s;
    z-index: 20;
    backdrop-filter: blur(10px);
}

.dropdown-menu.visible {
    opacity: 1;
    visibility: visible;
    transform: translateY(-8px);
}

.dropdown-menu button {
    display: block;
    width: 100%;
    padding: 8px 16px;
    background: transparent;
    border: none;
    color: white;
    text-align: left;
    cursor: pointer;
    font-size: 0.85rem;
    transition: background 0.2s;
}

.dropdown-menu button:hover {
    background: rgba(255,255,255,0.1);
}

.dropdown-menu button.active {
    color: var(--primary, #ff3e8f);
    font-weight: 600;
}

/* Title Overlay */
.title-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    padding: 20px;
    background: linear-gradient(rgba(0,0,0,0.7), transparent);
    color: white;
    font-size: 1.2rem;
    font-weight: 600;
    opacity: 0;
    transition: opacity 0.3s;
}

.title-overlay.visible {
    opacity: 1;
}

/* Mobile Adjustments */
/* Ad Overlay Styles */
.ad-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 1) !important;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}

.ad-card {
    background: #1a1a1a;
    border-radius: 12px;
    width: 330px; /* Adjusted to fit 308px ad + padding */
    max-width: 95%;
    overflow: hidden;
    border: 1px solid #333;
    display: flex;
    flex-direction: column;
}

.ad-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 15px;
    background: #222;
    border-bottom: 1px solid #333;
}

.ad-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #888;
}

.ad-timer {
    font-size: 0.8rem;
    color: var(--primary, #ff3e8f);
    font-weight: 600;
}

.ad-body {
    position: relative;
    height: 286px; /* Adjusted to match ad height */
    background: #222;
    cursor: pointer;
}

.ad-iframe-box {
    width: 100%;
    height: 100%;
    border: none;
}

@media (max-width: 480px) {
    .ad-card {
        width: 310px;
        transform: scale(0.9);
        transform-origin: center center;
    }
    
    .ad-body {
        height: 280px;
    }
}

@media (max-width: 360px) {
    .ad-card {
        width: 280px;
        transform: scale(0.85);
    }
}

.ad-click-overlay {
    position: absolute;
    inset: 0;
}

.ad-footer {
    padding: 15px;
    display: flex;
    justify-content: flex-end;
    background: #222;
}

.skip-ad-btn {
    padding: 8px 20px;
    background: var(--primary, #ff3e8f);
    border: none;
    border-radius: 6px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.skip-ad-btn:disabled {
    background: #444;
    color: #888;
    cursor: default;
}

.skip-ad-btn:not(:disabled):hover {
    filter: brightness(1.1);
    transform: translateX(2px);
}

@media (max-width: 768px) {
    .controls-bottom {
        gap: 4px;
    }
    
    .control-btn {
        width: 32px;
        height: 32px;
    }
    
    .control-btn i {
        font-size: 0.9rem;
    }
    
    .time-display {
        font-size: 0.75rem;
    }
    
    .volume-slider-container.visible {
        width: 60px;
    }
    
    .big-play-btn {
        width: 60px;
        height: 60px;
    }
    
    .big-play-btn i {
        font-size: 1.5rem;
    }
}
</style>

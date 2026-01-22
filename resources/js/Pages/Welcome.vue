<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';
import AdBanner from '@/Components/AdBanner.vue';

const props = defineProps({
    albums: {
        type: Object,
        default: () => ({ data: [] }),
    },
    search: {
        type: String,
        default: '',
    },
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    laravelVersion: {
        type: String,
        default: '',
    },
    phpVersion: {
        type: String,
        default: '',
    },
});

const page = usePage();
const isLoggedIn = computed(() => !!page.props.auth?.user);
const t = computed(() => page.props.translations || {});

// Get albums data from props (comes from controller)
const albumsList = computed(() => props.albums?.data || []);
const pagination = computed(() => ({
    currentPage: props.albums?.current_page || 1,
    lastPage: props.albums?.last_page || 1,
    total: props.albums?.total || 0,
    perPage: props.albums?.per_page || 24,
    prevPageUrl: props.albums?.prev_page_url,
    nextPageUrl: props.albums?.next_page_url,
    links: props.albums?.links || [],
}));

const formatNumber = (num) => {
    if (!num) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
};

const getPageRange = () => {
    const current = pagination.value.currentPage;
    const last = pagination.value.lastPage;
    const range = [];
    
    for (let i = Math.max(1, current - 2); i <= Math.min(last, current + 2); i++) {
        range.push(i);
    }
    
    return range;
};
</script>

<template>
    <AppLayout :title="search ? 'Busca: ' + search : 'Home'">
        <!-- Page Title -->
        <div class="page-title-section">
            <h1><i class="fas fa-fire"></i> {{ search ? 'Resultados' : (t.welcome?.latest_albums || 'Conteúdo em Destaque') }}</h1>
            <p class="subtitle">
                <template v-if="search">
                    {{ t.welcome?.search_results || 'Resultados para' }} "{{ search }}" 
                    <span class="result-count">({{ pagination.total }} {{ t.welcome?.found || 'encontrados' }})</span>
                </template>
                <template v-else>
                    {{ t.welcome?.subtitle || 'Explore os álbuns mais populares' }}
                </template>
            </p>
        </div>

        <!-- Welcome Ad -->
        <AdBanner zone="1109450" width="125" height="125" :count="5" />

        <!-- Albums Grid -->
        <div v-if="albumsList.length > 0" class="albums-grid">
            <AlbumCard 
                v-for="album in albumsList" 
                :key="album.id"
                :album="album"
            />
        </div>

        <!-- Empty State -->
        <div v-else class="empty-state">
            <i class="fas fa-video-slash"></i>
            <h3>{{ t.welcome?.no_albums || 'Nenhum conteúdo encontrado' }}</h3>
            <p v-if="search">{{ t.welcome?.search_empty || 'Tente buscar por outros termos.' }}</p>
            <p v-else>{{ t.welcome?.no_content || 'Os álbuns aparecerão aqui quando estiverem disponíveis.' }}</p>
        </div>

        <!-- Pagination -->
        <div v-if="pagination.lastPage > 1" class="pagination">
            <!-- Previous -->
            <Link 
                v-if="pagination.prevPageUrl"
                :href="pagination.prevPageUrl" 
                class="pagination-item"
                preserve-scroll
            >
                <i class="fas fa-chevron-left"></i>
            </Link>
            <span v-else class="pagination-item disabled">
                <i class="fas fa-chevron-left"></i>
            </span>

            <!-- Page Numbers -->
            <Link 
                v-for="pageNum in getPageRange()" 
                :key="pageNum"
                :href="`?page=${pageNum}${search ? '&q=' + encodeURIComponent(search) : ''}`"
                :class="['pagination-item', { active: pageNum === pagination.currentPage }]"
                preserve-scroll
            >
                {{ pageNum }}
            </Link>

            <!-- Next -->
            <Link 
                v-if="pagination.nextPageUrl"
                :href="pagination.nextPageUrl" 
                class="pagination-item"
                preserve-scroll
            >
                <i class="fas fa-chevron-right"></i>
            </Link>
            <span v-else class="pagination-item disabled">
                <i class="fas fa-chevron-right"></i>
            </span>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-title-section {
    margin-bottom: 32px;
}

.page-title-section h1 {
    font-size: 1.8rem;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title-section h1 i {
    color: var(--primary);
}

.page-title-section .subtitle {
    color: var(--text-secondary);
    font-size: 1rem;
}

.result-count {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 5rem;
    margin-bottom: 24px;
    color: var(--text-muted);
    opacity: 0.5;
}

.empty-state h3 {
    color: var(--text-primary);
    margin-bottom: 12px;
    font-size: 1.5rem;
}

.empty-state p {
    max-width: 400px;
    margin: 0 auto;
}

.pagination-item.disabled {
    opacity: 0.3;
    cursor: not-allowed;
    pointer-events: none;
}
</style>

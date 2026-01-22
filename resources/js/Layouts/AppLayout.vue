<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ChatManager from '@/Components/Chat/ChatManager.vue';
import AdBanner from '@/Components/AdBanner.vue';

defineProps({
    title: String,
});

const page = usePage();
const showingNavigationDropdown = ref(false);
const showAgeModal = ref(!document.cookie.includes('age_verified=yes'));
const t = computed(() => page.props.translations || {});

const isLoggedIn = computed(() => !!page.props.auth?.user);
const user = computed(() => page.props.auth?.user);

const searchQuery = ref('');

// Initialize search query from URL if present
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.has('q')) {
        searchQuery.value = params.get('q');
    }
});

const handleSearch = () => {
    if (searchQuery.value.trim()) {
        router.get(route('home'), { q: searchQuery.value });
    } else {
        router.get(route('home'));
    }
};

const logout = () => {
    router.post(route('logout'));
};

const languages = [
    { code: 'en', name: 'English', flag: '🇺🇸' },
    { code: 'pt_BR', name: 'Português', flag: '🇧🇷' },
    { code: 'es', name: 'Español', flag: '🇪🇸' },
    { code: 'fr', name: 'Français', flag: '🇫🇷' },
    { code: 'zh', name: '中文', flag: '🇨🇳' },
];

const currentLocale = computed(() => page.props.locale || 'en');

const switchLanguage = (code) => {
    router.get(route('lang.switch', { locale: code }), {}, {
        preserveScroll: true
    });
};

const acceptAge = () => {
    document.cookie = "age_verified=yes; path=/; max-age=" + (365 * 24 * 60 * 60);
    showAgeModal.value = false;
};

onMounted(() => {
    if (isLoggedIn.value && user.value) {
        const createdAt = new Date(user.value.created_at);
        const now = new Date();
        const diffInHours = (now - createdAt) / (1000 * 60 * 60);
        
        if (diffInHours < 48) {
            const hoursRemaining = Math.max(0, Math.ceil(48 - diffInHours));
            
            // Show alert only once per session or if redirected with error
            if (!sessionStorage.getItem('notified_age_restriction')) {
                window.Swal.fire({
                    title: 'Aviso de Segurança',
                    text: `Sua conta precisa ter pelo menos 48 horas para fazer uploads. Faltam aproximadamente ${hoursRemaining} horas.`,
                    icon: 'info',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#ff3366',
                    background: '#1a1a1a',
                    color: '#ffffff'
                });
                sessionStorage.setItem('notified_age_restriction', 'true');
            }
        }
    }

    // Check for flash errors or redirect errors from middleware
    if (page.props.errors && page.props.errors.account_age) {
        window.Swal.fire({
            title: 'Acesso Restrito',
            text: page.props.errors.account_age,
            icon: 'warning',
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#ff3366',
            background: '#1a1a1a',
            color: '#ffffff'
        });
    }
});
</script>

<template>
    <div class="hubporn-app">
        <Head :title="title" />

        <!-- Age Verification Modal -->
        <Teleport to="body">
            <div v-if="showAgeModal" class="age-modal" id="ageModal">
                <div class="age-modal-content fade-in">
                    <h2><i class="fas fa-shield-halved"></i> {{ t.age_verification?.title || 'Verificação de Idade' }}</h2>
                    <p>{{ t.age_verification?.text || 'Este site contém conteúdo adulto destinado apenas a maiores de 18 anos. Ao entrar, você confirma que tem idade legal para visualizar esse tipo de conteúdo.' }}</p>
                    <div class="age-modal-buttons">
                        <button class="btn btn-primary btn-lg" @click="acceptAge">
                            <i class="fas fa-check"></i> {{ t.age_verification?.accept || 'Tenho 18+ anos' }}
                        </button>
                        <a href="https://google.com" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times"></i> {{ t.age_verification?.exit || 'Sair' }}
                        </a>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Navbar -->
        <nav class="navbar">
            <div class="container">
                <Link href="/" class="navbar-brand">
                    <i class="fas fa-fire"></i>
                    <span>HubPorn</span>
                </Link>

                <div class="navbar-search">
                    <form @submit.prevent="handleSearch" class="search-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input 
                            v-model="searchQuery"
                            type="text" 
                            class="search-input" 
                            :placeholder="t.welcome?.search_placeholder || 'Buscar vídeos e fotos...'" 
                        />
                        <button type="submit" class="search-btn">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>

                <div class="navbar-actions">
                    <!-- Language Selector -->
                    <div class="lang-selector">
                        <Dropdown align="right" width="48" :contentClasses="['dropdown-menu-dark']">
                            <template #trigger>
                                <button class="lang-btn">
                                    <span class="curr-flag">{{ languages.find(l => l.code === currentLocale)?.flag }}</span>
                                    <span class="curr-code">{{ currentLocale.split('_')[0].toUpperCase() }}</span>
                                </button>
                            </template>
                            <template #content>
                                <div class="dropdown-header">
                                    Language / Idioma
                                </div>
                                <button 
                                    v-for="lang in languages" 
                                    :key="lang.code"
                                    @click="switchLanguage(lang.code)"
                                    class="dropdown-item flex items-center justify-between"
                                    :class="{ 'active-lang': currentLocale === lang.code }"
                                >
                                    <span>{{ lang.flag }} {{ lang.name }}</span>
                                    <i v-if="currentLocale === lang.code" class="fas fa-check text-primary"></i>
                                </button>
                            </template>
                        </Dropdown>
                    </div>

                    <!-- Logged In User -->
                    <template v-if="isLoggedIn">
                        <Link href="/upload" class="btn btn-primary hide-mobile">
                            <i class="fas fa-plus"></i> {{ t.welcome?.upload || 'Upload' }}
                        </Link>
                        
                        <!-- User Dropdown -->
                        <div class="ml-3 relative">
                            <Dropdown align="right" width="48" :contentClasses="['dropdown-menu-dark']">
                                <template #trigger>
                                    <button class="user-menu-btn">
                                        <img 
                                            :src="user.computed_avatar_url || user.profile_photo_url || '/assets/images/default-avatar.jpg'" 
                                            :alt="user.name" 
                                            class="user-avatar"
                                        />
                                    </button>
                                </template>

                                <template #content>
                                    <div class="dropdown-header">
                                        {{ user.name }}
                                    </div>
                                    
                                    <Link :href="route('dashboard')" class="dropdown-item">
                                        <i class="fas fa-tachometer-alt"></i> {{ t.nav?.dashboard || 'Dashboard' }}
                                    </Link>
                                    
                                    <Link :href="`/profile/${user.id}`" class="dropdown-item">
                                        <i class="fas fa-user"></i> {{ t.nav?.profile || 'Meu Perfil' }}
                                    </Link>
                                    
                                    <Link :href="route('profile.show')" class="dropdown-item">
                                        <i class="fas fa-cog"></i> {{ t.nav?.settings || 'Configurações' }}
                                    </Link>

                                    <div class="dropdown-divider"></div>

                                    <form @submit.prevent="logout">
                                        <button type="submit" class="dropdown-item text-danger w-full text-left">
                                            <i class="fas fa-sign-out-alt"></i> {{ t.nav?.logout || 'Sair' }}
                                        </button>
                                    </form>
                                </template>
                            </Dropdown>
                        </div>
                    </template>
                    <!-- Guest -->
                    <template v-else>
                        <Link :href="route('login')" class="btn btn-secondary">{{ t.welcome?.login || 'Entrar' }}</Link>
                        <Link :href="route('register')" class="btn btn-primary">{{ t.welcome?.register || 'Cadastrar' }}</Link>
                    </template>
                </div>

                <!-- Mobile Menu Button -->
                <div class="mobile-menu-toggle" @click="showingNavigationDropdown = !showingNavigationDropdown">
                    <i :class="showingNavigationDropdown ? 'fas fa-times' : 'fas fa-bars'"></i>
                </div>
            </div>
        </nav>

        <!-- Mobile Navigation -->
        <div :class="['mobile-nav', { 'active': showingNavigationDropdown }]">
            <div class="mobile-nav-content">
                <div class="mobile-search">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" class="search-input" placeholder="Buscar..." />
                    </div>
                </div>
                <template v-if="isLoggedIn">
                    <Link href="/upload" class="mobile-nav-link">
                        <i class="fas fa-plus"></i> Upload
                    </Link>
                    <Link :href="`/profile/${user.name}`" class="mobile-nav-link">
                        <i class="fas fa-user"></i> Perfil
                    </Link>
                    <button @click="logout" class="mobile-nav-link">
                        <i class="fas fa-sign-out-alt"></i> Sair
                    </button>
                </template>
                <template v-else>
                    <Link :href="route('login')" class="mobile-nav-link">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </Link>
                    <Link :href="route('register')" class="mobile-nav-link">
                        <i class="fas fa-user-plus"></i> Cadastrar
                    </Link>
                </template>
                <div class="mobile-lang-selector">
                    <p class="mobile-nav-title">Language / Idioma</p>
                    <div class="mobile-lang-grid">
                        <button 
                            v-for="lang in languages" 
                            :key="lang.code"
                            @click="switchLanguage(lang.code)"
                            class="mobile-lang-item"
                            :class="{ 'active': currentLocale === lang.code }"
                        >
                            <span class="flag">{{ lang.flag }}</span>
                            <span class="name">{{ lang.name.split(' ')[0] }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <main>
            <div class="container">
                <!-- Top Ad -->
                <AdBanner zone="1109450" width="125" height="125" :count="5" />

                <!-- Page Header Slot -->
                <template v-if="$slots.header">
                    <div class="page-header">
                        <slot name="header" />
                    </div>
                </template>

                <!-- Page Content -->
                <slot />

                <!-- Bottom Ad -->
                <AdBanner zone="1109450" width="125" height="125" :count="5" />
            </div>
        </main>

        <!-- Footer -->
        <footer class="footer">
            <div class="container">
                <div class="footer-content">
                    <div>
                        <strong>HubPorn</strong> &copy; {{ new Date().getFullYear() }} - Todos os direitos reservados
                    </div>
                    <div class="footer-links">
                        <Link href="/terms">Termos de Uso</Link>
                        <Link href="/privacy">Privacidade</Link>
                        <Link href="/dmca">DMCA</Link>
                        <Link href="/contact">Contato</Link>
                    </div>
                </div>
            </div>
        </footer>

        <!-- Floating Chat -->
        <ChatManager />
    </div>
</template>

<style>
/* ========================================
   HubPorn - Modern Adult Site CSS (Vue)
   ======================================== */

/* Import Google Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
    /* Colors */
    --primary: #ff3366;
    --primary-dark: #cc2952;
    --primary-light: #ff6b8a;
    --secondary: #6366f1;
    --dark: #0f0f0f;
    --dark-lighter: #1a1a1a;
    --dark-card: #1f1f1f;
    --dark-hover: #2a2a2a;
    --text-primary: #ffffff;
    --text-secondary: #a0a0a0;
    --text-muted: #666666;
    --border: #333333;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;

    /* Spacing */
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
    --spacing-2xl: 48px;

    /* Border Radius */
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 16px;
    --radius-xl: 24px;
    --radius-full: 9999px;

    /* Shadows */
    --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.3);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.5);
    --shadow-glow: 0 0 20px rgba(255, 51, 102, 0.3);

    /* Transitions */
    --transition-fast: 0.15s ease;
    --transition: 0.3s ease;
    --transition-slow: 0.5s ease;
}

/* Reset & Base */
*,
*::before,
*::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    scroll-behavior: smooth;
}

body,
.hubporn-app {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--dark);
    color: var(--text-primary);
    line-height: 1.6;
    min-height: 100vh;
}

a {
    color: var(--primary);
    text-decoration: none;
    transition: var(--transition-fast);
}

a:hover {
    color: var(--primary-light);
}

img,
video {
    max-width: 100%;
    height: auto;
}

/* Typography */
h1, h2, h3, h4, h5, h6 {
    font-weight: 600;
    line-height: 1.3;
    color: var(--text-primary);
}

h1 { font-size: 2.5rem; }
h2 { font-size: 2rem; }
h3 { font-size: 1.5rem; }
h4 { font-size: 1.25rem; }

/* Container */
.container {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

/* ========================================
   Buttons
   ======================================== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-lg);
    font-size: 0.95rem;
    font-weight: 500;
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    font-family: inherit;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    box-shadow: var(--shadow-sm);
}

.btn-primary:hover {
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
    box-shadow: var(--shadow-glow);
    transform: translateY(-2px);
    color: white;
}

.btn-secondary {
    background: var(--dark-card);
    color: var(--text-primary);
    border: 1px solid var(--border);
}

.btn-secondary:hover {
    background: var(--dark-hover);
    border-color: var(--primary);
}

.btn-outline {
    background: transparent;
    color: var(--primary);
    border: 2px solid var(--primary);
}

.btn-outline:hover {
    background: var(--primary);
    color: white;
}

.btn-lg {
    padding: var(--spacing-md) var(--spacing-xl);
    font-size: 1.1rem;
}

.btn-sm {
    padding: var(--spacing-xs) var(--spacing-md);
    font-size: 0.85rem;
}

/* ========================================
   Navbar
   ======================================== */
.navbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 70px;
    background: rgba(15, 15, 15, 0.95);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    z-index: 1000;
    display: flex;
    align-items: center;
}

.navbar .container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--spacing-lg);
}

/* Language Selector */
.lang-selector {
    margin-right: var(--spacing-md);
}

.lang-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border);
    padding: 6px 12px;
    border-radius: var(--radius-full, 20px);
    color: var(--text-primary);
    font-size: 0.85rem;
    font-weight: 600;
    transition: var(--transition);
    cursor: pointer;
}

.lang-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: var(--primary);
}

.curr-flag {
    font-size: 1.1rem;
}

.active-lang {
    background: rgba(139, 92, 246, 0.1) !important;
    font-weight: 700 !important;
}

.dropdown-item.flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Mobile Lang */
.mobile-lang-selector {
    padding: var(--spacing-md);
    border-top: 1px solid var(--border);
    margin-top: var(--spacing-md);
}

.mobile-nav-title {
    font-size: 0.8rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: var(--spacing-sm);
}

.mobile-lang-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.mobile-lang-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border);
    padding: 8px;
    border-radius: var(--radius-md);
    color: var(--text-primary);
    cursor: pointer;
    transition: var(--transition);
}

.mobile-lang-item.active {
    background: rgba(139, 92, 246, 0.1);
    border-color: var(--primary);
}

.mobile-lang-item .flag {
    font-size: 1.2rem;
}

.mobile-lang-item .name {
    font-size: 0.7rem;
}

.navbar-brand {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.navbar-brand span {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.navbar-brand:hover {
    color: var(--text-primary);
}

.navbar-search {
    flex: 1;
    max-width: 500px;
}

.search-wrapper {
    position: relative;
}

.search-wrapper i.search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
}

.search-input {
    width: 100%;
    padding: var(--spacing-sm) var(--spacing-xl);
    padding-left: 45px;
    padding-right: 50px;
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-full);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: var(--transition);
    font-family: inherit;
}

.search-btn {
    position: absolute;
    right: 5px;
    top: 50%;
    transform: translateY(-50%);
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--primary);
    border: none;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}

.search-btn:hover {
    background: var(--primary-light);
    transform: translateY(-50%) scale(1.05);
}

.search-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(255, 51, 102, 0.2);
}

.search-input::placeholder {
    color: var(--text-muted);
}

.navbar-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-full);
    object-fit: cover;
    border: 2px solid var(--primary);
    transition: var(--transition);
}

.user-avatar:hover {
    border-color: var(--primary-light);
    box-shadow: var(--shadow-glow);
}

.mobile-menu-toggle {
    display: none;
    font-size: 1.5rem;
    color: var(--text-primary);
    cursor: pointer;
    padding: var(--spacing-sm);
}

/* ========================================
   Mobile Navigation
   ======================================== */
.mobile-nav {
    position: fixed;
    top: 70px;
    left: 0;
    right: 0;
    background: var(--dark-lighter);
    border-bottom: 1px solid var(--border);
    z-index: 999;
    max-height: 0;
    overflow: hidden;
    transition: max-height var(--transition);
}

.mobile-nav.active {
    max-height: 400px;
}

.mobile-nav-content {
    padding: var(--spacing-md);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.mobile-search {
    margin-bottom: var(--spacing-sm);
}

.mobile-nav-link {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-md);
    background: var(--dark-card);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-weight: 500;
    transition: var(--transition);
    border: none;
    width: 100%;
    cursor: pointer;
    font-family: inherit;
    font-size: 1rem;
}

.mobile-nav-link:hover {
    background: var(--dark-hover);
    color: var(--primary);
}

/* ========================================
   Main Content
   ======================================== */
main {
    margin-top: 70px;
    min-height: calc(100vh - 70px - 100px);
    padding: var(--spacing-xl) 0;
}

.page-header {
    margin-bottom: var(--spacing-xl);
}

/* ========================================
   Footer
   ======================================== */
.footer {
    background: var(--dark-lighter);
    border-top: 1px solid var(--border);
    padding: var(--spacing-xl) 0;
    margin-top: var(--spacing-2xl);
}

.footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
    color: var(--text-secondary);
}

.footer-links {
    display: flex;
    gap: var(--spacing-lg);
}

.footer-links a {
    color: var(--text-secondary);
}

.footer-links a:hover {
    color: var(--primary);
}

/* ========================================
   Age Verification Modal
   ======================================== */
.age-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.age-modal-content {
    background: var(--dark-card);
    border-radius: var(--radius-xl);
    padding: var(--spacing-2xl);
    max-width: 500px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg);
    margin: var(--spacing-md);
}

.age-modal-content h2 {
    margin-bottom: var(--spacing-md);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
}

.age-modal-content p {
    color: var(--text-secondary);
    margin-bottom: var(--spacing-xl);
    line-height: 1.7;
}

.age-modal-buttons {
    display: flex;
    gap: var(--spacing-md);
    justify-content: center;
    flex-wrap: wrap;
}

/* ========================================
   Forms
   ======================================== */
.form-group {
    margin-bottom: var(--spacing-lg);
}

.form-label {
    display: block;
    margin-bottom: var(--spacing-sm);
    font-weight: 500;
    color: var(--text-secondary);
}

.form-input,
.form-textarea,
.form-select {
    width: 100%;
    padding: var(--spacing-md);
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 1rem;
    transition: var(--transition);
    font-family: inherit;
}

.form-input:focus,
.form-textarea:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(255, 51, 102, 0.2);
}

.form-textarea {
    min-height: 120px;
    resize: vertical;
}

/* ========================================
   Albums Grid
   ======================================== */
.albums-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--spacing-lg);
}

.album-card {
    background: var(--dark-card);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: var(--transition);
    border: 1px solid var(--border);
    position: relative;
    text-decoration: none;
    display: block;
}

.album-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary);
}

.album-thumbnail {
    position: relative;
    aspect-ratio: 16/9;
    overflow: hidden;
    background: var(--dark-lighter);
}

.album-thumbnail img,
.album-thumbnail video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: var(--transition);
}

.album-card:hover .album-thumbnail img,
.album-card:hover .album-thumbnail video {
    transform: scale(1.05);
}

.album-duration {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    font-weight: 500;
}

.album-media-count {
    position: absolute;
    top: 8px;
    left: 8px;
    background: var(--primary);
    color: white;
    padding: 4px 10px;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
}

.album-info {
    padding: var(--spacing-md);
}

.album-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: var(--spacing-sm);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    color: var(--text-primary);
}

.album-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--spacing-sm);
    color: var(--text-secondary);
    font-size: 0.85rem;
}

.album-author {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.album-author img {
    width: 24px;
    height: 24px;
    border-radius: var(--radius-full);
}

.album-stats {
    display: flex;
    gap: var(--spacing-md);
}

.album-stats span {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* ========================================
   Alerts
   ======================================== */
.alert {
    padding: var(--spacing-md) var(--spacing-lg);
    border-radius: var(--radius-md);
    margin-bottom: var(--spacing-lg);
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid var(--success);
    color: var(--success);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid var(--danger);
    color: var(--danger);
}

/* ========================================
   Pagination
   ======================================== */
.pagination {
    display: flex;
    justify-content: center;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-2xl);
}

.pagination-item {
    min-width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    font-weight: 500;
    transition: var(--transition-fast);
    text-decoration: none;
}

.pagination-item:hover,
.pagination-item.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

/* ========================================
   User Dropdown
   ======================================== */
.user-menu-btn {
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
}

.dropdown-menu-dark {
    background: var(--dark-card) !important; /* Force override Jetstream bg-white */
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--spacing-xs) 0;
    box-shadow: var(--shadow-lg);
    min-width: 200px;
    overflow: hidden;
}

.dropdown-header {
    padding: var(--spacing-md) var(--spacing-lg);
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border);
    margin-bottom: var(--spacing-xs);
    letter-spacing: 0.5px;
}

.dropdown-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 20px;
    font-size: 0.9rem;
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition-fast);
    border: none;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
}

.dropdown-item:hover {
    background: var(--dark-hover);
    color: var(--primary);
}

.dropdown-item i {
    margin-right: 10px;
    width: 16px;
    text-align: center;
    color: var(--text-muted);
}

.dropdown-item:hover i {
    color: var(--primary);
}

.dropdown-divider {
    height: 1px;
    background: var(--border);
    margin: var(--spacing-xs) 0;
}

.text-danger {
    color: var(--danger);
}

.text-danger:hover {
    color: #ff4d4d;
    background: rgba(239, 68, 68, 0.1);
}

.text-danger i {
    color: var(--danger);
}

.text-danger:hover i {
    color: #ff4d4d;
}

.pagination-item.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

/* ========================================
   Tags
   ======================================== */
.tag {
    background: var(--dark-hover);
    color: var(--text-secondary);
    padding: var(--spacing-xs) var(--spacing-md);
    border-radius: var(--radius-full);
    font-size: 0.85rem;
    transition: var(--transition-fast);
    text-decoration: none;
    display: inline-block;
}

.tag:hover {
    background: var(--primary);
    color: white;
}

/* ========================================
   Animations
   ======================================== */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-in {
    animation: fadeIn 0.5s ease;
}

/* ========================================
   Responsive
   ======================================== */
@media (max-width: 768px) {
    .navbar {
        height: auto;
        padding: 15px 10px;
    }

    .navbar .container {
        flex-direction: column;
        gap: 15px;
        padding: 0;
    }

    .navbar-search {
        display: block;
        width: 100%;
        order: 3;
    }

    .navbar-actions {
        display: flex;
        order: 2;
    }

    .navbar-brand {
        order: 1;
        font-size: 1.2rem;
    }

    .mobile-menu-toggle {
        display: none;
    }

    .albums-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--spacing-sm);
    }

    h1 {
        font-size: 1.8rem;
    }

    h2 {
        font-size: 1.5rem;
    }

    .footer-content {
        flex-direction: column;
        text-align: center;
    }

    .footer-links {
        flex-wrap: wrap;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 0 8px;
    }

    .albums-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
    }

    .album-info {
        padding: 8px;
    }

    .album-title {
        font-size: 0.85rem;
        margin-bottom: 4px;
    }

    .album-meta {
        font-size: 0.7rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
    }

    .album-author img {
        width: 16px;
        height: 16px;
    }
}
</style>

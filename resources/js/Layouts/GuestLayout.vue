<script setup>
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ChatManager from '@/Components/Chat/ChatManager.vue';

defineProps({
    title: String,
});

const page = usePage();
const showingNavigationDropdown = ref(false);
const showAgeModal = ref(typeof document !== 'undefined' && !document.cookie.includes('age_verified=yes'));

const isLoggedIn = computed(() => !!page.props.auth?.user);
const user = computed(() => page.props.auth?.user);

const acceptAge = () => {
    document.cookie = "age_verified=yes; path=/; max-age=" + (365 * 24 * 60 * 60);
    showAgeModal.value = false;
};
</script>

<template>
    <div class="hubporn-app">
        <Head :title="title" />

        <!-- Age Verification Modal -->
        <Teleport to="body">
            <div v-if="showAgeModal" class="age-modal" id="ageModal">
                <div class="age-modal-content fade-in">
                    <h2><i class="fas fa-shield-halved"></i> Verificação de Idade</h2>
                    <p>Este site contém conteúdo adulto destinado apenas a maiores de 18 anos. Ao entrar, você confirma que tem idade legal para visualizar esse tipo de conteúdo.</p>
                    <div class="age-modal-buttons">
                        <button class="btn btn-primary btn-lg" @click="acceptAge">
                            <i class="fas fa-check"></i> Tenho 18+ anos
                        </button>
                        <a href="https://google.com" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times"></i> Sair
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
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" class="search-input" placeholder="Buscar vídeos e fotos..." />
                    </div>
                </div>

                <div class="navbar-actions">
                    <!-- Logged In User -->
                    <template v-if="isLoggedIn">
                        <Link href="/upload" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Upload
                        </Link>
                        <Link :href="`/profile/${user.name}`">
                            <img 
                                :src="user.profile_photo_url || '/assets/images/default-avatar.jpg'" 
                                :alt="user.name" 
                                class="user-avatar"
                            />
                        </Link>
                        <Link :href="route('logout')" method="post" as="button" class="btn btn-secondary" title="Sair">
                            <i class="fas fa-sign-out-alt"></i>
                        </Link>
                    </template>
                    <!-- Guest -->
                    <template v-else>
                        <Link :href="route('login')" class="btn btn-secondary">Entrar</Link>
                        <Link :href="route('register')" class="btn btn-primary">Cadastrar</Link>
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
                    <Link :href="route('logout')" method="post" as="button" class="mobile-nav-link">
                        <i class="fas fa-sign-out-alt"></i> Sair
                    </Link>
                </template>
                <template v-else>
                    <Link :href="route('login')" class="mobile-nav-link">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </Link>
                    <Link :href="route('register')" class="mobile-nav-link">
                        <i class="fas fa-user-plus"></i> Cadastrar
                    </Link>
                </template>
            </div>
        </div>

        <!-- Main Content -->
        <main>
            <div class="container">
                <!-- Page Header Slot -->
                <template v-if="$slots.header">
                    <div class="page-header">
                        <slot name="header" />
                    </div>
                </template>

                <!-- Page Content -->
                <slot />
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
/* All styles are in AppLayout.vue - this component uses the same styles */
</style>

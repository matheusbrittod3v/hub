<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';

defineProps({
    confirmsTwoFactorAuthentication: Boolean,
    sessions: Array,
});
</script>

<template>
    <AppLayout title="Configurações da Conta">
        <template #header>
            <div class="header-content">
                <h2 class="font-semibold text-xl text-white leading-tight">
                    <i class="fas fa-cog"></i> Configurações da Conta
                </h2>
                <Link :href="route('profile.show', $page.props.auth.user.id)" class="btn btn-secondary">
                    <i class="fas fa-user"></i> Ver meu Perfil
                </Link>
            </div>
        </template>

        <div class="settings-page">
            <div class="settings-grid">
                <!-- Sidebar Navigation -->
                <div class="settings-nav">
                    <div class="nav-card">
                        <h3><i class="fas fa-sliders-h"></i> Menu</h3>
                        <nav>
                            <a href="#profile" class="nav-item active">
                                <i class="fas fa-user"></i> Informações do Perfil
                            </a>
                            <a href="#password" class="nav-item">
                                <i class="fas fa-lock"></i> Alterar Senha
                            </a>
                            <a href="#security" class="nav-item">
                                <i class="fas fa-shield-alt"></i> Segurança
                            </a>
                            <a href="#sessions" class="nav-item">
                                <i class="fas fa-desktop"></i> Sessões
                            </a>
                            <a href="#danger" class="nav-item danger">
                                <i class="fas fa-trash-alt"></i> Excluir Conta
                            </a>
                        </nav>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="settings-content">
                    <!-- Profile Information -->
                    <section id="profile" class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-user"></i>
                            <div>
                                <h3>Informações do Perfil</h3>
                                <p>Atualize as informações do seu perfil e endereço de e-mail.</p>
                            </div>
                        </div>
                        <div v-if="$page.props.jetstream.canUpdateProfileInformation" class="section-body">
                            <UpdateProfileInformationForm :user="$page.props.auth.user" />
                        </div>
                    </section>

                    <!-- Password -->
                    <section id="password" class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-lock"></i>
                            <div>
                                <h3>Alterar Senha</h3>
                                <p>Use uma senha longa e aleatória para manter sua conta segura.</p>
                            </div>
                        </div>
                        <div v-if="$page.props.jetstream.canUpdatePassword" class="section-body">
                            <UpdatePasswordForm />
                        </div>
                    </section>

                    <!-- Two Factor -->
                    <section id="security" class="settings-section" v-if="$page.props.jetstream.canManageTwoFactorAuthentication">
                        <div class="section-header">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <h3>Autenticação de Dois Fatores</h3>
                                <p>Adicione segurança adicional à sua conta usando autenticação de dois fatores.</p>
                            </div>
                        </div>
                        <div class="section-body">
                            <TwoFactorAuthenticationForm :requires-confirmation="confirmsTwoFactorAuthentication" />
                        </div>
                    </section>

                    <!-- Sessions -->
                    <section id="sessions" class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-desktop"></i>
                            <div>
                                <h3>Sessões do Navegador</h3>
                                <p>Gerencie e encerre suas sessões ativas em outros navegadores e dispositivos.</p>
                            </div>
                        </div>
                        <div class="section-body">
                            <LogoutOtherBrowserSessionsForm :sessions="sessions" />
                        </div>
                    </section>

                    <!-- Delete Account -->
                    <section id="danger" class="settings-section danger-zone" v-if="$page.props.jetstream.hasAccountDeletionFeatures">
                        <div class="section-header">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <h3>Zona de Perigo</h3>
                                <p>Uma vez que sua conta for excluída, todos os seus recursos e dados serão permanentemente apagados.</p>
                            </div>
                        </div>
                        <div class="section-body">
                            <DeleteUserForm />
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-content h2 i {
    margin-right: 10px;
    color: var(--primary);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: var(--radius-md);
    font-weight: 600;
    text-decoration: none;
    font-size: 0.9rem;
}

.btn-secondary {
    background: rgba(255,255,255,0.1);
    color: var(--text-primary);
}

.btn-secondary:hover {
    background: rgba(255,255,255,0.15);
}

.settings-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 30px 20px;
}

.settings-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 30px;
}

/* Sidebar Navigation */
.settings-nav {
    position: sticky;
    top: 100px;
    align-self: start;
}

.nav-card {
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
}

.nav-card h3 {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.nav-card nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.2s;
}

.nav-item:hover {
    background: rgba(255,255,255,0.05);
    color: var(--text-primary);
}

.nav-item.active {
    background: rgba(255, 51, 102, 0.1);
    color: var(--primary);
}

.nav-item.danger {
    color: #dc3545;
}

.nav-item.danger:hover {
    background: rgba(220, 53, 69, 0.1);
}

/* Content Sections */
.settings-content {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.settings-section {
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.section-header {
    display: flex;
    gap: 16px;
    padding: 24px;
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,0.02);
}

.section-header > i {
    font-size: 1.5rem;
    color: var(--primary);
    margin-top: 4px;
}

.section-header h3 {
    font-size: 1.2rem;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.section-header p {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.section-body {
    padding: 24px;
}

/* Danger Zone */
.danger-zone .section-header {
    background: rgba(220, 53, 69, 0.1);
    border-bottom-color: rgba(220, 53, 69, 0.3);
}

.danger-zone .section-header > i {
    color: #dc3545;
}

.danger-zone .section-header h3 {
    color: #dc3545;
}

/* Responsive */
@media (max-width: 900px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }

    .settings-nav {
        position: static;
    }

    .nav-card nav {
        flex-direction: row;
        flex-wrap: wrap;
    }

    .nav-item {
        flex: 1;
        min-width: 140px;
        justify-content: center;
    }
}
</style>

<style>
/* Global overrides for Jetstream components inside settings */
.settings-section .bg-white {
    background-color: transparent !important;
    box-shadow: none !important;
}

.settings-section input,
.settings-section select,
.settings-section textarea {
    background-color: var(--dark-lighter) !important;
    color: white !important;
    border-color: var(--border) !important;
    border-radius: var(--radius-md) !important;
}

.settings-section input:focus,
.settings-section select:focus,
.settings-section textarea:focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 2px rgba(255, 51, 102, 0.2) !important;
    outline: none !important;
}

.settings-section label {
    color: var(--text-secondary) !important;
}

.settings-section .text-gray-600,
.settings-section .text-gray-700,
.settings-section .text-gray-500,
.settings-section .text-gray-800 {
    color: var(--text-secondary) !important;
}

.settings-section .text-gray-900 {
    color: white !important;
}

.settings-section .shadow {
    box-shadow: none !important;
}

.settings-section .bg-gray-50,
.settings-section .bg-gray-100 {
    background-color: var(--dark-lighter) !important;
}

.settings-section button[type="submit"],
.settings-section .inline-flex.items-center.px-4 {
    background-color: var(--primary) !important;
    color: white !important;
}

.settings-section .border-gray-300 {
    border-color: var(--border) !important;
}
</style>

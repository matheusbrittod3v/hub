<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const page = usePage();
const t = computed(() => page.props.translations || {});

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t.welcome?.login || 'Entrar'" />

        <div class="auth-card-container">
            <div class="auth-card">
                <div class="auth-header">
                    <Link href="/" class="auth-logo">
                        <i class="fas fa-fire"></i> HubPorn
                    </Link>
                    <h2 class="auth-title">{{ t.auth?.login_title || 'Bem-vindo de volta!' }}</h2>
                    <p class="auth-subtitle">{{ t.auth?.login_subtitle || 'Entre para acessar sua conta' }}</p>
                </div>

                <div v-if="status" class="status-message">
                    {{ status }}
                </div>

                <form @submit.prevent="submit">
                    <div class="form-group">
                        <InputLabel :value="t.auth?.email || 'Email'" class="form-label" />
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <TextInput
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="form-input"
                                required
                                autofocus
                                autocomplete="username"
                                placeholder="seu@email.com"
                            />
                        </div>
                        <InputError :message="form.errors.email" class="error-message" />
                    </div>

                    <div class="form-group">
                        <InputLabel :value="t.auth?.password || 'Senha'" class="form-label" />
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <TextInput
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="form-input"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                            />
                        </div>
                        <InputError :message="form.errors.password" class="error-message" />
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <Checkbox v-model:checked="form.remember" name="remember" />
                            <span class="ml-2 text-sm text-gray-400">{{ t.auth?.remember_me || 'Lembrar de mim' }}</span>
                        </label>

                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="forgot-password"
                        >
                            {{ t.auth?.forgot_password || 'Esqueceu a senha?' }}
                        </Link>
                    </div>

                    <div class="form-actions">
                        <PrimaryButton class="auth-btn" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            <i class="fas fa-sign-in-alt mr-2"></i> {{ t.welcome?.login || 'Entrar' }}
                        </PrimaryButton>
                    </div>

                    <div class="auth-footer">
                        {{ t.auth?.no_account || 'Não tem uma conta?' }} 
                        <Link :href="route('register')" class="register-link">
                            {{ t.auth?.register_now || 'Cadastre-se grátis' }}
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>

<style scoped>
.auth-card-container {
    min-height: calc(100vh - 80px); /* Adjust for navbar if visible */
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.auth-card {
    background: var(--dark-card);
    width: 100%;
    max-width: 420px;
    padding: 40px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.auth-header {
    text-align: center;
    margin-bottom: 32px;
}

.auth-logo {
    font-size: 2rem;
    font-weight: 800;
    color: white;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.auth-logo i {
    color: var(--primary);
}

.auth-title {
    font-size: 1.5rem;
    color: white;
    margin-bottom: 8px;
}

.auth-subtitle {
    color: var(--text-secondary);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    color: var(--text-primary);
    margin-bottom: 8px;
    display: block;
}

.input-wrapper {
    position: relative;
}

.input-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
}

.form-input {
    width: 100%;
    background: #151515;
    border: 1px solid var(--border);
    color: white;
    padding: 12px 12px 12px 40px; /* Space for icon */
    border-radius: 8px;
    transition: all 0.3s;
}

.form-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.2);
    outline: none;
}

.error-message {
    color: #ef4444;
    font-size: 0.875rem;
    margin-top: 4px;
}

.form-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.remember-me {
    display: flex;
    align-items: center;
    cursor: pointer;
}

.forgot-password {
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.875rem;
    transition: color 0.2s;
}

.forgot-password:hover {
    color: var(--primary);
}

.auth-btn {
    width: 100%;
    justify-content: center;
    background: var(--primary);
    padding: 14px;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.auth-btn:hover {
    background: var(--primary-dark);
}

.auth-footer {
    margin-top: 24px;
    text-align: center;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.register-link {
    color: var(--primary);
    font-weight: 600;
    text-decoration: none;
    margin-left: 4px;
}

.register-link:hover {
    text-decoration: underline;
}

.status-message {
    margin-bottom: 20px;
    padding: 12px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 8px;
    color: #10b981;
    text-align: center;
}
</style>

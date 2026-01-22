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

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t.welcome?.register || 'Cadastrar'" />

        <div class="auth-card-container">
            <div class="auth-card">
                <div class="auth-header">
                    <Link href="/" class="auth-logo">
                        <i class="fas fa-fire"></i> HubPorn
                    </Link>
                    <h2 class="auth-title">{{ t.auth?.register_title || 'Crie sua conta' }}</h2>
                    <p class="auth-subtitle">{{ t.auth?.register_subtitle || 'Junte-se à nossa comunidade gratuitamente' }}</p>
                </div>

                <form @submit.prevent="submit">
                    <div class="form-group">
                        <InputLabel :value="t.auth?.name || 'Nome de Usuário'" class="form-label" />
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="form-input"
                                required
                                autofocus
                                autocomplete="name"
                                :placeholder="t.auth?.name_placeholder || 'Escolha um apelido'"
                            />
                        </div>
                        <InputError :message="form.errors.name" class="error-message" />
                    </div>

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
                                autocomplete="new-password"
                                :placeholder="t.auth?.password_hint || 'Mínimo 8 caracteres'"
                            />
                        </div>
                        <InputError :message="form.errors.password" class="error-message" />
                    </div>

                    <div class="form-group">
                        <InputLabel :value="t.auth?.confirm_password || 'Confirmar Senha'" class="form-label" />
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <TextInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="form-input"
                                required
                                autocomplete="new-password"
                                :placeholder="t.auth?.confirm_password_placeholder || 'Repita a senha'"
                            />
                        </div>
                        <InputError :message="form.errors.password_confirmation" class="error-message" />
                    </div>

                    <div v-if="$page.props.jetstream.hasTermsAndPrivacyPolicyFeature" class="form-group">
                        <InputLabel for="terms">
                            <div class="flex items-center">
                                <Checkbox id="terms" v-model:checked="form.terms" name="terms" required />
                                <div class="ml-2 text-sm text-gray-400">
                                    {{ t.auth?.terms_agree || 'Concordo com os' }} <a target="_blank" :href="route('terms.show')" class="text-primary hover:text-primary-light">{{ t.auth?.terms_service || 'Termos de Serviço' }}</a> {{ t.auth?.and || 'e' }} <a target="_blank" :href="route('policy.show')" class="text-primary hover:text-primary-light">{{ t.auth?.privacy_policy || 'Política de Privacidade' }}</a>
                                </div>
                            </div>
                        </InputLabel>
                        <InputError :message="form.errors.terms" class="error-message" />
                    </div>

                    <div class="form-actions mt-6">
                        <PrimaryButton class="auth-btn" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            <i class="fas fa-user-plus mr-2"></i> {{ t.auth?.create_account || 'Criar Conta' }}
                        </PrimaryButton>
                    </div>

                    <div class="auth-footer">
                        {{ t.auth?.already_registered || 'Já tem uma conta?' }} 
                        <Link :href="route('login')" class="login-link">
                            {{ t.auth?.login_here || 'Faça login aqui' }}
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
    max-width: 480px; /* Slightly wider for register */
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

.login-link {
    color: var(--primary);
    font-weight: 600;
    text-decoration: none;
    margin-left: 4px;
}

.login-link:hover {
    text-decoration: underline;
}

.text-primary {
    color: var(--primary);
    text-decoration: none;
}

.text-primary:hover {
    text-decoration: underline;
}
</style>

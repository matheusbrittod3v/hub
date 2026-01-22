<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const page = usePage();
const t = computed(() => page.props.translations || {});

const form = useForm({
    title: '',
    description: '',
    status: 'public', // Default
    files: [],
});

const fileInput = ref(null);
const previews = ref([]);

const handleFileChange = (e) => {
    const files = Array.from(e.target.files);
    form.files = files;
    
    // Generate previews
    previews.value = [];
    files.forEach(file => {
        const reader = new FileReader();
        const isVideo = file.type.startsWith('video/');
        
        reader.onload = (e) => {
            previews.value.push({
                name: file.name,
                size: (file.size / 1024 / 1024).toFixed(2) + ' MB',
                type: isVideo ? 'video' : 'image',
                src: e.target.result
            });
        };
        reader.readAsDataURL(file);
    });
};

const submit = () => {
    form.post(route('album.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            previews.value = [];
        }
    });
};
</script>

<template>
    <AppLayout :title="t.upload?.title || 'Criar Novo Álbum'">
        <template #header>
            <h2 class="font-semibold text-xl text-white leading-tight">
                {{ t.upload?.header || 'Upload de Conteúdo' }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="upload-card">
                    <form @submit.prevent="submit" class="upload-form">
                        
                        <!-- Header -->
                        <div class="form-header">
                            <h3><i class="fas fa-cloud-upload-alt"></i> {{ t.upload?.subtitle || 'Novo Álbum / Galeria' }}</h3>
                            <p>{{ t.upload?.desc_subtitle || 'Compartilhe suas fotos e vídeos com a comunidade.' }}</p>
                        </div>

                        <!-- Title -->
                        <div class="form-group">
                            <InputLabel for="title" :value="t.upload?.album_title || 'Título do Álbum'" class="text-white" />
                            <TextInput
                                id="title"
                                v-model="form.title"
                                type="text"
                                class="mt-1 block w-full bg-dark-input"
                                required
                                autofocus
                                :placeholder="t.upload?.placeholder_title || 'Ex: Minha viagem incrível...'"
                            />
                            <InputError :message="form.errors.title" class="mt-2" />
                        </div>

                        <!-- Description -->
                        <div class="form-group">
                            <InputLabel for="description" :value="t.upload?.album_desc || 'Descrição (Opcional)'" class="text-white" />
                            <textarea
                                id="description"
                                v-model="form.description"
                                class="form-textarea mt-1 block w-full bg-dark-input"
                                rows="3"
                                :placeholder="t.upload?.placeholder_desc || 'Conte um pouco sobre este conteúdo...'"
                            ></textarea>
                            <InputError :message="form.errors.description" class="mt-2" />
                        </div>

                        <!-- Status -->
                        <div class="form-group">
                             <InputLabel value="Visibilidade" class="text-white" />
                             <div class="flex gap-4 mt-2">
                                 <label class="radio-card" :class="{ 'active': form.status === 'public' }">
                                     <input type="radio" value="public" v-model="form.status" class="hidden">
                                     <i class="fas fa-globe"></i>
                                     <span>Público</span>
                                 </label>
                                 <label class="radio-card" :class="{ 'active': form.status === 'private' }">
                                     <input type="radio" value="private" v-model="form.status" class="hidden">
                                     <i class="fas fa-lock"></i>
                                     <span>Privado</span>
                                 </label>
                             </div>
                             <InputError :message="form.errors.status" class="mt-2" />
                        </div>

                        <!-- File Upload Area -->
                        <div class="form-group">
                            <InputLabel value="Arquivos (Fotos e Vídeos)" class="text-white mb-2" />
                            
                            <div 
                                class="dropzone"
                                @click="$refs.fileInput.click()"
                                :class="{ 'has-files': previews.length > 0 }"
                            >
                                <input 
                                    type="file" 
                                    ref="fileInput" 
                                    multiple 
                                    @change="handleFileChange" 
                                    class="hidden"
                                    accept="image/*,video/*"
                                >
                                
                                <div v-if="previews.length === 0" class="dropzone-placeholder">
                                    <i class="fas fa-cloud-upload-alt text-4xl mb-3 text-primary"></i>
                                    <p class="text-lg font-medium">Clique para selecionar arquivos</p>
                                    <p class="text-sm text-gray-400">Suporta JPG, PNG, MP4, MOV (Máx 500MB)</p>
                                </div>

                                <!-- Previews Grid -->
                                <div v-else class="previews-grid">
                                    <div v-for="(file, index) in previews" :key="index" class="preview-item">
                                        <div class="preview-media">
                                            <img v-if="file.type === 'image'" :src="file.src" />
                                            <video v-else :src="file.src"></video>
                                            <div class="file-type-badge">
                                                <i :class="file.type === 'video' ? 'fas fa-video' : 'fas fa-image'"></i>
                                            </div>
                                        </div>
                                        <div class="preview-info">
                                            <span class="truncate">{{ file.name }}</span>
                                            <span class="text-xs text-gray-400">{{ file.size }}</span>
                                        </div>
                                    </div>
                                    <div class="add-more-btn">
                                        <i class="fas fa-plus"></i>
                                        <span>Adicionar Mais</span>
                                    </div>
                                </div>
                            </div>
                            <InputError :message="form.errors.files" class="mt-2" />
                        </div>

                        <!-- Progress Bar -->
                        <div v-if="form.progress" class="mt-4">
                            <div class="progress-info">
                                <span>Enviando...</span>
                                <span>{{ form.progress.percentage }}%</span>
                            </div>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill" :style="{ width: form.progress.percentage + '%' }"></div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="form-actions mt-8 flex justify-end gap-4">
                            <Link :href="route('dashboard')" class="btn btn-secondary">
                                Cancelar
                            </Link>
                            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                <i class="fas fa-save mr-2"></i> Criar Álbum
                            </PrimaryButton>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.upload-card {
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 2rem;
    box-shadow: var(--shadow-lg);
}

.upload-form {
    max-width: 100%;
}

.form-header {
    margin-bottom: 2rem;
    border-bottom: 1px solid var(--border);
    padding-bottom: 1rem;
}

.form-header h3 {
    font-size: 1.5rem;
    color: white;
    margin-bottom: 0.5rem;
}

.form-header p {
    color: var(--text-secondary);
}

.bg-dark-input {
    background-color: var(--dark-lighter);
    border-color: var(--border);
    color: white;
}

.bg-dark-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
}

.radio-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    background: var(--dark-lighter);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s;
    color: var(--text-secondary);
}

.radio-card:hover {
    border-color: var(--primary);
}

.radio-card.active {
    background: rgba(255, 51, 102, 0.1);
    border-color: var(--primary);
    color: var(--primary);
}

.dropzone {
    border: 2px dashed var(--border);
    background: var(--dark-lighter);
    border-radius: var(--radius-lg);
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    min-height: 200px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.dropzone:hover {
    border-color: var(--primary);
    background: rgba(255, 255, 255, 0.02);
}

.dropzone.has-files {
    padding: 1rem;
    border-style: solid;
    justify-content: flex-start;
}

.previews-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 1rem;
    width: 100%;
}

.preview-item {
    background: var(--dark-card);
    border-radius: var(--radius-md);
    overflow: hidden;
    position: relative;
    border: 1px solid var(--border);
}

.preview-media {
    aspect-ratio: 1;
    position: relative;
    background: #000;
}

.preview-media img, 
.preview-media video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.file-type-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.7rem;
}

.preview-info {
    padding: 8px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.add-more-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.05);
    border: 2px dashed var(--border);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    min-height: 120px;
}

.progress-info {
    display: flex;
    justify-content: space-between;
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin-bottom: 5px;
}

.progress-bar-bg {
    height: 8px;
    background: var(--dark-lighter);
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: var(--primary);
    transition: width 0.3s ease;
}
</style>

<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const t = computed(() => page.props.translations || {});
const user = computed(() => page.props.auth?.user);
const isLoggedIn = computed(() => !!user.value);

const isOpen = ref(false);
const currentRoom = ref('geral');
const messages = ref([]);
const newMessage = ref('');
const isLoading = ref(false);
const isBlocked = ref(false);
const errorMsg = ref('');
const cooldown = ref(0);
const activeMenu = ref(null);
const showReportModal = ref(false);
const reportingMessageId = ref(null);
const selectedReason = ref('spam');
const selectedFile = ref(null);
const previewUrl = ref(null);
const chatContainer = ref(null);
const fileInput = ref(null);
const guestNickname = ref(null);
const rooms = ['geral', 'vazadas'];
const reportReasons = {
    'spam': 'Spam',
    'harassment': 'Assédio',
    'illegal': 'Conteúdo Ilegal',
    'other': 'Outros'
};

let pollInterval = null;
let cooldownInterval = null;

const toggleChat = () => {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        fetchMessages();
        startPolling();
        scrollToBottom();
    } else {
        stopPolling();
    }
};

const switchRoom = (room) => {
    currentRoom.value = room;
    messages.value = [];
    fetchMessages();
};

const fetchMessages = async () => {
    try {
        const response = await axios.get(route('chat.index'), {
            params: { room: currentRoom.value }
        });
        messages.value = response.data.messages;
        isBlocked.value = !!response.data.is_blocked;
        scrollToBottom();
    } catch (error) {
        console.error('Error fetching messages:', error);
    }
};

const startPolling = () => {
    stopPolling();
    pollInterval = setInterval(fetchMessages, 5000);
};

const stopPolling = () => {
    if (pollInterval) clearInterval(pollInterval);
};

const sendMessage = async () => {
    if (!newMessage.value.trim() && !selectedFile.value) return;
    if (cooldown.value > 0) return;

    isLoading.value = true;
    errorMsg.value = '';

    const formData = new FormData();
    formData.append('message', newMessage.value);
    formData.append('room', currentRoom.value);
    if (!isLoggedIn.value && guestNickname.value) {
        formData.append('guest_nickname', guestNickname.value);
    }
    
    if (selectedFile.value) {
        formData.append('file', selectedFile.value);
        formData.append('type', selectedFile.value.type.startsWith('video/') ? 'video' : 'image');
    } else {
        formData.append('type', 'text');
    }

    try {
        const response = await axios.post(route('chat.store'), formData);
        messages.value.push(response.data);
        newMessage.value = '';
        removeFile();
        startCooldown();
        scrollToBottom();
    } catch (error) {
        if (error.response?.status === 429) {
            errorMsg.value = error.response.data.error;
            // Parse seconds from message if possible or just set a default
            startCooldown(60);
        } else if (error.response?.status === 403) {
            errorMsg.value = error.response.data.error;
            isBlocked.value = true;
        } else {
            errorMsg.value = t.value.chat?.error_sending || 'Erro ao enviar mensagem.';
        }
    } finally {
        isLoading.value = false;
    }
};

const reportMessage = async (msgId) => {
    reportingMessageId.value = msgId;
    showReportModal.value = true;
    activeMenu.value = null;
};

const confirmReport = async () => {
    if (!reportingMessageId.value) return;
    
    try {
        await axios.post(route('chat.report', reportingMessageId.value), {
            reason: reportReasons[selectedReason.value] || 'Outros'
        });
        messages.value = messages.value.filter(m => m.id !== reportingMessageId.value);
        alert(t.value.chat?.report_success || 'Mensagem denunciada.');
        closeReportModal();
    } catch (error) {
        console.error('Error reporting message:', error);
    }
};

const closeReportModal = () => {
    showReportModal.value = false;
    reportingMessageId.value = null;
};

const startCooldown = (seconds = 60) => {
    cooldown.value = seconds;
    if (cooldownInterval) clearInterval(cooldownInterval);
    cooldownInterval = setInterval(() => {
        if (cooldown.value > 0) {
            cooldown.value--;
        } else {
            clearInterval(cooldownInterval);
        }
    }, 1000);
};

const scrollToBottom = () => {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
};

const triggerFileInput = () => {
    if (fileInput.value) fileInput.value.click();
};

const handleFileSelection = (e) => {
    const file = e.target.files[0];
    if (file) {
        selectedFile.value = file;
        previewUrl.value = URL.createObjectURL(file);
    }
};

const removeFile = () => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
    selectedFile.value = null;
    previewUrl.value = null;
    if (fileInput.value) fileInput.value.value = '';
};

const toggleMenu = (msgId) => {
    if (activeMenu.value === msgId) {
        activeMenu.value = null;
    } else {
        activeMenu.value = msgId;
    }
};

const closeMenus = (e) => {
    if (activeMenu.value && !e.target.closest('.chat-msg-menu-wrapper')) {
        activeMenu.value = null;
    }
};

const getOrSetGuestName = () => {
    if (isLoggedIn.value) return;

    let savedName = localStorage.getItem('hubporn_guest_name');
    let expiry = localStorage.getItem('hubporn_guest_expiry');

    if (!savedName || !expiry || new Date().getTime() > parseInt(expiry)) {
        savedName = 'Guest_' + Math.random().toString(36).substring(2, 7).toUpperCase();
        const ninetyDays = 90 * 24 * 60 * 60 * 1000;
        const expiryTime = new Date().getTime() + ninetyDays;
        
        localStorage.setItem('hubporn_guest_name', savedName);
        localStorage.setItem('hubporn_guest_expiry', expiryTime.toString());
        
        // Also set a cookie for 90 days if possible (fallback/redundancy)
        document.cookie = `guest_chat_name=${savedName}; max-age=${90 * 24 * 60 * 60}; path=/`;
    }
    
    guestNickname.value = savedName;
};

const parseMessage = (text) => {
    if (!text) return '';
    
    // Basic HTML Escaping
    let escaped = text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");

    // Simple BBCode
    escaped = escaped.replace(/\[b\](.*?)\[\/b\]/gi, '<strong>$1</strong>');
    escaped = escaped.replace(/\[i\](.*?)\[\/i\]/gi, '<em>$1</em>');
    escaped = escaped.replace(/\[u\](.*?)\[\/u\]/gi, '<u>$1</u>');
    escaped = escaped.replace(/\[url\](.*?)\[\/url\]/gi, '<a href="$1" target="_blank" class="text-primary underline">$1</a>');

    // Basic Emoji support (replacing :smile: etc)
    const emojis = {
        ':smile:': '😊',
        ':heart:': '❤️',
        ':fire:': '🔥',
        ':thumbsup:': '👍',
        ':laugh:': '😂',
        ':wink:': '😉',
    };
    
    Object.keys(emojis).forEach(key => {
        const regex = new RegExp(key, 'g');
        escaped = escaped.replace(regex, emojis[key]);
    });

    return escaped;
};

onMounted(() => {
    getOrSetGuestName();
    window.addEventListener('click', closeMenus);
});

onUnmounted(() => {
    stopPolling();
    if (cooldownInterval) clearInterval(cooldownInterval);
    window.removeEventListener('click', closeMenus);
});

watch(messages, () => {
    scrollToBottom();
}, { deep: true });

</script>

<template>
    <div class="chat-manager">
        <!-- Floating Button -->
        <button 
            @click="toggleChat" 
            class="chat-float-btn"
            :class="{ 'active': isOpen }"
            :title="t.chat?.title || 'Chat'"
        >
            <i :class="isOpen ? 'fas fa-times' : 'fas fa-comments'"></i>
            <span v-if="!isOpen" class="chat-badge">LIVE</span>
        </button>

        <!-- Chat Window -->
        <div v-if="isOpen" class="chat-window fade-in-up">
            <div class="chat-header">
                <div class="chat-header-info">
                    <i class="fas fa-comments text-primary"></i>
                    <span>{{ t.chat?.title || 'Chat HubPorn' }}</span>
                </div>
                <div class="chat-rooms">
                    <button 
                        v-for="room in rooms" 
                        :key="room"
                        @click="switchRoom(room)"
                        :class="{ 'active': currentRoom === room }"
                    >
                        {{ t.chat?.[room] || room }}
                    </button>
                </div>
            </div>

            <div ref="chatContainer" class="chat-messages">
                <div v-if="messages.length === 0" class="chat-empty">
                    {{ t.chat?.no_messages || 'Nenhuma mensagem ainda.' }}
                </div>
                <div 
                    v-for="msg in messages" 
                    :key="msg.id" 
                    class="chat-msg-item"
                    :class="{ 'is-me': msg.user_id === user?.id }"
                >
                    <div class="chat-msg-avatar">
                        <img 
                            :src="msg.user?.computed_avatar_url || '/assets/images/default-avatar.jpg'" 
                            alt="Avatar"
                        />
                    </div>
                    <div class="chat-msg-content">
                        <div class="chat-msg-info">
                            <span class="chat-msg-user">{{ msg.nickname }}</span>
                            <span class="chat-msg-time">{{ new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
                        </div>
                        
                        <!-- Text -->
                        <div class="chat-msg-text" v-html="parseMessage(msg.message)"></div>

                        <!-- Image -->
                        <div v-if="msg.type === 'image' && msg.meta?.url" class="chat-msg-media">
                            <img :src="msg.meta.url" alt="Chat Image" @click="window.open(msg.meta.url)" />
                        </div>

                        <!-- Video -->
                        <div v-if="msg.type === 'video' && msg.meta?.url" class="chat-msg-media">
                            <video :src="msg.meta.url" controls></video>
                        </div>

                        <!-- Context Menu (Three Dots) -->
                        <div v-if="msg.user_id !== user?.id" class="chat-msg-menu-wrapper">
                            <button @click="toggleMenu(msg.id)" class="chat-msg-menu-btn">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div v-if="activeMenu === msg.id" class="chat-msg-dropdown scale-in">
                                <button @click="reportMessage(msg.id)" class="chat-dropdown-item text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    {{ t.chat?.report || 'Denunciar' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chat-footer">
                <!-- Preview Area -->
                <div v-if="previewUrl" class="chat-media-preview-container fade-in">
                    <div class="chat-media-preview">
                        <img v-if="selectedFile?.type.startsWith('image/')" :src="previewUrl" alt="Preview" />
                        <video v-else-if="selectedFile?.type.startsWith('video/')" :src="previewUrl" muted></video>
                        <button @click="removeFile" class="chat-preview-remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <div v-if="errorMsg" class="chat-error">
                    {{ errorMsg }}
                </div>
                
                <div v-if="isBlocked" class="chat-blocked">
                    {{ t.chat?.blocked || 'Você está bloqueado.' }}
                </div>
                <template v-else>
                    <div class="chat-input-wrapper">
                        <!-- Media Upload (Only for users) -->
                        <button 
                            v-if="isLoggedIn" 
                            @click="triggerFileInput" 
                            class="chat-media-btn"
                            :title="t.chat?.send_media || 'Mídia'"
                        >
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <input 
                            type="file" 
                            ref="fileInput" 
                            class="hidden" 
                            @change="handleFileSelection" 
                            accept="image/*,video/*"
                        />

                        <input 
                            v-model="newMessage" 
                            @keyup.enter="sendMessage"
                            type="text" 
                            :placeholder="isLoggedIn ? (t.chat?.placeholder_user || 'Digite sua mensagem...') : (t.chat?.placeholder_guest || 'Guest...')"
                            class="chat-input"
                            :disabled="isLoading || cooldown > 0"
                        />
                        
                        <button 
                            @click="sendMessage" 
                            class="chat-send-btn"
                            :disabled="isLoading || cooldown > 0 || (!newMessage.trim() && !selectedFile)"
                        >
                            <span v-if="cooldown > 0">{{ cooldown }}s</span>
                            <i v-else-if="isLoading" class="fas fa-spinner fa-spin"></i>
                            <i v-else class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div v-if="!isLoggedIn" class="chat-guest-hint" v-html="t.chat?.guest_hint?.replace(':register_link', `<a href='/register'>${t.chat?.register_text || 'Register'}</a>`) || 'Guest'">
                    </div>
                </template>
            </div>
        </div>

        <!-- Report Modal -->
        <div v-if="showReportModal" class="chat-modal-overlay fade-in">
            <div class="chat-modal scale-in">
                <div class="chat-modal-header">
                    <span>{{ t.chat?.report || 'Denunciar' }}</span>
                    <button @click="closeReportModal" class="chat-modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="chat-modal-body">
                    <p>Por que você está denunciando esta mensagem?</p>
                    <div class="chat-report-reasons">
                        <label v-for="(label, key) in reportReasons" :key="key" class="chat-report-reason">
                            <input type="radio" :value="key" v-model="selectedReason" />
                            <span>{{ label }}</span>
                        </label>
                    </div>
                </div>
                <div class="chat-modal-footer">
                    <button @click="closeReportModal" class="chat-btn-secondary">Cancelar</button>
                    <button @click="confirmReport" class="chat-btn-primary">Confirmar Denúncia</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.chat-manager {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 9999;
}

/* Floating Button */
.chat-float-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    box-shadow: 0 4px 15px rgba(255, 51, 102, 0.4);
    cursor: pointer;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
}

.chat-float-btn:hover {
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 6px 20px rgba(255, 51, 102, 0.5);
}

.chat-float-btn.active {
    background: var(--dark-card);
    color: var(--text-primary);
    transform: rotate(90deg);
}

.chat-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ff0000;
    color: white;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    border: 2px solid var(--dark);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 0, 0, 0.7); }
    70% { transform: scale(1.1); box-shadow: 0 0 0 10px rgba(255, 0, 0, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 0, 0, 0); }
}

/* Chat Window */
.chat-window {
    position: absolute;
    bottom: 80px;
    right: 0;
    width: 380px;
    height: 550px;
    background: var(--dark-lighter);
    border: 1px solid var(--border);
    border-radius: 20px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    overflow: hidden;
}

.chat-header {
    background: var(--dark-card);
    padding: 15px;
    border-bottom: 1px solid var(--border);
}

.chat-header-info {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 10px;
}

.chat-rooms {
    display: flex;
    gap: 10px;
}

.chat-rooms button {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}

.chat-rooms button.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 15px;
    display: flex;
    flex-direction: column;
    gap: 15px;
    scrollbar-width: thin;
    scrollbar-color: var(--primary) transparent;
}

.chat-messages::-webkit-scrollbar {
    width: 4px;
}

.chat-messages::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 10px;
}

.chat-empty {
    text-align: center;
    color: var(--text-muted);
    font-size: 0.9rem;
    margin-top: 50px;
}

.chat-msg-item {
    display: flex;
    gap: 10px;
    animation: fadeIn 0.3s ease;
}

.chat-msg-avatar img {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    object-fit: cover;
}

.chat-msg-content {
    flex: 1;
    background: var(--dark-card);
    padding: 10px;
    border-radius: 0 15px 15px 15px;
    position: relative;
}

.chat-msg-item.is-me .chat-msg-content {
    background: rgba(255, 51, 102, 0.1);
    border: 1px solid rgba(255, 51, 102, 0.2);
}

.chat-msg-info {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.chat-msg-user {
    font-weight: 700;
    font-size: 0.8rem;
    color: var(--primary);
}

.chat-msg-time {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.chat-msg-text {
    font-size: 0.9rem;
    word-break: break-word;
}

.chat-msg-media {
    margin-top: 8px;
    border-radius: 10px;
    overflow: hidden;
}

.chat-msg-media img, .chat-msg-media video {
    width: 100%;
    max-height: 200px;
    object-fit: cover;
    display: block;
}

.chat-msg-report {
    position: absolute;
    top: 5px;
    right: -25px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 0.7rem;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s;
}

.chat-msg-item:hover .chat-msg-report {
    opacity: 1;
}

.chat-msg-report:hover {
    color: var(--danger);
}

.chat-msg-menu-wrapper {
    position: absolute;
    top: 5px;
    right: 5px;
}

.chat-msg-menu-btn {
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 0.9rem;
    cursor: pointer;
    padding: 5px;
    transition: color 0.2s;
}

.chat-msg-menu-btn:hover {
    color: var(--text-primary);
}

.chat-msg-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: var(--dark-lighter);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 5px;
    min-width: 120px;
    z-index: 10;
    box-shadow: 0 5px 15px rgba(0,0,0,0.5);
}

.chat-dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 8px 12px;
    background: transparent;
    border: none;
    color: var(--text-primary);
    font-size: 0.8rem;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.2s;
    text-align: left;
}

.chat-dropdown-item:hover {
    background: rgba(255, 255, 255, 0.05);
}

.chat-dropdown-item.text-danger {
    color: var(--danger);
}

.scale-in {
    animation: scaleIn 0.2s ease-out;
    transform-origin: top right;
}

@keyframes scaleIn {
    from { transform: scale(0.9); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

.chat-footer {
    padding: 15px;
    background: var(--dark-card);
    border-top: 1px solid var(--border);
}

.chat-error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--danger);
    padding: 8px;
    border-radius: 8px;
    font-size: 0.8rem;
    margin-bottom: 10px;
    text-align: center;
}

.chat-input-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--dark-lighter);
    padding: 5px 5px 5px 15px;
    border-radius: 30px;
    border: 1px solid var(--border);
}

.chat-input {
    flex: 1;
    background: transparent;
    border: none;
    color: var(--text-primary);
    font-size: 0.9rem;
    outline: none;
}

.chat-media-btn {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 1rem;
    transition: color 0.2s;
}

.chat-media-btn:hover {
    color: var(--primary);
}

.chat-send-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.chat-send-btn:disabled {
    background: var(--border);
    cursor: not-allowed;
    color: var(--text-muted);
}

.chat-guest-hint {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 8px;
    text-align: center;
}

.chat-guest-hint a {
    color: var(--primary);
    text-decoration: underline;
}

.hidden {
    display: none;
}

/* Animations */
.fade-in-up {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Mobile Responsive */
@media (max-width: 480px) {
    .chat-window {
        width: calc(100vw - 40px);
        height: 70vh;
        right: -10px;
    }
    .chat-manager {
        bottom: 20px;
        right: 20px;
    }
}

.chat-media-preview-container {
    padding: 10px;
    background: var(--dark-lighter);
    border-top: 1px solid var(--border);
    margin: -15px -15px 10px -15px;
}

.chat-media-preview {
    position: relative;
    width: 80px;
    height: 80px;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid var(--primary);
}

.chat-media-preview img, .chat-media-preview video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.chat-preview-remove {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.7rem;
    transition: background 0.2s;
}

.chat-preview-remove:hover {
    background: var(--danger);
}

/* Modals */
.chat-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    padding: 20px;
}

.chat-modal {
    background: var(--dark-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    width: 100%;
    max-width: 350px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.chat-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 700;
}

.chat-modal-close {
    background: transparent;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 1.1rem;
}

.chat-modal-body {
    padding: 20px;
}

.chat-modal-body p {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin-bottom: 15px;
}

.chat-report-reasons {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.chat-report-reason {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.chat-report-reason:hover {
    border-color: var(--primary);
}

.chat-report-reason input {
    accent-color: var(--primary);
}

.chat-report-reason span {
    font-size: 0.9rem;
}

.chat-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 10px;
}

.chat-btn-primary, .chat-btn-secondary {
    flex: 1;
    padding: 10px;
    border-radius: 10px;
    border: none;
    font-weight: 700;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.chat-btn-primary {
    background: var(--primary);
    color: white;
}

.chat-btn-primary:hover {
    background: var(--primary-dark);
}

.chat-btn-secondary {
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-primary);
}

.chat-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.1);
}
</style>

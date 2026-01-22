<script setup>
import { computed } from 'vue';

const props = defineProps({
    zone: {
        type: [String, Number],
        required: true
    },
    width: {
        type: [String, Number],
        default: '774'
    },
    height: {
        type: [String, Number],
        default: '290'
    },
    count: {
        type: Number,
        default: 1
    }
});

const adContent = computed(() => {
    const singleAd = `
        <div class="ad-container">
            <script type="text/javascript" data-cfasync="false" async src="https://poweredby.jads.co/js/jads.js"><\/script>
            <ins id="${props.zone}" data-width="${props.width}" data-height="${props.height}"></ins>
            <script type="text/javascript" data-cfasync="false" async>(adsbyjuicy = window.adsbyjuicy || []).push({'adzone':${props.zone}});<\/script>
        </div>
    `;

    let ads = '';
    for (let i = 0; i < props.count; i++) {
        ads += singleAd;
    }

    return `
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { margin: 0; padding: 0; overflow: hidden; background: transparent; }
                .wrapper { display: flex; flex-direction: row; justify-content: center; align-items: center; gap: 10px; flex-wrap: nowrap; }
                .ad-container { display: inline-block; }
            </style>
        </head>
        <body>
            <div class="wrapper">
                ${ads}
            </div>
        </body>
        </html>
    `;
});

// Calculate total iframe width based on count and gaps
const totalWidth = computed(() => {
    const w = parseInt(props.width);
    const gap = 10;
    return (w * props.count) + (gap * (props.count - 1));
});
</script>

<template>
    <div class="ad-wrapper" :style="{ minHeight: height + 'px' }">
        <div class="ad-scaler">
            <iframe
                :srcdoc="adContent"
                :width="totalWidth"
                :height="height"
                frameborder="0"
                scrolling="no"
                class="ad-iframe"
            ></iframe>
        </div>
    </div>
</template>

<style scoped>
.ad-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 20px auto;
    width: 100%;
    overflow: hidden;
}

.ad-scaler {
    max-width: 100%;
    overflow: auto;
    display: flex;
    justify-content: center;
}

.ad-iframe {
    border: none;
    max-width: none; /* Allow iframe to be its original size inside scaler */
}

@media (max-width: 768px) {
    .ad-wrapper {
        margin: 10px auto;
    }
    
    /* Simple scaling for mobile if it's too wide */
    .ad-scaler {
        transform-origin: top center;
        width: 100%;
    }
}
</style>

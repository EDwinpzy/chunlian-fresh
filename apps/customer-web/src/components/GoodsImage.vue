<script setup lang="ts">
import { computed, ref } from 'vue'

/**
 * 商品图。
 *
 * 【为什么需要一个专门的组件，而不是直接 <img>】
 * 本店的商品图是店主用手机拍的，会出现三种情况：
 *   1. 还没上传图（新上架的商品）
 *   2. 图挂了（CDN/路径变更）
 *   3. 图正常但比例不是 1:1
 * 三种情况都必须保持卡片布局不塌陷 —— 否则商品网格会参差不齐。
 * 这里统一给 1:1 容器，加载失败或缺失时退化为品牌色占位图。
 */
const props = withDefaults(
  defineProps<{
    src?: string
    alt?: string
    /** 占位图里显示的首字（通常是商品名第一个字） */
    fallbackText?: string
    radius?: string
  }>(),
  { src: '', alt: '', fallbackText: '', radius: 'var(--radius-lg)' },
)

const failed = ref(false)
const showImage = computed(() => props.src !== '' && !failed.value)
const initial = computed(() => {
  const text = props.fallbackText || props.alt || '鲜'

  return text.trim().slice(0, 1)
})
</script>

<template>
  <div class="goods-image" :style="{ borderRadius: radius }">
    <img
      v-if="showImage"
      :src="src"
      :alt="alt"
      loading="lazy"
      decoding="async"
      @error="failed = true"
    />
    <div v-else class="goods-image__placeholder">
      <span>{{ initial }}</span>
    </div>
  </div>
</template>

<style scoped>
.goods-image {
  position: relative;
  width: 100%;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  background: var(--color-brand-100);
}

.goods-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.goods-image__placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  /* 商品图未就位时的中性占位。
     用品牌 50 号色的**平涂**而不是渐变 —— 渐变在不同屏上色偏不可控，
     且会让这块区域显得「比页面更重要」。平涂明确表达「这里还空着」，
     不假装是商品图（方案 §4.5：不能用示意图冒充真实商品）。 */
  background: var(--color-media-placeholder);
}

.goods-image__placeholder span {
  font-size: var(--font-display);
  font-weight: var(--weight-semibold);
  color: var(--color-brand-300);
  letter-spacing: 2px;
}
</style>

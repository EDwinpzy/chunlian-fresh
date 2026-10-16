<script setup lang="ts">
import { onMounted } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import { useShopStore } from '@/stores/shop'

/**
 * 关于与政策（方案 §5.3 UI-C17）。
 *
 * 方案要求「合规展示，不强要头像昵称」。这里把三件顾客最关心的事讲清楚：
 * 我们收什么数据、配送怎么算、售后怎么走。
 */
const shop = useShopStore()
onMounted(() => shop.loadSetting(true).catch(() => null))
</script>

<template>
  <div class="page">
    <PageHeader title="关于与政策" />

    <div class="page-body page-body--tabbar">
      <section class="card about-card">
        <h2 class="about-title">{{ shop.setting?.shop_name || '店铺' }}</h2>
        <p v-if="shop.setting?.shop_description" class="about-desc">{{ shop.setting.shop_description }}</p>
        <p v-if="shop.setting?.shop_address" class="about-desc">店铺地址：{{ shop.setting.shop_address }}</p>
        <p v-if="shop.setting?.business_hours" class="about-desc">营业时间：{{ shop.setting.business_hours }}</p>
        <p v-if="shop.setting" class="about-desc">店铺电话：{{ shop.setting.phone || '店主暂未留电话' }}</p>
        <p v-if="shop.setting" class="about-desc">营业状态：{{ shop.setting.is_open ? '营业中' : '已打烊' }}</p>
      </section>

      <section class="section">
        <h2 class="section-title">我们收集哪些信息</h2>
        <div class="card">
          <p class="about-p">· 手机号：用于登录与配送时联系你。</p>
          <p class="about-p">· 收货人姓名与详细地址：仅用于本次配送。</p>
          <p class="about-p">· 订单内容与金额：用于履约与售后。</p>
          <p class="about-p about-p--strong">
            我们不获取你的位置权限、通讯录、相册；不使用地图定位，
            地址由你自己填写。
          </p>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">配送规则</h2>
        <div class="card">
          <p v-if="shop.setting?.delivery_description" class="about-p">{{ shop.setting.delivery_description }}</p>
          <p class="about-p">· 「尽快配送」表示下单后尽快安排，不承诺固定分钟数。</p>
          <p class="about-p">· 「预约送达」是你希望送达的时间，实际以配送情况为准。</p>
          <p class="about-p">· 配送费按店铺统一标准收取，在结算页明确显示。</p>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">修改、取消与售后</h2>
        <div class="card">
          <p class="about-p">
            · 店主点击「开始配送」之前，你可以随时自行修改商品、数量、地址、时间、备注，或直接取消订单。
          </p>
          <p class="about-p">
            · 扫码预付订单增额需店主核实补款，减额退款由店主实际退款后核实，状态以订单为准。
          </p>
          <p class="about-p">
            · 店主开始配送后，订单不能再自行修改或取消，请通过「联系店主」协商售后。
          </p>
          <p class="about-p">
            · 货到付款的订单在送达时收款；若当时未收，订单会保留待收货款记录，
            请与店主确认后核销。
          </p>
        </div>
      </section>

      <p class="mini about-foot">
        本页内容为店铺自述的经营与售后规则，不构成法律意见；如需正式协议文本，请与店主另行签署。
      </p>
      <p class="mini about-foot">版本 1.0.0 · 顾客端网页版</p>
    </div>
  </div>
</template>

<style scoped>
.about-card {
  margin-top: 12px;
}

.about-title {
  margin: 0;
  font-size: var(--font-section);
  font-weight: var(--weight-bold);
  color: var(--color-brand-700);
}

.about-desc, .about-p { white-space: pre-wrap; overflow-wrap: anywhere; }

.about-desc {
  margin: 8px 0 0;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  line-height: 1.7;
}

.about-p {
  margin: 0 0 6px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  line-height: 1.7;
}

.about-p:last-child {
  margin-bottom: 0;
}

.about-p--strong {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--color-divider);
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.about-foot {
  margin: var(--space-6) 0 0;
  text-align: center;
}
</style>

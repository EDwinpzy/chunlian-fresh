package com.chunlian.customer.data

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.chunlian.androidupdate.AndroidAppUpdater
import com.chunlian.customer.BuildConfig
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

enum class CustomerTab(val label: String) { HOME("首页"), CATEGORY("分类"), CART("购物车"), ORDERS("订单"), PROFILE("我的") }

data class CustomerUiState(
    val tab: CustomerTab = CustomerTab.HOME,
    val shop: ShopSetting = ShopSetting(),
    val categories: List<Category> = emptyList(),
    val products: List<GoodsSummary> = emptyList(),
    val keyword: String = "",
    val categoryId: String? = null,
    val cart: List<CartItem> = emptyList(),
    val product: GoodsDetail? = null,
    val user: AuthUser? = null,
    val addresses: List<Address> = emptyList(),
    val orders: List<Order> = emptyList(),
    val ordersKeyword: String = "",
    val ordersLoading: Boolean = false,
    val order: Order? = null,
    val preview: CheckoutPreview? = null,
    val revisionPreview: RevisionPreview? = null,
    val revisionPreviewItems: List<OrderItemRequest>? = null,
    val revisionCatalog: List<GoodsSummary> = emptyList(),
    val revisionCatalogKeyword: String = "",
    val revisionProduct: GoodsDetail? = null,
    val loading: Boolean = false,
    val busy: Boolean = false,
    val error: String? = null,
    val notice: String? = null,
)

class CustomerViewModel(application: Application) : AndroidViewModel(application) {
    private val repository = CustomerRepository(application)
    val appUpdater = AndroidAppUpdater(
        context = application,
        applicationId = BuildConfig.APPLICATION_ID,
        currentVersionCode = BuildConfig.VERSION_CODE.toLong(),
        baseUrl = BuildConfig.API_BASE_URL,
    )
    val secureTransport: Boolean get() = repository.secureTransport
    val identityTransportAllowed: Boolean get() = repository.identityTransportAllowed
    val loopbackTestTransport: Boolean get() = repository.loopbackTestTransport
    private val _state = MutableStateFlow(CustomerUiState(cart = repository.session.cart()))
    val state: StateFlow<CustomerUiState> = _state.asStateFlow()
    private var checkoutPreviewRequestId = 0L
    private var catalogRequestId = 0L
    private var shopSettingRequestId = 0L
    private var orderSearchJob: Job? = null
    private var orderLoadJob: Job? = null
    private var orderRequestId = 0L

    init {
        loadCatalog()
        if (!repository.identityTransportAllowed && repository.session.token().isNotBlank()) repository.session.clear()
        if (repository.identityTransportAllowed && repository.session.token().isNotBlank()) loadProfile()
    }

    fun selectTab(tab: CustomerTab) {
        _state.update { it.copy(tab = tab, error = null, notice = null) }
        when (tab) {
            CustomerTab.HOME -> {
                _state.update { it.copy(categoryId = null) }
                loadCatalog(categoryId = null)
            }
            CustomerTab.CATEGORY -> loadCatalog()
            CustomerTab.ORDERS -> loadOrders()
            CustomerTab.PROFILE -> if (repository.session.token().isNotBlank() && repository.identityTransportAllowed) loadProfile()
            CustomerTab.CART -> Unit
        }
        if (tab != CustomerTab.HOME && tab != CustomerTab.CATEGORY) loadShopSetting()
    }

    fun refreshStorefront() {
        if (_state.value.tab == CustomerTab.HOME || _state.value.tab == CustomerTab.CATEGORY) loadCatalog()
        else loadShopSetting()
    }

    private fun loadShopSetting() {
        val requestId = ++shopSettingRequestId
        launch(action = {
            val shop = repository.unwrap { repository.api.shop() }
            if (requestId == shopSettingRequestId) _state.update { it.copy(shop = shop) }
        })
    }

    fun loadCatalog(keyword: String = _state.value.keyword, categoryId: String? = _state.value.categoryId) {
        val requestId = ++catalogRequestId
        val shopRequestId = ++shopSettingRequestId
        launch(
            busy = _state.value.products.isEmpty(),
            loading = true,
            action = {
                val shop = repository.unwrap { repository.api.shop() }
                val categories = repository.unwrap { repository.api.categories() }
                val activeCategoryId = categoryId?.takeIf { requestedId ->
                    categories.any { category ->
                        category.id == requestedId || category.children.any { child -> child.id == requestedId }
                    }
                }
                val products = repository.unwrap {
                    repository.api.goods(categoryId = activeCategoryId, keyword = keyword.trim().takeIf(String::isNotEmpty))
                }.list
                if (requestId == catalogRequestId) {
                    _state.update {
                        it.copy(categories = categories, products = products, keyword = keyword, categoryId = activeCategoryId)
                    }
                }
                if (shopRequestId == shopSettingRequestId) _state.update { it.copy(shop = shop) }
            },
        )
    }

    fun setSearch(keyword: String) { _state.update { it.copy(keyword = keyword) } }
    fun searchCatalog(keyword: String) {
        _state.update { it.copy(keyword = keyword, categoryId = null, tab = CustomerTab.CATEGORY) }
        loadCatalog(keyword = keyword, categoryId = null)
    }
    fun setCategory(categoryId: String?) {
        _state.update { it.copy(categoryId = categoryId, tab = CustomerTab.CATEGORY) }
        loadCatalog(categoryId = categoryId)
    }

    fun openProduct(id: String) = launch(action = {
        val product = repository.unwrap { repository.api.goodsDetail(id) }
        _state.update { it.copy(product = product, error = null) }
    })
    fun closeProduct() { _state.update { it.copy(product = null) } }

    fun loadRevisionCatalog(keyword: String = _state.value.revisionCatalogKeyword) = launch(action = {
        val products = repository.unwrap {
            repository.api.goods(keyword = keyword.trim().takeIf(String::isNotEmpty), page = 1, pageSize = 50)
        }.list
        _state.update { it.copy(revisionCatalog = products, revisionCatalogKeyword = keyword) }
    })

    fun openRevisionProduct(id: String) = launch(action = {
        val product = repository.unwrap { repository.api.goodsDetail(id) }
        _state.update { it.copy(revisionProduct = product) }
    })

    fun closeRevisionProduct() { _state.update { it.copy(revisionProduct = null) } }

    fun addToCart(product: GoodsDetail, sku: Sku, count: Int): Boolean {
        val bounds = purchaseQuantityBounds(product.buyMinNumber, product.buyMaxNumber, sku.inventory)
        if (!bounds.canPurchase || count !in bounds.minimum..bounds.maximum || sku.isOutOfStock) return false
        val old = _state.value.cart.firstOrNull { it.skuId == sku.skuId }
        val nextQty = (old?.qty ?: 0) + count
        if (nextQty > bounds.maximum) {
            notify("最多可购买 ${bounds.maximum} 件")
            return false
        }
        val item = CartItem(
            sku.skuId, product.id, product.title, sku.specText.ifBlank { product.inventoryUnit },
            product.imageUrl, sku.priceFen, nextQty, sku.inventory, bounds.minimum, bounds.maximum,
        )
        val cart = _state.value.cart.filterNot { it.skuId == sku.skuId } + item
        repository.session.saveCart(cart)
        _state.update { it.copy(cart = cart, product = null, notice = "已加入购物车") }
        return true
    }

    fun changeCart(skuId: String, delta: Int) {
        val cart = _state.value.cart.mapNotNull { item ->
            if (item.skuId != skuId) item
            else {
                val maximum = minOf(item.inventory.coerceAtLeast(0), item.maximumQty.coerceAtLeast(0))
                val minimum = item.minimumQty.coerceAtLeast(1)
                val nextQty = item.qty + delta
                if (delta < 0 && nextQty < minimum) null
                else item.copy(qty = nextQty.coerceAtMost(maximum)).takeIf { it.qty > 0 }
            }
        }
        repository.session.saveCart(cart)
        _state.update { it.copy(cart = cart) }
    }
    fun removeCartItem(skuId: String) {
        val cart = _state.value.cart.filterNot { it.skuId == skuId }
        repository.session.saveCart(cart)
        _state.update { it.copy(cart = cart) }
    }
    fun clearCart() {
        repository.session.saveCart(emptyList())
        _state.update { it.copy(cart = emptyList()) }
    }

    fun login(phone: String, password: String, nickname: String? = null) = launch(action = {
        if (!repository.identityTransportAllowed) throw CustomerApiException("当前服务器尚未启用 HTTPS。为保护手机号和密码，登录/注册暂不可用。")
        if (!isValidMainlandMobile(phone)) throw CustomerApiException("请输入正确的手机号")
        if (password.length !in 6..32) throw CustomerApiException("密码长度需为 6 至 32 位")
        val result = if (nickname == null) repository.unwrap { repository.api.login(LoginRequest(phone, password)) }
        else repository.unwrap { repository.api.register(RegisterRequest(phone, password, nickname)) }
        repository.session.saveToken(result.token)
        _state.update { it.copy(user = result.user, notice = "登录成功", error = null) }
        loadAddresses()
    })

    fun loadProfile() = launch(action = {
        if (!repository.identityTransportAllowed) return@launch
        val user = repository.unwrap { repository.api.me() }
        _state.update { it.copy(user = user) }
    })

    fun updateProfile(nickname: String) = launch(action = {
        requireLogin()
        val clean = nickname.trim()
        if (clean.isBlank()) throw CustomerApiException("昵称不能为空")
        val user = repository.unwrap { repository.api.updateProfile(ProfileRequest(nickname = clean)) }
        _state.update { it.copy(user = user, notice = "昵称已更新") }
    })

    fun logout() = launch(action = {
        if (repository.identityTransportAllowed && repository.session.token().isNotBlank()) runCatching { repository.unwrap { repository.api.logout() } }
        repository.session.clear()
        orderSearchJob?.cancel()
        orderLoadJob?.cancel()
        orderRequestId += 1
        _state.update { it.copy(user = null, addresses = emptyList(), orders = emptyList(), ordersKeyword = "", ordersLoading = false, notice = "已退出登录") }
    })

    fun loadAddresses() = launch(action = {
        requireLogin()
        val addresses = repository.unwrap { repository.api.addresses() }
        _state.update { it.copy(addresses = addresses) }
    })

    fun saveAddress(id: String?, name: String, phone: String, address: String, isDefault: Boolean) = launch(action = {
        requireLogin()
        if (name.trim().isEmpty() || !isValidMainlandMobile(phone) || address.trim().length < 4) {
            throw CustomerApiException("请填写收货人、正确手机号和详细地址")
        }
        val result = repository.unwrap { repository.api.saveAddress(AddressSaveRequest(id, name.trim(), phone.trim(), address.trim(), isDefault)) }
        _state.update { it.copy(addresses = result, notice = "地址已保存") }
    })

    fun deleteAddress(id: String) = launch(action = {
        requireLogin()
        val result = repository.unwrap { repository.api.deleteAddress(id) }
        _state.update { it.copy(addresses = result, notice = "地址已删除") }
    })

    fun setDefaultAddress(id: String) = launch(action = {
        requireLogin()
        val result = repository.unwrap { repository.api.setDefaultAddress(id) }
        _state.update { it.copy(addresses = result, notice = "已设为默认地址") }
    })

    fun previewCheckout(mode: String, expectedAt: String?) {
        val requestId = ++checkoutPreviewRequestId
        launch(action = {
            requireLogin()
            val items = _state.value.cart.map { OrderItemRequest(it.skuId, it.qty) }
            if (items.isEmpty()) throw CustomerApiException("购物车为空")
            _state.update { it.copy(preview = null) }
            val preview = repository.unwrap { repository.api.preview(PreviewRequest(items, mode, expectedAt)) }
            if (requestId == checkoutPreviewRequestId) _state.update { it.copy(preview = preview, error = null) }
        })
    }

    fun clearCheckoutPreview() {
        checkoutPreviewRequestId++
        _state.update { it.copy(preview = null) }
    }

    fun submitOrder(name: String, phone: String, address: String, mode: String, expectedAt: String?, paymentMethod: String, remark: String) = launch(action = {
        requireLogin()
        if (!repository.identityTransportAllowed) throw CustomerApiException("当前服务器尚未启用 HTTPS，暂不能提交订单。请先为服务器配置 HTTPS。")
        if (paymentMethod !in setOf("COD", "OFFLINE_QR")) throw CustomerApiException("付款方式暂不可用")
        if (paymentMethod == "OFFLINE_QR" && _state.value.shop.paymentQrUrl.isBlank()) throw CustomerApiException("店铺还未上传收款二维码，暂不能选择预付扫码")
        val request = CreateOrderRequest(
            _state.value.cart.map { OrderItemRequest(it.skuId, it.qty) },
            ContactRequest(name.trim(), phone.trim(), address.trim()),
            mode,
            expectedAt,
            paymentMethod,
            remark.trim(),
        )
        val key = repository.idempotencyKey("customer.order.create", request.toString())
        val result = repository.unwrap {
            repository.api.createOrder(request, key)
        }
        repository.clearIdempotencyKey("customer.order.create")
        clearCart()
        _state.update { it.copy(order = result.order, notice = result.paymentHint.ifBlank { "订单已提交" }, tab = CustomerTab.ORDERS) }
        loadOrders()
    })

    fun setOrdersKeyword(keyword: String) {
        if (_state.value.ordersKeyword == keyword) return
        orderSearchJob?.cancel()
        orderLoadJob?.cancel()
        orderRequestId += 1
        _state.update { it.copy(ordersKeyword = keyword, ordersLoading = true) }
        orderSearchJob = viewModelScope.launch {
            delay(350)
            loadOrdersFor(keyword)
        }
    }

    fun loadOrders() {
        orderSearchJob?.cancel()
        loadOrdersFor(_state.value.ordersKeyword)
    }

    private fun loadOrdersFor(keyword: String) {
        val requestId = ++orderRequestId
        orderLoadJob?.cancel()
        _state.update { it.copy(ordersLoading = true, error = null) }
        orderLoadJob = viewModelScope.launch {
            try {
                val orders = fetchOrders(keyword)
                if (requestId == orderRequestId) _state.update { it.copy(orders = orders) }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: Exception) {
                if (requestId == orderRequestId) _state.update { it.copy(error = error.message ?: "订单加载失败，请稍后重试") }
            } finally {
                if (requestId == orderRequestId) _state.update { it.copy(ordersLoading = false) }
            }
        }
    }

    private suspend fun fetchOrders(keyword: String): List<Order> {
        requireLogin()
        return repository.unwrap {
            repository.api.orders(
                status = null,
                page = 1,
                pageSize = 30,
                keyword = keyword.trim().takeIf(String::isNotEmpty),
            )
        }.list
    }

    fun openOrder(id: String) = launch(action = {
        requireLogin()
        val order = repository.unwrap { repository.api.orderDetail(id) }
        _state.update { it.copy(order = order, revisionPreview = null) }
    })

    fun refreshOrder(id: String) = launch(action = {
        requireLogin()
        val order = repository.unwrap { repository.api.orderDetail(id) }
        val orders = fetchOrders(_state.value.ordersKeyword)
        _state.update { it.copy(order = order, orders = orders, revisionPreview = null, revisionPreviewItems = null, notice = "订单状态已刷新") }
    })

    fun updateOrderInfo(order: Order, name: String, phone: String, address: String, mode: String, expectedAt: String?, remark: String) = launch(action = {
        requireLogin()
        if (!repository.identityTransportAllowed) throw CustomerApiException("当前服务器尚未启用 HTTPS，暂不能修改订单信息。")
        if (name.trim().isEmpty() || !isValidMainlandMobile(phone) || address.trim().length < 4) {
            throw CustomerApiException("请填写收货人、正确手机号和详细地址")
        }
        if (mode == "SCHEDULED" && expectedAt.isNullOrBlank()) throw CustomerApiException("请填写有效的未来送达时间")
        val updated = repository.unwrap {
            repository.api.updateOrderInfo(
                order.id,
                OrderInfoRequest(
                    version = order.version,
                    contact = ContactRequest(name.trim(), phone.trim(), address.trim()),
                    deliveryMode = mode,
                    expectedAt = expectedAt,
                    remark = remark.trim(),
                ),
            )
        }
        _state.update { it.copy(order = updated, notice = "订单信息已更新") }
    })

    fun cancelOrder(order: Order) = launch(action = {
        requireLogin()
        if (!repository.identityTransportAllowed) throw CustomerApiException("当前服务器尚未启用 HTTPS，暂不能取消订单。")
        val operation = "customer.order.cancel:${order.id}"
        val request = CancelOrderRequest(order.version, "顾客主动取消")
        val key = repository.idempotencyKey(operation, request.toString())
        val result = repository.unwrap { repository.api.cancelOrder(order.id, request, key) }
        repository.clearIdempotencyKey(operation)
        _state.update { it.copy(order = result, notice = "订单已取消") }
        loadOrders()
    })

    fun receiveOrder(order: Order) = launch(action = {
        requireLogin()
        if (!repository.identityTransportAllowed) throw CustomerApiException("当前服务器尚未启用 HTTPS，暂不能确认收货。")
        val operation = "customer.order.receive:${order.id}"
        val request = ReceiveOrderRequest(order.version)
        val key = repository.idempotencyKey(operation, request.toString())
        val result = repository.unwrap { repository.api.receiveOrder(order.id, request, key) }
        repository.clearIdempotencyKey(operation)
        _state.update { it.copy(order = result, notice = "已确认收货") }
        loadOrders()
    })

    fun previewRevision(order: Order, items: List<OrderItemRequest>) = launch(action = {
        requireLogin()
        val normalized = normalizeOrderItems(items)
        if (normalized.isEmpty()) throw CustomerApiException("订单至少需要保留一件商品")
        _state.update { it.copy(revisionPreview = null, revisionPreviewItems = null) }
        val preview = repository.unwrap { repository.api.revisionPreview(order.id, ItemsRequest(normalized)) }
        _state.update { it.copy(revisionPreview = preview, revisionPreviewItems = normalized) }
    })

    fun submitRevision(order: Order, items: List<OrderItemRequest>) = launch(action = {
        requireLogin()
        if (!repository.identityTransportAllowed) throw CustomerApiException("当前服务器尚未启用 HTTPS，暂不能修改订单。")
        val normalized = normalizeOrderItems(items)
        val preview = _state.value.revisionPreview ?: throw CustomerApiException("请先查看修改后的金额")
        if (_state.value.revisionPreviewItems != normalized) throw CustomerApiException("商品数量已变化，请重新查看金额")
        if (preview.deltaFen > 0 && order.paidFen > 0 && order.paymentMethod != "OFFLINE_QR") {
            throw CustomerApiException("已付款订单增加金额需要补款；当前服务器未配置在线支付，原订单保持不变。")
        }
        val operation = "customer.order.revision:${order.id}"
        val request = ItemsRequest(normalized, clientVersion = order.version)
        val key = repository.idempotencyKey(operation, request.toString())
        val result = repository.unwrap { repository.api.revisionSubmit(order.id, request, key) }
        // The revision-submit DTO serializes a domain order with different field names and
        // omits customer-only capabilities. Always reload the customer detail representation.
        val updatedOrder = repository.unwrap { repository.api.orderDetail(order.id) }
        repository.clearIdempotencyKey(operation)
        val notice = when {
            result.requiresTopup -> "商品修改已提交，请按差额扫码并等待店主核实后生效。"
            result.revisionState == "APPLYING" -> "商品修改已提交，退款由店主人工处理。"
            !result.changed -> "订单内容没有变化。"
            else -> "商品修改已提交。"
        }
        _state.update { it.copy(order = updatedOrder, notice = notice, revisionPreview = null, revisionPreviewItems = null) }
        loadOrders()
    })

    fun clearNotice() { _state.update { it.copy(notice = null, error = null) } }
    fun clearError() { _state.update { it.copy(error = null) } }
    private fun notify(message: String) { _state.update { it.copy(notice = message) } }
    private fun requireLogin() {
        if (!repository.identityTransportAllowed) throw CustomerApiException("服务器尚未启用 HTTPS，登录及个人数据暂不可用。")
        if (repository.session.token().isBlank()) throw CustomerApiException("请先登录")
    }

    private fun launch(busy: Boolean = true, loading: Boolean = false, action: suspend () -> Unit) {
        viewModelScope.launch {
            _state.update { it.copy(busy = busy, loading = loading, error = null, notice = null) }
            try {
                action()
            } catch (error: Exception) {
                _state.update { it.copy(error = error.message ?: "操作失败，请稍后重试") }
            } finally {
                _state.update { it.copy(busy = false, loading = false) }
            }
        }
    }
}

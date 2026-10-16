package com.chunlian.merchant.feature.auth

import android.os.Build
import androidx.compose.foundation.background
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.network.ApiException
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.PrimaryButton
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * 商家登录（方案 §6.3 UI-A04）。
 *
 * 【为什么账号密码不走「记住密码」】
 * 这是店主的管理员账号，能看全部订单、能登记收款。
 * 记住密码意味着任何人拿到手机就能动钱。
 * 只记住**账号**（少输一次），密码每次手输。
 */
class LoginViewModel(
    private val repo: MerchantRepository,
    private val session: com.chunlian.merchant.core.network.SessionStore,
) : ViewModel() {

    data class UiState(
        val username: String = "",
        val password: String = "",
        val loading: Boolean = false,
        val error: String? = null,
        val success: Boolean = false,
    )

    private val _state = MutableStateFlow(UiState(username = session.lastUsername))
    val state: StateFlow<UiState> = _state.asStateFlow()

    fun onUsernameChange(value: String) {
        _state.value = _state.value.copy(username = value.trim(), error = null)
    }

    fun onPasswordChange(value: String) {
        _state.value = _state.value.copy(password = value, error = null)
    }

    fun submit() {
        val current = _state.value
        if (current.username.isBlank() || current.password.isBlank()) {
            _state.value = current.copy(error = "请填写账号与密码")

            return
        }
        if (current.loading) return

        _state.value = current.copy(loading = true, error = null)

        viewModelScope.launch {
            try {
                // device_id 用于服务端的会话撤销与限速；机型信息不含个人数据
                val deviceId = "${Build.MANUFACTURER}-${Build.MODEL}".replace(" ", "_").take(60)
                repo.login(current.username, current.password, deviceId)
                _state.value = _state.value.copy(loading = false, success = true, password = "")
            } catch (e: ApiException) {
                _state.value = _state.value.copy(loading = false, error = e.message)
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(loading = false, error = e.message)
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = "登录失败：${e.message ?: "未知错误"}")
            }
        }
    }
}

@Composable
fun LoginScreen(onLoggedIn: () -> Unit) {
    val app = MerchantApp.instance
    val vm = remember { LoginViewModel(app.repository, app.session) }
    val state by vm.state.collectAsState()

    LaunchedEffect(state.success) {
        if (state.success) onLoggedIn()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(C.Canvas)
            .safeDrawingPadding()
            .padding(horizontal = dim.pagePadding),
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(top = 72.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            MerchantAssetIcon(
                R.mipmap.ic_launcher,
                contentDescription = "商家应用图标",
                modifier = Modifier.clip(RoundedCornerShape(dim.radiusXl)),
                size = 72.dp,
            )

            Spacer(Modifier.height(16.dp))
            Text("商家管理", fontSize = T.section, fontWeight = FontWeight.Bold, color = C.Brand700)
            Spacer(Modifier.height(4.dp))
            Text("仅店主本人使用，请勿共享账号", fontSize = T.caption, color = C.TextSecondary)

            Spacer(Modifier.height(32.dp))

            OutlinedTextField(
                value = state.username,
                onValueChange = vm::onUsernameChange,
                label = { Text("管理员账号") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
                modifier = Modifier.fillMaxWidth(),
                colors = TextFieldDefaults.colors(
                    focusedContainerColor = C.Surface,
                    unfocusedContainerColor = C.Surface,
                ),
            )

            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = state.password,
                onValueChange = vm::onPasswordChange,
                label = { Text("密码") },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
                modifier = Modifier.fillMaxWidth(),
                colors = TextFieldDefaults.colors(
                    focusedContainerColor = C.Surface,
                    unfocusedContainerColor = C.Surface,
                ),
            )

            if (state.error != null) {
                Spacer(Modifier.height(12.dp))
                NoticeBar(state.error!!, ChipTone.Danger)
            }

            Spacer(Modifier.height(20.dp))

            PrimaryButton(
                text = if (state.loading) "登录中…" else "登录",
                onClick = vm::submit,
                enabled = state.username.isNotBlank() && state.password.isNotBlank(),
                loading = state.loading,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(16.dp))
            Text(
                "登录凭据仅用于本机保存会话；密码不会被记住。",
                fontSize = T.mini,
                color = C.TextMuted,
            )
        }
    }
}

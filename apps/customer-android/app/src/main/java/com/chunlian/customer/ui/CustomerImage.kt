package com.chunlian.customer.ui

import android.content.Context
import androidx.annotation.DrawableRes
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.Image
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.key
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.ImageLoader
import coil.compose.AsyncImage
import coil.request.CachePolicy
import coil.request.ImageRequest
import com.chunlian.customer.BuildConfig
import com.chunlian.customer.data.resolveCustomerImageUrl
import com.chunlian.customer.ui.theme.CustomerColors
import com.chunlian.customer.ui.theme.T
import com.chunlian.customer.ui.theme.dim
import okhttp3.OkHttpClient

/** A dedicated loader keeps customer API bearer headers out of public product and QR image requests. */
private object CustomerImageLoader {
    @Volatile private var instance: ImageLoader? = null

    fun get(context: Context): ImageLoader = instance ?: synchronized(this) {
        instance ?: ImageLoader.Builder(context.applicationContext)
            .okHttpClient { OkHttpClient.Builder().build() }
            .build()
            .also { instance = it }
    }
}

@Composable
fun CustomerImage(
    url: String,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    contentScale: ContentScale = ContentScale.Crop,
    placeholderText: String = "图片暂不可用",
    cacheEnabled: Boolean = true,
    @DrawableRes fallbackResource: Int? = null,
    showRetry: Boolean = true,
) {
    val context = LocalContext.current
    val imageUrl = remember(url) { resolveCustomerImageUrl(url, BuildConfig.API_BASE_URL) }
    var attempt by remember(imageUrl) { mutableIntStateOf(0) }
    var loading by remember(imageUrl, attempt) { mutableStateOf(imageUrl != null) }
    var failed by remember(imageUrl, attempt) { mutableStateOf(imageUrl == null) }

    Box(
        modifier = modifier
            .clip(RoundedCornerShape(dim.radiusSm))
            .background(CustomerColors.Brand100),
        contentAlignment = Alignment.Center,
    ) {
        if (imageUrl != null) {
            key(imageUrl, attempt, cacheEnabled) {
                val request = remember(imageUrl, context, cacheEnabled) {
                    val builder = ImageRequest.Builder(context)
                        .data(imageUrl)
                        .crossfade(true)
                    if (!cacheEnabled) {
                        builder
                            .memoryCachePolicy(CachePolicy.DISABLED)
                            .diskCachePolicy(CachePolicy.DISABLED)
                    }
                    builder.build()
                }
                AsyncImage(
                    model = request,
                    imageLoader = remember(context) { CustomerImageLoader.get(context) },
                    contentDescription = contentDescription,
                    modifier = Modifier.fillMaxSize(),
                    contentScale = contentScale,
                    onLoading = { loading = true; failed = false },
                    onSuccess = { loading = false; failed = false },
                    onError = { loading = false; failed = true },
                )
            }
        }
        if (loading) CircularProgressIndicator(modifier = Modifier.size(22.dp), color = CustomerColors.Brand700, strokeWidth = 2.dp)
        if (failed && fallbackResource != null) {
            Image(
                painter = painterResource(fallbackResource),
                contentDescription = contentDescription,
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Fit,
            )
            if (imageUrl != null && showRetry) {
                TextButton(
                    onClick = { attempt += 1 },
                    modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 2.dp),
                ) {
                    Text("重试", color = CustomerColors.Brand700, fontSize = T.mini)
                }
            }
        } else if (failed) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(placeholderText, color = CustomerColors.TextSecondary, fontSize = T.mini, textAlign = TextAlign.Center)
                if (imageUrl != null && showRetry) {
                    TextButton(onClick = { attempt += 1 }, modifier = Modifier.padding(top = 0.dp)) {
                        Text("重试", color = CustomerColors.Brand700, fontSize = T.mini)
                    }
                }
            }
        }
    }
}

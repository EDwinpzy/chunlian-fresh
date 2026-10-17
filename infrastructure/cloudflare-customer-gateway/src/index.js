const ORIGIN_IP = '103.236.97.137'
const ORIGIN_HOST = '127.0.0.1'
const ORIGIN_PORT = '8097'
const ORIGIN_BASE = `http://${ORIGIN_HOST}:${ORIGIN_PORT}`

function normalizedPathname(pathname) {
  if (/%(?:2f|5c|2e)/i.test(pathname)) return null

  let decoded
  try {
    decoded = decodeURIComponent(pathname)
  } catch {
    return null
  }

  if (
    !decoded.startsWith('/') ||
    decoded.includes('\\') ||
    decoded.includes('\0') ||
    decoded.includes('//') ||
    decoded.split('/').some((part) => part === '.' || part === '..')
  ) {
    return null
  }

  return decoded
}

export function isAllowedPath(pathname) {
  const path = normalizedPathname(pathname)
  if (path === null) return false

  return (
    path === '/downloads' ||
    path.startsWith('/downloads/') ||
    path === '/app' ||
    path.startsWith('/app/') ||
    path === '/api/v1/customer' ||
    path.startsWith('/api/v1/customer/') ||
    path.startsWith('/static/upload/images/')
  )
}

export function isAllowedMethod(pathname, method) {
  if (!isAllowedPath(pathname)) return false

  const path = normalizedPathname(pathname)
  const verb = method.toUpperCase()
  if (path === '/api/v1/customer' || path.startsWith('/api/v1/customer/')) {
    return ['GET', 'HEAD', 'POST', 'PATCH', 'DELETE'].includes(verb)
  }

  return verb === 'GET' || verb === 'HEAD'
}

export function rewriteOriginUrls(body, publicOrigin) {
  return body.replace(
    /https?:\/\/(?:(?:103\.236\.97\.137(?::(?:80|443|8095|8096))?)|(?:127\.0\.0\.1(?::8097)?))(?=[/"'?#\s\\]|$)/gi,
    publicOrigin,
  )
}

function notFound() {
  return new Response('Not found', {
    status: 404,
    headers: { 'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=utf-8' },
  })
}

async function fetchOrigin(request, env, publicUrl) {
  const originUrl = new URL(request.url)
  originUrl.protocol = 'http:'
  originUrl.hostname = ORIGIN_HOST
  originUrl.port = ORIGIN_PORT

  const originRequest = new Request(originUrl, request)
  originRequest.headers.set('X-Forwarded-Host', publicUrl.host)
  originRequest.headers.set('X-Forwarded-Proto', 'https')

  if (env.CUSTOMER_ORIGIN) {
    try {
      return await env.CUSTOMER_ORIGIN.fetch(originRequest.clone(), { redirect: 'manual' })
    } catch (error) {
      if (!env.NAT_ORIGIN_BASE) throw error
    }
  }

  if (!env.NAT_ORIGIN_BASE) throw new Error('No customer origin is available')

  const natOrigin = new URL(env.NAT_ORIGIN_BASE)
  if (natOrigin.protocol !== 'http:' || !natOrigin.port) {
    throw new Error('The temporary NAT origin must use HTTP and an explicit port')
  }

  const natUrl = new URL(originRequest.url)
  natUrl.protocol = natOrigin.protocol
  natUrl.hostname = natOrigin.hostname
  natUrl.port = natOrigin.port
  const natRequest = new Request(natUrl, originRequest)
  natRequest.headers.set('Host', natOrigin.host)
  return fetch(natRequest, { redirect: 'manual' })
}

export default {
  async fetch(request, env) {
    const publicUrl = new URL(request.url)
    const pathname = normalizedPathname(publicUrl.pathname)
    if (pathname === null || !isAllowedPath(publicUrl.pathname)) return notFound()

    if (!isAllowedMethod(publicUrl.pathname, request.method)) {
      return new Response('Method not allowed', {
        status: 405,
        headers: {
          Allow: pathname === '/api/v1/customer' || pathname.startsWith('/api/v1/customer/')
            ? 'GET, HEAD, POST, PATCH, DELETE'
            : 'GET, HEAD',
          'Cache-Control': 'no-store',
        },
      })
    }

    if (pathname === '/downloads' || pathname === '/app') {
      return Response.redirect(`${publicUrl.origin}${pathname}/${publicUrl.search}`, 308)
    }

    let originResponse
    try {
      originResponse = await fetchOrigin(request, env, publicUrl)
    } catch {
      return new Response('Service temporarily unavailable', {
        status: 503,
        headers: { 'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=utf-8' },
      })
    }

    const headers = new Headers(originResponse.headers)
    headers.delete('content-length')

    const location = headers.get('location')
    if (location) {
      try {
        const redirectUrl = new URL(location, ORIGIN_BASE)
        const isLoopbackOrigin =
          redirectUrl.hostname === ORIGIN_HOST && ['', '80', ORIGIN_PORT].includes(redirectUrl.port)
        const isLegacyPublicOrigin =
          redirectUrl.hostname === ORIGIN_IP &&
          ['', '80', '443', '8095', '8096'].includes(redirectUrl.port)

        if (isLoopbackOrigin || isLegacyPublicOrigin) {
          if (!isAllowedPath(redirectUrl.pathname)) return notFound()
          headers.set(
            'location',
            `${publicUrl.origin}${redirectUrl.pathname}${redirectUrl.search}${redirectUrl.hash}`,
          )
        }
      } catch {
        // Leave non-URL Location values untouched.
      }
    }

    const isCustomerApi = pathname === '/api/v1/customer' || pathname.startsWith('/api/v1/customer/')
    if (isCustomerApi) headers.set('Cache-Control', 'no-store')

    if ((headers.get('content-type') || '').toLowerCase().includes('application/json')) {
      headers.delete('content-encoding')
      const body = rewriteOriginUrls(await originResponse.text(), publicUrl.origin)
      return new Response(body, {
        status: originResponse.status,
        statusText: originResponse.statusText,
        headers,
      })
    }

    return new Response(originResponse.body, {
      status: originResponse.status,
      statusText: originResponse.statusText,
      headers,
    })
  },
}

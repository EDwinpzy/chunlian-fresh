import assert from 'node:assert/strict'
import test from 'node:test'
import worker, { isAllowedMethod, isAllowedPath, rewriteOriginUrls } from '../src/index.js'

test('allows only the customer download and web surfaces', () => {
  for (const path of [
    '/downloads/',
    '/downloads/assets/customer-app-icon.png',
    '/app/',
    '/app/assets/index.js',
    '/api/v1/customer/catalog/goods',
    '/static/upload/images/goods/item.jpg',
  ]) {
    assert.equal(isAllowedPath(path), true, path)
  }

  for (const path of [
    '/',
    '/admin/',
    '/merchant/',
    '/api/v1/merchant/orders',
    '/api/v1/customer/../merchant/orders',
    '/api/v1/customer/%2e%2e/merchant/orders',
    '/api/v1/customer%2f..%2fmerchant/orders',
    '/static/upload/private/document.pdf',
  ]) {
    assert.equal(isAllowedPath(path), false, path)
  }
})

test('allows mutations only on customer API routes', () => {
  assert.equal(isAllowedMethod('/api/v1/customer/orders', 'POST'), true)
  assert.equal(isAllowedMethod('/api/v1/customer/profile', 'PATCH'), true)
  assert.equal(isAllowedMethod('/app/', 'POST'), false)
  assert.equal(isAllowedMethod('/static/upload/images/item.jpg', 'DELETE'), false)
  assert.equal(isAllowedMethod('/api/v1/merchant/orders', 'GET'), false)
})

test('rewrites only the production origin host in API payloads', () => {
  const source = '{"public_ip":"https://103.236.97.137:8096/static/upload/images/item.jpg","loopback":"http://127.0.0.1:8097/static/upload/images/item.jpg","keep":"https://example.com/a.jpg"}'
  assert.equal(
    rewriteOriginUrls(source, 'https://chunlian-fresh.pengzhiyuan0724.workers.dev'),
    '{"public_ip":"https://chunlian-fresh.pengzhiyuan0724.workers.dev/static/upload/images/item.jpg","loopback":"https://chunlian-fresh.pengzhiyuan0724.workers.dev/static/upload/images/item.jpg","keep":"https://example.com/a.jpg"}',
  )
})

test('proxies customer API requests, rewrites image URLs, and disables API caching', async () => {
  let seenRequest
  const response = await worker.fetch(
    new Request('https://chunlian-fresh.pengzhiyuan0724.workers.dev/api/v1/customer/catalog/goods?page=1'),
    {
      CUSTOMER_ORIGIN: {
        async fetch(request, init) {
          seenRequest = { request, init }
          return Response.json({ image_url: 'https://103.236.97.137/static/upload/images/goods/item.jpg' })
        },
      },
    },
  )

  assert.equal(seenRequest.request.url, 'http://127.0.0.1:8097/api/v1/customer/catalog/goods?page=1')
  assert.equal(seenRequest.request.headers.get('x-forwarded-host'), 'chunlian-fresh.pengzhiyuan0724.workers.dev')
  assert.equal(seenRequest.request.headers.get('x-forwarded-proto'), 'https')
  assert.equal(seenRequest.init.redirect, 'manual')
  assert.equal(response.headers.get('cache-control'), 'no-store')
  assert.deepEqual(await response.json(), {
    image_url: 'https://chunlian-fresh.pengzhiyuan0724.workers.dev/static/upload/images/goods/item.jpg',
  })
})

test('falls back to the temporary NAT origin when the VPC tunnel is unavailable', async () => {
  const originalFetch = globalThis.fetch
  let fallbackRequest
  let fallbackOptions
  globalThis.fetch = async (request, options) => {
    fallbackRequest = request
    fallbackOptions = options
    return new Response('<html>temporary origin</html>', {
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    })
  }

  try {
    const response = await worker.fetch(
      new Request('https://chunlian-fresh.pengzhiyuan0724.workers.dev/app/orders/7?view=detail'),
      {
        CUSTOMER_ORIGIN: {
          async fetch() { throw new Error('VPC tunnel is inactive') },
        },
        NAT_ORIGIN_BASE: 'http://103.236.97.137:43127',
      },
    )

    assert.equal(response.status, 200)
    assert.equal(fallbackRequest.url, 'http://103.236.97.137:43127/app/orders/7?view=detail')
    assert.equal(fallbackRequest.headers.get('x-forwarded-host'), 'chunlian-fresh.pengzhiyuan0724.workers.dev')
    assert.equal(fallbackRequest.headers.get('x-forwarded-proto'), 'https')
    assert.equal(fallbackOptions.redirect, 'manual')
  } finally {
    globalThis.fetch = originalFetch
  }
})

test('accepts a DNS hostname for the temporary NAT origin instead of a bare IP', async () => {
  const originalFetch = globalThis.fetch
  let fallbackRequest
  globalThis.fetch = async (request) => {
    fallbackRequest = request
    return new Response('<html>temporary origin</html>', {
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    })
  }

  try {
    const response = await worker.fetch(
      new Request('https://chunlian-fresh.pengzhiyuan0724.workers.dev/downloads/'),
      {
        CUSTOMER_ORIGIN: {
          async fetch() { throw new Error('VPC tunnel is inactive') },
        },
        // Cloudflare answers bare-IP subrequests with error 1003, so the NAT
        // fallback has to be addressed through a hostname that resolves to the
        // same server.
        NAT_ORIGIN_BASE: 'http://103-236-97-137.sslip.io:36965',
      },
    )

    assert.equal(response.status, 200)
    assert.equal(fallbackRequest.url, 'http://103-236-97-137.sslip.io:36965/downloads/')
    assert.equal(fallbackRequest.headers.get('host'), '103-236-97-137.sslip.io:36965')
  } finally {
    globalThis.fetch = originalFetch
  }
})

test('normalizes private and legacy origin redirects and blocks private-path leaks', async () => {
  const redirectTo = async (location) => worker.fetch(
    new Request('https://chunlian-fresh.pengzhiyuan0724.workers.dev/app/'),
    {
      CUSTOMER_ORIGIN: {
        async fetch() { return new Response(null, { status: 302, headers: { Location: location } }) },
      },
    },
  )

  const loopbackRedirect = await redirectTo('/app/orders/7?view=detail')
  assert.equal(loopbackRedirect.headers.get('location'), 'https://chunlian-fresh.pengzhiyuan0724.workers.dev/app/orders/7?view=detail')

  const legacyRedirect = await redirectTo('https://103.236.97.137:8096/downloads/')
  assert.equal(legacyRedirect.headers.get('location'), 'https://chunlian-fresh.pengzhiyuan0724.workers.dev/downloads/')

  const blockedRedirect = await redirectTo('http://127.0.0.1:8097/api/v1/merchant/orders')
  assert.equal(blockedRedirect.status, 404)
})

test('does not send blocked routes or methods to the origin', async () => {
  let originCalls = 0
  const env = { CUSTOMER_ORIGIN: { async fetch() { originCalls += 1; return new Response('unexpected') } } }

  const merchantResponse = await worker.fetch(
    new Request('https://chunlian-fresh.pengzhiyuan0724.workers.dev/api/v1/merchant/orders'),
    env,
  )
  const imageMutationResponse = await worker.fetch(
    new Request('https://chunlian-fresh.pengzhiyuan0724.workers.dev/static/upload/images/item.jpg', { method: 'DELETE' }),
    env,
  )

  assert.equal(merchantResponse.status, 404)
  assert.equal(imageMutationResponse.status, 405)
  assert.equal(originCalls, 0)
})

test('adds trailing slashes before serving the download page and web app', async () => {
  let originCalls = 0
  const env = { CUSTOMER_ORIGIN: { async fetch() { originCalls += 1; return new Response('unexpected') } } }

  for (const [path, expected] of [['/downloads?from=qr', '/downloads/?from=qr'], ['/app', '/app/']]) {
    const response = await worker.fetch(
      new Request(`https://chunlian-fresh.pengzhiyuan0724.workers.dev${path}`),
      env,
    )
    assert.equal(response.status, 308)
    assert.equal(response.headers.get('location'), `https://chunlian-fresh.pengzhiyuan0724.workers.dev${expected}`)
  }

  assert.equal(originCalls, 0)
})

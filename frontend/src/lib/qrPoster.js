import QRCode from 'qrcode'

/**
 * Where customers book. The salon's own subdomain when one has been
 * minted — that is the address an owner puts on a poster — and the
 * apex-hosted path as a fallback.
 */
export function bookingUrl(organization) {
  if (!organization) return ''
  if (organization.primary_domain) return `https://${organization.primary_domain}`
  return `${window.location.origin}/book/${organization.slug}`
}

const POSTER_WIDTH = 800
const POSTER_HEIGHT = 1000
const QR_SIZE = 520

/**
 * A printable poster: salon name, QR code, and the URL in readable text
 * underneath — a customer whose camera will not scan it can still type it.
 */
export async function posterCanvas(url, salonName) {
  const qr = document.createElement('canvas')
  await QRCode.toCanvas(qr, url, { width: QR_SIZE, margin: 1 })

  const poster = document.createElement('canvas')
  poster.width = POSTER_WIDTH
  poster.height = POSTER_HEIGHT
  const ctx = poster.getContext('2d')

  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, POSTER_WIDTH, POSTER_HEIGHT)

  ctx.fillStyle = '#0f172a'
  ctx.textAlign = 'center'
  ctx.font = 'bold 48px sans-serif'
  ctx.fillText(salonName, POSTER_WIDTH / 2, 110)

  ctx.font = '28px sans-serif'
  ctx.fillStyle = '#475569'
  ctx.fillText('Book your appointment', POSTER_WIDTH / 2, 160)

  ctx.drawImage(qr, (POSTER_WIDTH - QR_SIZE) / 2, 210)

  ctx.font = '24px sans-serif'
  ctx.fillStyle = '#0f172a'
  ctx.fillText(url.replace(/^https?:\/\//, ''), POSTER_WIDTH / 2, 210 + QR_SIZE + 70)

  return poster
}

export async function downloadPoster(url, salonName) {
  const canvas = await posterCanvas(url, salonName)
  const link = document.createElement('a')
  link.download = `${salonName.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-booking-qr.png`
  link.href = canvas.toDataURL('image/png')
  link.click()
}

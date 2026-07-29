#!/usr/bin/env node

import { readFile, mkdir, writeFile } from 'node:fs/promises'

const args = parseArgs(process.argv.slice(2))
const input = args.input || 'data/competitors-geocoded-2026-05-07.json'
const outDir = args.out || 'data'
const radiusMeters = Number(args.radius || 1000)

const AREAS = [
  { key: 'sangenjaya', name: '三軒茶屋', lat: 35.6436, lng: 139.6700 },
  { key: 'shimokitazawa', name: '下北沢', lat: 35.6614, lng: 139.6680 },
  { key: 'yoga', name: '用賀', lat: 35.6303, lng: 139.6558 },
]

function parseArgs(rawArgs) {
  const parsed = {}
  for (let i = 0; i < rawArgs.length; i++) {
    const arg = rawArgs[i]
    if (!arg.startsWith('--')) continue
    const key = arg.slice(2)
    const next = rawArgs[i + 1]
    if (!next || next.startsWith('--')) {
      parsed[key] = true
    } else {
      parsed[key] = next
      i++
    }
  }
  return parsed
}

function distanceMeters(a, b) {
  const earthRadius = 6371000
  const lat1 = toRad(a.lat)
  const lat2 = toRad(b.lat)
  const deltaLat = toRad(b.lat - a.lat)
  const deltaLng = toRad(b.lng - a.lng)
  const h =
    Math.sin(deltaLat / 2) ** 2 +
    Math.cos(lat1) * Math.cos(lat2) * Math.sin(deltaLng / 2) ** 2
  return 2 * earthRadius * Math.asin(Math.sqrt(h))
}

function toRad(value) {
  return (value * Math.PI) / 180
}

function classifyCompany(name) {
  const majorBrands = [
    '三井',
    '住友',
    '東急',
    '野村',
    '三菱',
    '東京建物',
    'オープンハウス',
    '大京',
    '大和',
    '積水',
    '旭化成',
    '伊藤忠',
    '長谷工',
  ]
  const franchiseBrands = [
    'センチュリー21',
    'センチュリー２１',
    'ピタットハウス',
    'ハウスドゥ',
    'アパマン',
    'エイブル',
    'リブマックス',
    'スターツ',
  ]

  if (franchiseBrands.some(brand => name.includes(brand))) return 'FC'
  if (majorBrands.some(brand => name.includes(brand))) return '大手'
  return '中小'
}

function sqlString(value) {
  return String(value ?? '').replaceAll("'", "''")
}

function buildSeedSql(results) {
  const lines = []
  const areaKeys = results.map(result => `'${result.key}'`).join(', ')

  lines.push('begin;')
  lines.push('')
  lines.push('alter table public.competitors add column if not exists address text;')
  lines.push('alter table public.competitors add column if not exists license_no text;')
  lines.push('alter table public.competitors add column if not exists license text;')
  lines.push('alter table public.competitors add column if not exists representative text;')
  lines.push('alter table public.competitors add column if not exists detail_url text;')
  lines.push('alter table public.competitors add column if not exists source text;')
  lines.push('')
  lines.push(`delete from public.competitors where area_key in (${areaKeys});`)
  lines.push('')

  const rows = []
  for (const result of results) {
    for (const competitor of result.competitors) {
      rows.push(
        `('${result.key}', '${sqlString(competitor.name)}', ${competitor.lat}, ${competitor.lng}, '${competitor.type}', '${sqlString(competitor.address)}', '${sqlString(competitor.licenseNo)}', '${sqlString(competitor.license)}', '${sqlString(competitor.representative)}', '${sqlString(competitor.detailUrl)}', '東京都 宅地建物取引業者免許情報提供サービス')`
      )
    }
  }

  if (rows.length > 0) {
    lines.push('insert into public.competitors (area_key, name, lat, lng, type, address, license_no, license, representative, detail_url, source) values')
    lines.push(`${rows.join(',\n')};`)
    lines.push('')
  }

  for (const result of results) {
    lines.push(`update public.areas set competitors = ${result.count} where key = '${result.key}';`)
  }

  lines.push('')
  lines.push('commit;')
  lines.push('')

  return lines.join('\n')
}

async function main() {
  await mkdir(outDir, { recursive: true })

  const raw = await readFile(input, 'utf8')
  const competitors = JSON.parse(raw)

  const results = AREAS.map(area => {
    const nearby = competitors
      .map(row => ({
        ...row,
        type: classifyCompany(row.name),
        distanceMeters: Math.round(distanceMeters(area, row)),
      }))
      .filter(row => row.distanceMeters <= radiusMeters)
      .sort((a, b) => a.distanceMeters - b.distanceMeters)

    return {
      key: area.key,
      name: area.name,
      center: { lat: area.lat, lng: area.lng },
      radiusMeters,
      count: nearby.length,
      competitors: nearby,
    }
  })

  const date = new Date().toISOString().slice(0, 10)
  const jsonPath = `${outDir}/area-competitors-${date}.json`
  const sqlPath = `${outDir}/area-competitors-seed-${date}.sql`

  await writeFile(jsonPath, JSON.stringify(results, null, 2), 'utf8')
  await writeFile(sqlPath, buildSeedSql(results), 'utf8')

  for (const result of results) {
    console.log(`${result.name}: ${result.count}件 within ${radiusMeters}m`)
  }
  console.log(`Wrote ${jsonPath}`)
  console.log(`Wrote ${sqlPath}`)
}

main().catch(error => {
  console.error(error)
  process.exitCode = 1
})

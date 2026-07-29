#!/usr/bin/env node

import { readFile, mkdir, writeFile } from 'node:fs/promises'

const DEFAULT_DELAY_MS = 800

const args = parseArgs(process.argv.slice(2))
const input = args.input
const outDir = args.out || 'data'
const delayMs = Number(args.delay || DEFAULT_DELAY_MS)
const limit = Number(args.limit || 0)
const prefix = args.prefix || 'competitors'

if (!input) {
  console.error('Usage: node scripts/geocode-competitors.mjs --input data/tokyo-takken-世田谷区-YYYY-MM-DD.json')
  process.exit(1)
}

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

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

async function geocode(address) {
  const url = `https://msearch.gsi.go.jp/address-search/AddressSearch?q=${encodeURIComponent(address)}`
  const response = await fetch(url, {
    headers: {
      'user-agent': 'arealab-data-research/0.1',
    },
  })
  if (!response.ok) throw new Error(`${response.status} ${response.statusText}`)

  const data = await response.json()
  const first = data?.[0]
  const coordinates = first?.geometry?.coordinates
  if (!coordinates) return null

  return {
    lng: coordinates[0],
    lat: coordinates[1],
    geocodeTitle: first.properties?.title || '',
  }
}

async function main() {
  await mkdir(outDir, { recursive: true })

  const raw = await readFile(input, 'utf8')
  const allRows = JSON.parse(raw)
  const rows = limit > 0 ? allRows.slice(0, limit) : allRows
  const enriched = []
  const failed = []

  for (const row of rows) {
    console.log(`Geocoding ${row.address}`)
    try {
      const result = await geocode(row.address)
      if (!result) {
        failed.push(row)
      } else {
        enriched.push({ ...row, ...result })
      }
    } catch (error) {
      failed.push({ ...row, error: error.message })
    }
    await sleep(delayMs)
  }

  const date = new Date().toISOString().slice(0, 10)
  const okPath = `${outDir}/${prefix}-geocoded-${date}.json`
  const ngPath = `${outDir}/${prefix}-geocode-failed-${date}.json`

  await writeFile(okPath, JSON.stringify(enriched, null, 2), 'utf8')
  await writeFile(ngPath, JSON.stringify(failed, null, 2), 'utf8')

  console.log(`Wrote ${okPath}`)
  console.log(`Wrote ${ngPath}`)
}

main().catch(error => {
  console.error(error)
  process.exitCode = 1
})

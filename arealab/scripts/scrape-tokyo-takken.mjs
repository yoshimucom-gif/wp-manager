#!/usr/bin/env node

import { mkdir, writeFile } from 'node:fs/promises'

const BASE_URL = 'https://www.takken.metro.tokyo.lg.jp'
const DEFAULT_DELAY_MS = 800

const args = parseArgs(process.argv.slice(2))
const ward = args.ward || ''
const maxPages = Number(args.pages || 0)
const delayMs = Number(args.delay || DEFAULT_DELAY_MS)
const outDir = args.out || 'data'
const includeDetails = Boolean(args.details)
const checkpointEvery = Number(args.checkpointEvery || 100)

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

function normalizeText(value) {
  return value
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim()
}

function extractSearchRows(html) {
  const rows = []
  const rowRegex = /<tr style="height:55px;">(?<row>[\s\S]*?)<\/tr>/g
  const cellRegex = /<td[^>]*>(?<cell>[\s\S]*?)<\/td>/g

  for (const rowMatch of html.matchAll(rowRegex)) {
    const rowHtml = rowMatch.groups.row
    const cells = [...rowHtml.matchAll(cellRegex)].map(match => normalizeText(match.groups.cell))
    const detailMatch = rowHtml.match(/detail\?licenseno=(?<licenseNo>\d+)&amp;disp=1/)
    if (!detailMatch || cells.length < 7) continue

    const licenseNo = detailMatch.groups.licenseNo
    const license = cells[3]
    const name = cells[4]
    const representative = cells[5]
    const address = cells[6]

    rows.push({
      licenseNo,
      detailUrl: `${BASE_URL}/detail?disp=1&licenseno=${licenseNo}`,
      officeListUrl: `${BASE_URL}/detail?disp=2&licenseno=${licenseNo}`,
      license,
      name,
      representative,
      address,
    })
  }

  return rows
}

function extractDetail(html, fallback) {
  const text = normalizeText(html)
  const nameMatch = text.match(/商号又は名称\s*（漢字）\s*(?<name>.+?)\s*主たる事務所/)
  const mainAddressMatch = text.match(/主たる事務所\s*（本店）の所在地\s*(?<address>東京都.+?)\s*免許申請時点/)
  const phoneMatch = text.match(/(0\d{1,4}-\d{1,4}-\d{3,4})/)
  const licenseMatch = text.match(/免許証番号\s*(?<license>.+?)\s*法人・個人の別/)

  return {
    licenseNo: fallback.licenseNo,
    license: licenseMatch?.groups?.license?.trim() || '',
    name: nameMatch?.groups?.name?.trim() || '',
    address: mainAddressMatch?.groups?.address?.trim() || fallback.address,
    phone: phoneMatch?.[1] || '',
    detailUrl: fallback.detailUrl,
    source: '東京都 宅地建物取引業者免許情報提供サービス',
  }
}

async function fetchText(url) {
  const response = await fetch(url, {
    headers: {
      'user-agent': 'arealab-data-research/0.1',
    },
  })

  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}: ${url}`)
  }

  return response.text()
}

async function main() {
  await mkdir(outDir, { recursive: true })

  const candidates = []
  let page = 1
  const date = new Date().toISOString().slice(0, 10)
  const scope = ward || 'all'
  const partialPath = `${outDir}/tokyo-takken-${scope}-${date}.partial.json`

  while (true) {
    if (maxPages > 0 && page > maxPages) break

    const url = `${BASE_URL}/search/get?page=${page}`
    if (page === 1 || page % 50 === 0) {
      console.log(`Fetching search page ${page}: ${url}`)
    }
    const html = await fetchText(url)
    if (args.debug) {
      await writeFile(`${outDir}/debug-search-page-${page}.html`, html, 'utf8')
    }
    const rows = extractSearchRows(html)

    if (rows.length === 0) break

    for (const row of rows) {
      if (!ward || row.address.includes(`東京都${ward}`)) {
        candidates.push(row)
      }
    }

    page++
    if (checkpointEvery > 0 && page % checkpointEvery === 0) {
      await writeFile(partialPath, JSON.stringify(candidates, null, 2), 'utf8')
      console.log(`Checkpoint: ${candidates.length} candidates through page ${page - 1}`)
    }
    await sleep(delayMs)
  }

  console.log(`Found ${candidates.length} candidates${ward ? ` for ${ward}` : ' for all Tokyo'}`)

  const details = []
  if (includeDetails) {
    for (const candidate of candidates) {
      console.log(`Fetching detail ${candidate.licenseNo}`)
      const html = await fetchText(candidate.detailUrl)
      details.push(extractDetail(html, candidate))
      await sleep(delayMs)
    }
  }

  const jsonPath = `${outDir}/tokyo-takken-${scope}-${date}.json`
  await writeFile(jsonPath, JSON.stringify(includeDetails ? details : candidates, null, 2), 'utf8')
  console.log(`Wrote ${jsonPath}`)
}

main().catch(error => {
  console.error(error)
  process.exitCode = 1
})

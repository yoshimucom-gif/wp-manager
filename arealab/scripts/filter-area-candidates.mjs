#!/usr/bin/env node

import { readFile, mkdir, writeFile } from 'node:fs/promises'

const args = parseArgs(process.argv.slice(2))
const input = args.input || 'data/tokyo-takken-all-2026-05-07.json'
const outDir = args.out || 'data'

const AREAS = [
  {
    key: 'sangenjaya',
    name: '三軒茶屋',
    keywords: [
      '世田谷区三軒茶屋',
      '世田谷区太子堂',
      '世田谷区若林',
      '世田谷区上馬',
      '世田谷区下馬',
      '世田谷区野沢',
      '世田谷区池尻',
      '世田谷区代沢',
    ],
  },
  {
    key: 'shimokitazawa',
    name: '下北沢',
    keywords: [
      '世田谷区北沢',
      '世田谷区代沢',
      '世田谷区代田',
      '世田谷区大原',
      '世田谷区羽根木',
    ],
  },
  {
    key: 'yoga',
    name: '用賀',
    keywords: [
      '世田谷区用賀',
      '世田谷区瀬田',
      '世田谷区玉川台',
      '世田谷区上用賀',
      '世田谷区中町',
      '世田谷区桜新町',
      '世田谷区深沢',
    ],
  },
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

function uniqueByLicense(rows) {
  const seen = new Set()
  const unique = []
  for (const row of rows) {
    if (seen.has(row.licenseNo)) continue
    seen.add(row.licenseNo)
    unique.push(row)
  }
  return unique
}

async function main() {
  await mkdir(outDir, { recursive: true })

  const raw = await readFile(input, 'utf8')
  const rows = JSON.parse(raw)

  const allCandidates = []
  const byArea = []

  for (const area of AREAS) {
    const matches = rows
      .filter(row => area.keywords.some(keyword => row.address.includes(keyword)))
      .map(row => ({
        ...row,
        candidateArea: area.key,
        candidateAreaName: area.name,
      }))

    byArea.push({
      key: area.key,
      name: area.name,
      keywords: area.keywords,
      count: matches.length,
      candidates: matches,
    })
    allCandidates.push(...matches)
  }

  const uniqueCandidates = uniqueByLicense(allCandidates)
  const date = new Date().toISOString().slice(0, 10)
  const byAreaPath = `${outDir}/area-candidates-by-keyword-${date}.json`
  const flatPath = `${outDir}/area-candidates-flat-${date}.json`

  await writeFile(byAreaPath, JSON.stringify(byArea, null, 2), 'utf8')
  await writeFile(flatPath, JSON.stringify(uniqueCandidates, null, 2), 'utf8')

  for (const area of byArea) {
    console.log(`${area.name}: ${area.count} keyword candidates`)
  }
  console.log(`Unique candidates: ${uniqueCandidates.length}`)
  console.log(`Wrote ${byAreaPath}`)
  console.log(`Wrote ${flatPath}`)
}

main().catch(error => {
  console.error(error)
  process.exitCode = 1
})

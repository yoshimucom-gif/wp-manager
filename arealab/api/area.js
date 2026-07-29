const { createClient } = require('@supabase/supabase-js')

const supabase = createClient(
  process.env.SUPABASE_URL,
  process.env.SUPABASE_ANON_KEY
)

module.exports = async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*')
  res.setHeader('Cache-Control', 's-maxage=300, stale-while-revalidate')

  const { key, name } = req.query

  if (!key && !name) {
    return res.status(400).json({ error: 'key or name is required' })
  }

  // エリア検索
  let query = supabase.from('areas').select('*')
  if (key)  query = query.eq('key', key).limit(1)
  if (name) query = query.ilike('name', `%${name}%`).limit(1)

  const { data: areas, error } = await query
  if (error || !areas || areas.length === 0) {
    return res.status(404).json({ error: 'not_found' })
  }
  const area = areas[0]

  // 関連データを並列取得
  const [compRes, simRes, costRes] = await Promise.all([
    supabase.from('competitors').select('*').eq('area_key', area.key).order('id'),
    supabase.from('simulations').select('*').eq('area_key', area.key).order('sort_order'),
    supabase.from('costs').select('*').eq('area_key', area.key).order('sort_order'),
  ])

  return res.json({
    ...area,
    competitorList: compRes.data || [],
    simulations:    simRes.data  || [],
    costs:          costRes.data || [],
  })
}

-- エリアLab initial seed data
-- Run after supabase-schema.sql.
-- This script refreshes the three mock areas used by index.html.

begin;

delete from public.costs
where area_key in ('sangenjaya', 'shimokitazawa', 'yoga');

delete from public.simulations
where area_key in ('sangenjaya', 'shimokitazawa', 'yoga');

delete from public.competitors
where area_key in ('sangenjaya', 'shimokitazawa', 'yoga');

insert into public.areas (
  key,
  name,
  prefecture,
  grade,
  lat,
  lng,
  annual_transactions,
  median_price,
  competitors,
  potential,
  price_range,
  main_property,
  population,
  avg_age,
  color,
  tagline,
  ai_comment
) values
(
  'sangenjaya',
  '三軒茶屋',
  '東京都世田谷区',
  'A',
  35.6436,
  139.6700,
  847,
  4280,
  12,
  70.6,
  '2,000〜6,500万円',
  'マンション・戸建て',
  '約68,000人（1km圏内）',
  '35.2歳',
  '#059669',
  '高ポテンシャル・最優先開業候補',
  '三軒茶屋エリアは東急田園都市線・世田谷線の交差点として高い交通利便性を誇り、30代ファミリー層とDINKSの流入が顕著です。年間成約847件・競合12社という数字は市場の活況を示すと同時に、差別化戦略の重要性も示唆しています。当エリアでの成功には「1,500〜3,500万円台のリノベーション物件への専門特化」または「買い替え需要を軸にした資産整理・住み替えコンサルティング」が有効です。競合マップを見ると、三軒茶屋駅南側（太子堂3〜4丁目）にホワイトスポットが存在し、そこへの出店が差別化の最短経路と判断されます。グレードA判定として、適切な資本計画のもとで開業から3年以内の黒字化が高確率で期待できる最優先候補エリアです。'
),
(
  'shimokitazawa',
  '下北沢',
  '東京都世田谷区',
  'B',
  35.6614,
  139.6680,
  623,
  3650,
  9,
  69.2,
  '1,800〜5,000万円',
  'マンション・リノベーション物件',
  '約52,000人（1km圏内）',
  '31.8歳',
  '#185FA5',
  '若年層集客力高・差別化が鍵',
  '下北沢エリアは小田急線・京王井の頭線の結節点として、20〜30代の若年層・クリエイター・音楽関係者が多く集まる独自のカルチャー圏を形成しています。成約単価が比較的低め（中央3,650万円）な点は客単価を下げる要因ですが、成約回転率が高く賃貸仲介との兼業が収益を安定させる強力な手段となります。競合9社のうち大手2社は「一般的な物件」に集中しており、リノベーション物件専門またはシェアハウス・デザイナーズ物件特化という差別化ポジションで十分に対抗できます。グレードB判定ですが、ニッチ戦略を正確に実行できれば標準シナリオを上回る収益も期待できます。'
),
(
  'yoga',
  '用賀',
  '東京都世田谷区',
  'B',
  35.6303,
  139.6558,
  412,
  5120,
  6,
  68.7,
  '3,500〜9,000万円',
  '戸建て・高額マンション',
  '約38,000人（1km圏内）',
  '41.5歳',
  '#185FA5',
  '高単価・競合少・富裕層マーケット',
  '用賀エリアは東急田園都市線の閑静な住宅街として、世帯年収1,000万円超のファミリー層が厚く根付いています。競合6社と少ない反面、成約件数も412件と控えめで「高単価・低回転型」のビジネスモデルが適合します。平均手数料は255万円/件（標準シナリオ）と3エリア中最高水準であり、少ない取引件数でも高い収益性を維持できます。内装・ブランディングへの初期投資（150万円以上推奨）を行い、富裕層顧客のリピート・紹介ネットワークを構築することが最重要の成功要因です。グレードB判定ですが、富裕層向けの専門的なサービス設計を施すことで、5年後には最も安定した事業基盤を構築できるエリアといえます。'
)
on conflict (key) do update set
  name = excluded.name,
  prefecture = excluded.prefecture,
  grade = excluded.grade,
  lat = excluded.lat,
  lng = excluded.lng,
  annual_transactions = excluded.annual_transactions,
  median_price = excluded.median_price,
  competitors = excluded.competitors,
  potential = excluded.potential,
  price_range = excluded.price_range,
  main_property = excluded.main_property,
  population = excluded.population,
  avg_age = excluded.avg_age,
  color = excluded.color,
  tagline = excluded.tagline,
  ai_comment = excluded.ai_comment;

insert into public.competitors (area_key, name, lat, lng, type) values
('sangenjaya', '東急リバブル三軒茶屋店', 35.6441, 139.6712, '大手'),
('sangenjaya', '野村の仲介＋ 三軒茶屋', 35.6428, 139.6695, '大手'),
('sangenjaya', 'センチュリー21 三茶不動産', 35.6450, 139.6705, 'FC'),
('sangenjaya', '住友不動産販売 三軒茶屋', 35.6435, 139.6685, '大手'),
('sangenjaya', 'ピタットハウス三軒茶屋', 35.6445, 139.6720, 'FC'),
('sangenjaya', '三菱地所ハウスネット', 35.6420, 139.6690, '大手'),
('sangenjaya', 'エイブル三軒茶屋', 35.6460, 139.6715, 'FC'),
('sangenjaya', '大京穴吹不動産', 35.6432, 139.6702, '大手'),
('sangenjaya', 'リブマックス三軒茶屋', 35.6448, 139.6698, '中小'),
('sangenjaya', 'オープンハウス三軒茶屋', 35.6438, 139.6680, '大手'),
('sangenjaya', 'アパマンショップ三軒茶屋', 35.6455, 139.6688, 'FC'),
('sangenjaya', 'スターツ三軒茶屋', 35.6425, 139.6710, 'FC'),
('shimokitazawa', '東急リバブル下北沢店', 35.6618, 139.6688, '大手'),
('shimokitazawa', 'センチュリー21 下北沢', 35.6605, 139.6675, 'FC'),
('shimokitazawa', 'ピタットハウス下北沢', 35.6625, 139.6695, 'FC'),
('shimokitazawa', 'ハウスドゥ下北沢', 35.6612, 139.6665, 'FC'),
('shimokitazawa', 'スターツ下北沢', 35.6598, 139.6682, 'FC'),
('shimokitazawa', 'リブマックス下北沢', 35.6630, 139.6702, '中小'),
('shimokitazawa', 'エイブル下北沢', 35.6608, 139.6670, 'FC'),
('shimokitazawa', 'アパマンショップ下北沢', 35.6622, 139.6658, 'FC'),
('shimokitazawa', '住友不動産販売 下北沢', 35.6595, 139.6690, '大手'),
('yoga', '東急リバブル用賀店', 35.6308, 139.6568, '大手'),
('yoga', '野村の仲介＋ 用賀', 35.6295, 139.6555, '大手'),
('yoga', 'センチュリー21 用賀', 35.6315, 139.6578, 'FC'),
('yoga', '住友不動産販売 用賀', 35.6302, 139.6548, '大手'),
('yoga', 'ピタットハウス用賀', 35.6320, 139.6562, 'FC'),
('yoga', '田園都市ハウジング', 35.6288, 139.6570, '中小');

insert into public.simulations (
  area_key,
  sort_order,
  label,
  icon,
  transactions,
  fee,
  revenue,
  cost,
  net,
  highlight
) values
('sangenjaya', 1, '保守的', '📉', 8, 150, 1200, 480, 720, false),
('sangenjaya', 2, '標準的', '📊', 12, 165, 1980, 792, 1188, true),
('sangenjaya', 3, '積極的', '📈', 18, 175, 3150, 1260, 1890, false),
('shimokitazawa', 1, '保守的', '📉', 7, 135, 945, 378, 567, false),
('shimokitazawa', 2, '標準的', '📊', 10, 150, 1500, 600, 900, true),
('shimokitazawa', 3, '積極的', '📈', 15, 160, 2400, 960, 1440, false),
('yoga', 1, '保守的', '📉', 5, 240, 1200, 480, 720, false),
('yoga', 2, '標準的', '📊', 8, 255, 2040, 816, 1224, true),
('yoga', 3, '積極的', '📈', 12, 265, 3180, 1272, 1908, false);

insert into public.costs (area_key, sort_order, label, value) values
('sangenjaya', 1, '事務所賃借費用（初年度）', '180〜300万円'),
('sangenjaya', 2, '内装工事費', '120〜200万円'),
('sangenjaya', 3, '看板・サイン・備品', '30〜60万円'),
('sangenjaya', 4, '弁済業務保証金分担金', '60万円'),
('sangenjaya', 5, '免許申請費用', '約3.3万円'),
('sangenjaya', 6, '初期広告・マーケティング費', '50〜100万円'),
('sangenjaya', 7, '合計（概算）', '443〜723万円'),
('shimokitazawa', 1, '事務所賃借費用（初年度）', '160〜260万円'),
('shimokitazawa', 2, '内装工事費', '100〜180万円'),
('shimokitazawa', 3, '看板・サイン・備品', '25〜50万円'),
('shimokitazawa', 4, '弁済業務保証金分担金', '60万円'),
('shimokitazawa', 5, '免許申請費用', '約3.3万円'),
('shimokitazawa', 6, '初期広告・マーケティング費', '40〜80万円'),
('shimokitazawa', 7, '合計（概算）', '388〜633万円'),
('yoga', 1, '事務所賃借費用（初年度）', '140〜220万円'),
('yoga', 2, '内装工事費（高級感要）', '150〜300万円'),
('yoga', 3, '看板・サイン・備品', '30〜60万円'),
('yoga', 4, '弁済業務保証金分担金', '60万円'),
('yoga', 5, '免許申請費用', '約3.3万円'),
('yoga', 6, '初期広告・マーケティング費', '60〜120万円'),
('yoga', 7, '合計（概算）', '443〜763万円');

commit;

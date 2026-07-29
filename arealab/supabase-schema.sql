create table if not exists public.areas (
  key text primary key,
  name text not null,
  prefecture text,
  grade text check (grade in ('A', 'B', 'C')),
  lat double precision,
  lng double precision,
  annual_transactions integer,
  median_price integer,
  competitors integer,
  potential double precision,
  price_range text,
  main_property text,
  population text,
  avg_age text,
  color text,
  tagline text,
  ai_comment text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.competitors (
  id bigserial primary key,
  area_key text not null references public.areas(key) on delete cascade,
  name text not null,
  lat double precision,
  lng double precision,
  type text,
  address text,
  license_no text,
  license text,
  representative text,
  detail_url text,
  source text,
  created_at timestamptz not null default now()
);

create index if not exists competitors_area_key_idx
  on public.competitors(area_key);

create table if not exists public.simulations (
  id bigserial primary key,
  area_key text not null references public.areas(key) on delete cascade,
  sort_order integer not null default 0,
  label text not null,
  icon text,
  transactions integer,
  fee integer,
  revenue integer,
  cost integer,
  net integer,
  highlight boolean not null default false
);

create index if not exists simulations_area_key_sort_idx
  on public.simulations(area_key, sort_order);

create table if not exists public.costs (
  id bigserial primary key,
  area_key text not null references public.areas(key) on delete cascade,
  sort_order integer not null default 0,
  label text not null,
  value text not null
);

create index if not exists costs_area_key_sort_idx
  on public.costs(area_key, sort_order);

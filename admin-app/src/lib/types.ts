export type CampaignStatus =
  | "draft"
  | "scheduled"
  | "preparing"
  | "applying"
  | "verifying"
  | "active"
  | "reverting"
  | "verifying_revert"
  | "ended"
  | "paused";

export type TargetType = "products" | "categories" | "all_in_stock";
export type DiscountType = "percent" | "fixed";
export type SalePolicy = "best" | "override" | "keep";
export type RepeatMode = "none" | "daily" | "weekly";

export interface Stats {
  total: number;
  pending: number;
  applied: number;
  skipped: number;
  failed: number;
  reverted: number;
  kept: number;
  cancelled: number;
  last_batch?: { items: number; secs: number; mem_mb: number; why: string; next: number };
  stopped_manually?: boolean;
}

export interface LogEntry {
  t: number;
  code: string;
  ctx: Record<string, unknown>;
}

export interface Campaign {
  id: number;
  name: string;
  status: CampaignStatus;
  paused_status: string;
  discount_type: DiscountType;
  discount_value: number;
  target_type: TargetType;
  target: {
    product_ids: number[];
    category_ids: number[];
    exclude_product_ids: number[];
    exclude_category_ids: number[];
  };
  options: {
    only_in_stock: boolean;
    sale_policy: SalePolicy;
    round_step: number;
    min_price: number;
    include_children: boolean;
  };
  starts_at: number | null;
  ends_at: number | null;
  starts_local: string | null;
  ends_local: string | null;
  repeat_mode: RepeatMode;
  stats: Stats;
  log: LogEntry[];
  run_count: number;
  created_at: number;
  updated_at: number;
}

export interface Worker {
  heartbeat: number;
  now: number;
  busy: boolean;
  queued: boolean;
  has_work: boolean;
}

export interface Product {
  id: number;
  name: string;
  sku: string;
  type: string;
  stock: "instock" | "outofstock" | "onbackorder";
  regular: number | null;
  regular_max?: number | null;
  sale: number | null;
  image: string | null;
  categories: string[];
  variations: number;
  locked_by: number;
}

export interface Category {
  id: number;
  name: string;
  parent: number;
  depth: number;
  count: number;
}

export interface Preview {
  count: number;
  conflicts: number;
  samples: { id: number; name: string; regular: number; sale: number | null; new: number | null }[];
}

export interface Item {
  id: number;
  product_id: number;
  parent_id: number;
  name: string;
  status: string;
  orig_regular: string | null;
  orig_sale: string | null;
  new_sale: string | null;
  note: string;
  edit_url: string;
}

export interface SystemInfo {
  php_version: string;
  wc_version: string | null;
  memory_limit_mb: number;
  max_execution_time: number;
  cpu_count: number;
  load: number | null;
  load_ratio: number | null;
  wp_cron_disabled: boolean;
  action_scheduler: boolean;
  object_cache: boolean;
  profile: { name: string; max_seconds: number; max_batch: number };
  batch_size: number;
  ms_per_item: number;
  base_ms: number;
  pace: number;
  duty: number;
  headroom_mb: number;
  cooling: number;
  loopback_enabled: boolean;
  last_kick: number;
  caches: string[];
  cache_compat: boolean;
  last_purge: { t: number; reason: string; caches: string[] } | null;
  site_timezone: string;
  site_now: string;
  time_budget: number;
  history: {
    t: number;
    items: number;
    secs: number;
    work?: number;
    rest?: number;
    mem_mb: number;
    load: number | null;
    next: number;
    why: string;
    stress?: string;
    pace?: number;
    source?: string;
  }[];
  total_items: number;
  worker: Worker;
  last_skip: { t: number; reason: string; source?: string; note?: string } | null;
}

export interface Settings {
  profile: "auto" | "gentle" | "fast";
  pause_on_high_load: boolean;
  admin_runner: boolean;
  loopback: boolean;
  cache_compat: boolean;
  round_step: number;
  delete_data_on_uninstall: boolean;
}

/** What the wizard sends to the API. */
export interface CampaignInput {
  name: string;
  discount_type: DiscountType;
  discount_value: number;
  target_type: TargetType;
  target: Campaign["target"];
  options: Campaign["options"];
  starts_at: string | null; // "YYYY-MM-DD HH:mm" in the site timezone (Gregorian)
  ends_at: string | null;
  repeat_mode: RepeatMode;
  start?: boolean;
}

export const WORKING: CampaignStatus[] = ["preparing", "applying", "verifying", "reverting", "verifying_revert"];

/** Tiny REST client for /wp-json/dsd/v1 (cookie auth + wp_rest nonce). */

export interface Boot {
  restRoot: string;
  nonce: string;
  currency: string;
  decimals: number;
  timezone: string;
  now: number;
  nowLocal: string;
  productsUrl: string;
  version: string;
  /** wp-admin/admin-post.php (report downloads). */
  exportUrl?: string;
  exportNonce?: string;
}

declare global {
  interface Window {
    DSD_BOOT?: Boot;
  }
}

export const boot: Boot = window.DSD_BOOT ?? {
  restRoot: "/wp-json/dsd/v1/",
  nonce: "",
  currency: "تومان",
  decimals: 0,
  timezone: "Asia/Tehran",
  now: Math.floor(Date.now() / 1000),
  nowLocal: "",
  productsUrl: "#",
  version: "dev",
};

/** Download link for a campaign report (Excel file, or the print page that saves as PDF). */
export function exportUrl(id: number, format: "xlsx" | "pdf", status = ""): string {
  const url = new URL(boot.exportUrl || "/wp-admin/admin-post.php", window.location.origin);
  url.searchParams.set("action", "dsd_export");
  url.searchParams.set("id", String(id));
  url.searchParams.set("format", format);
  if (status) url.searchParams.set("status", status);
  url.searchParams.set("_wpnonce", boot.exportNonce ?? "");
  return url.toString();
}

export class ApiError extends Error {
  code: string;
  status: number;
  constructor(message: string, code: string, status: number) {
    super(message);
    this.code = code;
    this.status = status;
  }
}

export async function api<T>(path: string, init: { method?: string; body?: unknown; query?: Record<string, unknown> } = {}): Promise<T> {
  // restRoot may be "/wp-json/dsd/v1/" or "/?rest_route=/dsd/v1/" — handle both.
  const url = new URL(boot.restRoot + path.replace(/^\//, ""), window.location.origin);
  for (const [k, v] of Object.entries(init.query ?? {})) {
    if (v === undefined || v === null || v === "") continue;
    if (Array.isArray(v)) v.forEach((x) => url.searchParams.append(`${k}[]`, String(x)));
    else url.searchParams.set(k, String(v));
  }
  let res: Response;
  try {
    res = await fetch(url.toString(), {
      method: init.method ?? "GET",
      credentials: "same-origin",
      headers: {
        "X-WP-Nonce": boot.nonce,
        ...(init.body !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
    });
  } catch {
    throw new ApiError("ارتباط با سایت برقرار نشد. اینترنت یا سرور را بررسی کنید.", "network", 0);
  }
  let data: unknown = null;
  try {
    data = await res.json();
  } catch {
    /* empty body */
  }
  if (!res.ok) {
    const d = (data ?? {}) as { message?: string; code?: string };
    const msg =
      res.status === 403 || d.code === "rest_cookie_invalid_nonce"
        ? "نشست شما منقضی شده؛ صفحه را دوباره بارگذاری کنید."
        : d.message || `خطای سرور (${res.status})`;
    throw new ApiError(msg, d.code ?? "http", res.status);
  }
  return data as T;
}

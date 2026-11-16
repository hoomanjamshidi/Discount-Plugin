import { useCallback, useEffect, useMemo, useState } from "react";
import type { Product } from "./types";

/**
 * The "cart" of selected products. It lives above the search UI, so new searches,
 * pages and category filters never lose the selection. It is also saved in
 * localStorage so a refresh does not throw away a long selection.
 */
export type BasketItem = Pick<Product, "id" | "name" | "sku" | "image" | "regular" | "variations" | "stock">;

const KEY = "dsd-basket-v1";

function load(): BasketItem[] {
  try {
    const raw = window.localStorage.getItem(KEY);
    const data = raw ? (JSON.parse(raw) as BasketItem[]) : [];
    return Array.isArray(data) ? data : [];
  } catch {
    return [];
  }
}

export function useBasket(initialIds: number[] = []) {
  const [items, setItems] = useState<BasketItem[]>(() => {
    const saved = load();
    if (initialIds.length === 0) return saved;
    // Editing an existing campaign: start from its products (names are filled in later).
    return initialIds.map((id) => saved.find((s) => s.id === id) ?? { id, name: `#${id}`, sku: "", image: null, regular: null, variations: 0, stock: "instock" });
  });

  useEffect(() => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(items));
    } catch {
      /* private mode */
    }
  }, [items]);

  const ids = useMemo(() => new Set(items.map((i) => i.id)), [items]);

  const toggle = useCallback((p: BasketItem) => {
    setItems((prev) => (prev.some((x) => x.id === p.id) ? prev.filter((x) => x.id !== p.id) : [...prev, pick(p)]));
  }, []);

  const addMany = useCallback((list: BasketItem[]) => {
    setItems((prev) => {
      const have = new Set(prev.map((x) => x.id));
      return [...prev, ...list.filter((p) => !have.has(p.id)).map(pick)];
    });
  }, []);

  const removeMany = useCallback((list: number[]) => {
    const drop = new Set(list);
    setItems((prev) => prev.filter((x) => !drop.has(x.id)));
  }, []);

  const hydrate = useCallback((list: BasketItem[]) => {
    const byId = new Map(list.map((p) => [p.id, p]));
    setItems((prev) => prev.map((x) => (byId.has(x.id) ? pick(byId.get(x.id)!) : x)));
  }, []);

  const clear = useCallback(() => setItems([]), []);

  return { items, ids, toggle, addMany, removeMany, hydrate, clear };
}

function pick(p: BasketItem): BasketItem {
  return { id: p.id, name: p.name, sku: p.sku, image: p.image, regular: p.regular, variations: p.variations, stock: p.stock };
}

export type Basket = ReturnType<typeof useBasket>;

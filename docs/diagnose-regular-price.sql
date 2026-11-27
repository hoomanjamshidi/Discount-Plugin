-- Smart Bulk Discount — read-only check: did any product's REGULAR price end up equal to the
-- discount price of the last finished campaign? (Changes nothing. Change the "wp_" prefix if
-- the site uses another one.) See docs/regular-price-investigation.md for how to read it.
--
--   problem = REGULAR_EQUALS_DISCOUNT        the regular price was overwritten with our discount
--                                            price (by something else; orig_regular is the true one)
--   problem = ACTIVE_PRICE_STUCK_AT_DISCOUNT no sale price, but the price customers pay is still ours
--   problem = SALE_PRICE_STILL_DISCOUNT      the product still carries our sale price
SELECT
  i.campaign_id,
  i.product_id,
  p.post_title,
  i.status            AS item_status,
  i.note              AS item_note,
  i.orig_regular,
  i.orig_sale,
  i.new_sale,
  rp.meta_value       AS regular_now,
  sp.meta_value       AS sale_now,
  pr.meta_value       AS price_now,
  p.post_modified_gmt AS product_modified_utc,
  i.updated_at        AS item_updated_utc,
  CASE
    WHEN ABS(rp.meta_value - i.new_sale) < 0.001 AND ABS(rp.meta_value - i.orig_regular) >= 0.001
      THEN 'REGULAR_EQUALS_DISCOUNT'
    WHEN (sp.meta_value IS NULL OR sp.meta_value = '') AND ABS(pr.meta_value - i.new_sale) < 0.001
      THEN 'ACTIVE_PRICE_STUCK_AT_DISCOUNT'
    WHEN ABS(sp.meta_value - i.new_sale) < 0.001
      THEN 'SALE_PRICE_STILL_DISCOUNT'
  END AS problem
FROM wp_dsd_items i
JOIN wp_posts p ON p.ID = i.product_id
LEFT JOIN wp_postmeta rp ON rp.post_id = i.product_id AND rp.meta_key = '_regular_price'
LEFT JOIN wp_postmeta sp ON sp.post_id = i.product_id AND sp.meta_key = '_sale_price'
LEFT JOIN wp_postmeta pr ON pr.post_id = i.product_id AND pr.meta_key = '_price'
WHERE i.campaign_id = (SELECT MAX(id) FROM wp_dsd_campaigns WHERE status = 'ended')
  AND i.has_backup = 1
HAVING problem IS NOT NULL
ORDER BY problem, i.product_id;

-- Update the customer service catalog to match Pia's Laundry Shop banner.
-- Existing ticket line items retain their saved names, quantities and prices.

ALTER TABLE services
  MODIFY unit ENUM('kg', 'piece', 'load') NOT NULL DEFAULT 'kg';

UPDATE services
SET name = 'Wash', category = 'Wash', unit = 'load', price = 85.00,
    description = 'Up to 7 kilos per load', is_active = 1
WHERE name IN ('Wash', 'Wash & Fold');

UPDATE services
SET name = 'Dry', category = 'Dry', unit = 'load', price = 85.00,
    description = 'Up to 7 kilos per load', is_active = 1
WHERE name IN ('Dry', 'Dry Cleaning');

UPDATE services
SET name = 'Full Service', category = 'Full Service', unit = 'load', price = 199.00,
    description = 'Wash, dry and fold per load', is_active = 1
WHERE name IN ('Full Service', 'Wash, Dry & Fold');

UPDATE services
SET is_active = 0
WHERE name IN ('Bed Sheet', 'Towel', 'Ironing / Press', 'Comforter / Blanket', 'Curtain');

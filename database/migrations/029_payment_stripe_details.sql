-- Detail platby ze Stripe: metoda, stav, poplatek a výplata.
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS stripe_details MEDIUMTEXT DEFAULT NULL AFTER charged_amount;

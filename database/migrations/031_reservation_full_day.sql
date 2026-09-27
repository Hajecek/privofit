-- Delší rezervace: strop 180 min bránil vybrat víc než tři okénka.
UPDATE app_settings
SET setting_value = '1440'
WHERE setting_key = 'reservation.max_minutes'
  AND setting_value IN ('180', 'null', '');

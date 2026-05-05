DELETE FROM prescription_modifications;
DELETE FROM customer_examinations;
DELETE FROM customer_orders;
DELETE FROM custom_frames;

ALTER TABLE prescription_modifications AUTO_INCREMENT = 1;
ALTER TABLE customer_examinations AUTO_INCREMENT = 1;
ALTER TABLE customer_orders AUTO_INCREMENT = 1;
ALTER TABLE custom_frames AUTO_INCREMENT = 1;
DELETE FROM prescription_modifications;
DELETE FROM customer_examinations;
DELETE FROM customer_orders;
DELETE FROM custom_frames;

ALTER TABLE prescription_modifications AUTO_INCREMENT = 1;
ALTER TABLE customer_examinations AUTO_INCREMENT = 1;
ALTER TABLE customer_orders AUTO_INCREMENT = 1;
ALTER TABLE custom_frames AUTO_INCREMENT = 1;

1 = Order Received (Processing)

2=Manufacturing in Progress

3=Out for Delivery / Shipping

4=Completed / Awaiting Collection

5=Finish








kode invoice yang sekarang ini kan di akses setelah melalui customer_presciption, dengan kata lain ukuran resep lensanya itu didapat dari customer_prescription, nah sekarang saya mau invoice.php itu bisa diakses langsung (tidak melalui customer_prescription) yang maknanya: 1 customer hanya membeli frame 2 membeli lengkap namun untuk resep ukuran itu berasal dari customer sendiri, nah bagaimana untuk membedakan di database resep ukuran itu dari customer_prescription atau dari customer, yakni dari customer_number, misal 2/LZ-C/16.31/002/V/26, 002 itu urutan dari customer prescription yang saya periksa, jadi untuk resep yang digunakan berasal dari customer maka kodenya menjadi 000, yakni 2/LZ-C/16.31/000/V/26

begitu juga untuk nama alamat dan nomor telfon tersedia ketika dalam kondisi ini (karena nama diinputkan pada customer_prescription )

apa yang anda butuhkan?



dan pastikan tidak menggangu yang lain sama sekali dan dalam bahasa inggris
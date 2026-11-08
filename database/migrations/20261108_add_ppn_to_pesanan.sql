ALTER TABLE pesanan
  ADD COLUMN ppn_nominal INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'PPN 11% dari subtotal, masuk ke admin' AFTER potongan_voucher,
  DROP CONSTRAINT chk_pesanan_total,
  ADD CONSTRAINT chk_pesanan_total
    CHECK (total_bayar + potongan_mahasiswa + potongan_voucher = subtotal + ppn_nominal);

UPDATE pesanan
SET ppn_nominal = ROUND(harga_satuan * 11 / 100) * jumlah,
    total_bayar = subtotal + ROUND(harga_satuan * 11 / 100) * jumlah
      - potongan_mahasiswa - potongan_voucher
WHERE status = 'menunggu_pembayaran' AND batas_bayar >= NOW();

INSERT INTO pengaturan (kunci, nilai, keterangan)
VALUES ('pajak_persen', '11', 'PPN tetap 11% dari harga jual, dibayar pembeli dan dicatat untuk admin')
ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), keterangan = VALUES(keterangan);
